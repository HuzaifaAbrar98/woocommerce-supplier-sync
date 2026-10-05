<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WSS_Sync_Engine {

    public static function init() {
        add_action( 'wss_daily_sync_event', array( __CLASS__, 'scheduled_run' ) );
        add_action( 'wss_resume_sync_event', array( __CLASS__, 'resumed_run' ) );
    }

    public static function next_scheduled_timestamp() {
        $timezone = wp_timezone();
        $now = new DateTimeImmutable( 'now', $timezone );
        $next = $now->setTime( 8, 30, 0 );

        if ( $next <= $now ) {
            $next = $next->modify( '+1 day' );
        }

        return $next->getTimestamp();
    }

    public static function scheduled_run() {
        self::run();
        wp_schedule_single_event( self::next_scheduled_timestamp(), 'wss_daily_sync_event' );
    }

    public static function resumed_run() {
        self::run( true );
    }

    public static function get_settings() {
        return get_option( 'wss_settings', array() );
    }

    public static function test_connection() {
        $settings = self::get_settings();
        $validation = self::validate_settings( $settings );

        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        if ( ! function_exists( 'ftp_connect' ) ) {
            return new WP_Error( 'wss_no_ftp_extension', 'PHP FTP extension is not enabled on this server. Ask your host to enable the php-ftp / ext-ftp module, or check phpinfo() for "FTP support".' );
        }

        // Step 1: raw TCP reachability check, independent of the FTP extension.
        // This tells us whether the problem is network/DNS/firewall (before any
        // FTP protocol handshake) or something specific to the FTP login/handshake.
        $host = trim( (string) $settings['host'] );
        $host = preg_replace( '#^[a-z]+://#i', '', $host );
        $host = strtok( $host, '/' );
        $port = ! empty( $settings['port'] ) ? absint( $settings['port'] ) : 21;

        $socket_errno = 0;
        $socket_errstr = '';
        $socket = @fsockopen( $host, $port, $socket_errno, $socket_errstr, 10 );

        if ( ! $socket ) {
            $resolved = gethostbyname( $host );
            if ( $resolved === $host && ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
                return new WP_Error(
                    'wss_dns_failed',
                    sprintf(
                        'Could not resolve FTP hostname "%s" from the web server. Check for typos, or ask your host whether outbound DNS is restricted.',
                        $host
                    )
                );
            }

            return new WP_Error(
                'wss_tcp_unreachable',
                sprintf(
                    'Could not open a TCP connection to %1$s:%2$d from the web server (%3$s). This points to the hosting server\'s outbound firewall blocking port %2$d, or the supplier\'s firewall only allowing your local/office IP address. FileZilla working from your computer does not rule this out, since the two machines have different IP addresses and different firewall rules. Ask your host to run "nc -vz %1$s %2$d" from the server, and ask the supplier to confirm the server\'s outbound IP is allow-listed.',
                    $host,
                    $port,
                    trim( $socket_errstr . ( $socket_errno ? " (errno {$socket_errno})" : '' ) )
                )
            );
        }
        fclose( $socket );

        // Step 2: the actual FTP handshake, with PHP warnings captured so the
        // real underlying reason (rather than a generic message) reaches the log.
        $captured_warning = '';
        set_error_handler(
            function ( $errno, $errstr ) use ( &$captured_warning ) {
                $captured_warning = $errstr;
                return true;
            }
        );

        $connection = self::connect( $settings, $captured_warning );

        restore_error_handler();

        if ( is_wp_error( $connection ) ) {
            return $connection;
        }

        ftp_close( $connection );
        return true;
    }

    const BATCH_SIZE = 20000; // CSV data rows per batch
    const LOCK_KEY = 'wss_sync_running';
    const LOCK_TTL = 25 * MINUTE_IN_SECONDS; // self-heals if a run dies without releasing the lock
    const CHECKPOINT_KEY = 'wss_sync_checkpoint';
    const RESUME_RETRY_DELAY = 3 * MINUTE_IN_SECONDS;

    public static function run( $resume = false, $use_existing_local_csv = false ) {
        // Prevent two sync jobs (scheduled + manual, or two overlapping
        // scheduled runs) from processing the same products at once.
        if ( get_transient( self::LOCK_KEY ) ) {
            return new WP_Error( 'wss_already_running', 'A sync is already in progress. Skipping this run to avoid duplicate/overlapping updates.' );
        }
        set_transient( self::LOCK_KEY, time(), self::LOCK_TTL );

        // A 222MB file and up to tens of thousands of product saves can
        // exceed default PHP/web-server execution limits; this run is meant
        // to be triggered via WP-CLI/server cron where this actually applies.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }
        ignore_user_abort( true );

        // Detects the case a try/finally block cannot catch: the host hard-kills
        // this PHP process (timeout, OOM) partway through. In that case execution
        // just stops dead — no exception, no finally, the lock transient is left
        // set until it expires on its own. This shutdown function still runs in
        // that case (PHP always calls registered shutdown functions, even after
        // a fatal error), so it's the only reliable place to notice the death and
        // automatically schedule a resume attempt a few minutes later.
        register_shutdown_function( function () use ( $resume ) {
            $checkpoint = get_option( self::CHECKPOINT_KEY, array() );
            $died_mid_run = get_transient( self::LOCK_KEY )
                && ! empty( $checkpoint )
                && 'incomplete' === ( $checkpoint['status'] ?? '' );

            if ( $died_mid_run ) {
                delete_transient( self::LOCK_KEY );
                if ( ! wp_next_scheduled( 'wss_resume_sync_event' ) ) {
                    wp_schedule_single_event( time() + self::RESUME_RETRY_DELAY, 'wss_resume_sync_event' );
                }
                self::notify_failure( new WP_Error(
                    'wss_process_killed',
                    'The sync process stopped unexpectedly (likely a server timeout or memory limit) after batch ' .
                    ( $checkpoint['batch_number'] ?? '?' ) . '. It will automatically resume from where it left off in about ' .
                    ( self::RESUME_RETRY_DELAY / 60 ) . ' minutes.'
                ) );
            }
        } );

        try {
            $started = current_time( 'mysql' );
            $settings = self::get_settings();
            $validation = self::validate_settings( $settings );

            if ( is_wp_error( $validation ) ) {
                self::notify_failure( $validation );
                return $validation;
            }

            $checkpoint = get_option( self::CHECKPOINT_KEY, array() );
            $has_incomplete = ! empty( $checkpoint ) && 'incomplete' === ( $checkpoint['status'] ?? '' ) && ! empty( $checkpoint['file'] ) && file_exists( $checkpoint['file'] );

            if ( $resume || $has_incomplete ) {
                if ( ! $has_incomplete ) {
                    return new WP_Error( 'wss_nothing_to_resume', 'No incomplete sync was found to resume; the downloaded CSV may have been removed since it stopped.' );
                }
                // Resuming: reuse the exact file already on disk. Do NOT
                // re-download — that would overwrite the very file we need
                // to pick back up mid-way through.
                $local_file = $checkpoint['file'];
                $excluded_skus = ! empty( $checkpoint['excluded_skus'] ) && is_array( $checkpoint['excluded_skus'] )
                    ? array_fill_keys( array_map( array( __CLASS__, 'normalize_sku' ), $checkpoint['excluded_skus'] ), true )
                    : array();
                $result = self::sync_csv( $local_file, $checkpoint, $excluded_skus );
            } else {
                $local_file = self::local_file_path( $settings );

                if ( is_wp_error( $local_file ) ) {
                    self::notify_failure( $local_file );
                    return $local_file;
                }

                if ( $use_existing_local_csv ) {
                    // Testing/fallback path: skip FTP entirely and process
                    // whatever CSV is already sitting at the configured local
                    // path (e.g. uploaded manually via SFTP/File Manager while
                    // diagnosing an FTP connectivity problem).
                    if ( ! file_exists( $local_file ) ) {
                        $error = new WP_Error( 'wss_local_csv_missing', 'No local CSV found at: ' . $local_file . '. Upload one to that exact path first (matching the filename supplier-latest.csv), or use "Download & Run Sync" once FTP is working.' );
                        self::notify_failure( $error );
                        return $error;
                    }
                    if ( ! is_readable( $local_file ) || 0 === filesize( $local_file ) ) {
                        $error = new WP_Error( 'wss_local_csv_invalid', 'The local CSV file exists but is empty or not readable: ' . $local_file );
                        self::notify_failure( $error );
                        return $error;
                    }
                } else {
                    $download = self::download_file( $settings, $local_file, $settings['remote_path'] );

                    if ( is_wp_error( $download ) ) {
                        self::notify_failure( $download );
                        return $download;
                    }
                }

                // Optional second FTP CSV: a small list of SKUs that must be
                // excluded from updates even when they also appear in the main
                // supplier CSV. The exclusion list is loaded once per sync and
                // kept in the checkpoint so a resumed run uses the exact same
                // rules as the original run.
                $excluded_skus = array();
                $exclude_local_file = self::exclusion_local_file_path( $settings, $local_file );

                if ( $use_existing_local_csv ) {
                    // In local-upload mode, the Skip-SKU file is optional and is
                    // detected by its standard local filename. This means the
                    // user does not need to configure an FTP exclusion path just
                    // to use a dashboard-uploaded exclusion CSV.
                    if ( file_exists( $exclude_local_file ) ) {
                        $excluded_skus = self::load_excluded_skus( $exclude_local_file );
                        if ( is_wp_error( $excluded_skus ) ) {
                            self::notify_failure( $excluded_skus );
                            return $excluded_skus;
                        }
                    } elseif ( ! empty( $settings['exclude_remote_path'] ) ) {
                        $error = new WP_Error( 'wss_exclusion_csv_missing', 'The Skip-SKU CSV is enabled for FTP, but no local exclusion file was found at: ' . $exclude_local_file . '. Upload it from the dashboard or clear the Skip-SKU CSV Remote Path setting.' );
                        self::notify_failure( $error );
                        return $error;
                    }
                } elseif ( file_exists( $exclude_local_file ) && is_readable( $exclude_local_file ) && filesize( $exclude_local_file ) > 0 ) {
                    // Mixed mode: the main supplier CSV can come from FTP while
                    // the Skip-SKU CSV was uploaded through the dashboard. A
                    // locally uploaded exclusion file takes precedence so the
                    // FTP run does not overwrite or ignore the dashboard file.
                    $excluded_skus = self::load_excluded_skus( $exclude_local_file );
                    if ( is_wp_error( $excluded_skus ) ) {
                        self::notify_failure( $excluded_skus );
                        return $excluded_skus;
                    }
                } elseif ( ! empty( $settings['exclude_remote_path'] ) ) {
                    $exclude_download = self::download_file( $settings, $exclude_local_file, $settings['exclude_remote_path'] );
                    if ( is_wp_error( $exclude_download ) ) {
                        self::notify_failure( $exclude_download );
                        return $exclude_download;
                    }

                    $excluded_skus = self::load_excluded_skus( $exclude_local_file );
                    if ( is_wp_error( $excluded_skus ) ) {
                        self::notify_failure( $excluded_skus );
                        return $excluded_skus;
                    }
                }

                $result = self::sync_csv( $local_file, null, $excluded_skus );
            }

            if ( is_wp_error( $result ) ) {
                self::notify_failure( $result );
                return $result;
            }

            $result['started'] = $started;
            $result['completed'] = current_time( 'mysql' );
            $result['file'] = $local_file;

            update_option( 'wss_last_sync', $result, false );

            // Optional: remove the downloaded CSV once processing succeeds,
            // so supplier pricing data doesn't sit on disk indefinitely.
            if ( ! empty( $settings['delete_after_sync'] ) && file_exists( $local_file ) ) {
                @unlink( $local_file );
            }
            // Do not delete a dashboard-uploaded Skip-SKU CSV just because the
            // main supplier file came from FTP. Only an exclusion file that was
            // actually downloaded from FTP during this run should be auto-deleted.
            if ( ! empty( $settings['delete_after_sync'] ) && ! empty( $settings['exclude_remote_path'] ) ) {
                $exclude_local_file = self::exclusion_local_file_path( $settings, $local_file );
                // If the file existed before this run it may have been a dashboard
                // upload and must be preserved. The FTP branch above only downloads
                // when no usable local file exists, so a fresh FTP file is safe to remove
                // only when it has not been supplied locally.
                // Keep local files by default; users can replace/remove them from the dashboard.
            }

            return $result;
        } finally {
            delete_transient( self::LOCK_KEY );
        }
    }

    /**
     * Pushes the "sync running" lock's expiry forward. The lock otherwise
     * expires LOCK_TTL after the run started, and a long first run (file pass
     * plus zeroing) can outlast that, which would let a second sync start on
     * top of this one. Called regularly while work is progressing; if the
     * process dies, refreshing stops and the lock still expires on its own.
     */
    private static function refresh_lock() {
        set_transient( self::LOCK_KEY, time(), self::LOCK_TTL );
    }

    private static function notify_failure( $error ) {
        $admin_email = get_option( 'admin_email' );
        if ( ! $admin_email ) {
            return;
        }
        wp_mail(
            $admin_email,
            '[' . get_bloginfo( 'name' ) . '] Supplier Sync failed',
            'The supplier price/stock sync failed: ' . $error->get_error_message() .
            "\n\nCheck Supplier Sync \xe2\x86\x92 Dashboard for details."
        );
    }

    private static function validate_settings( $settings ) {
        $required = array( 'host', 'username', 'password', 'remote_path', 'local_path' );

        foreach ( $required as $key ) {
            if ( empty( $settings[ $key ] ) ) {
                return new WP_Error( 'wss_missing_setting', 'Missing setting: ' . $key );
            }
        }

        if ( 'sftp' === ( $settings['connection_type'] ?? 'ftp' ) ) {
            return new WP_Error( 'wss_sftp_unsupported', 'SFTP is not supported by native PHP FTP. Use FTP/FTPS or request SSH/SFTP integration.' );
        }

        return true;
    }

    private static function connect( $settings, &$captured_warning = '' ) {
        $host = $settings['host'];
        $host = trim( (string) $host );
        $host = preg_replace( '#^[a-z]+://#i', '', $host );
        $host = strtok( $host, '/' );
        $port = ! empty( $settings['port'] ) ? absint( $settings['port'] ) : 21;
        $timeout = 30;
        $use_ftps = 'ftps' === ( $settings['connection_type'] ?? 'ftp' );

        if ( $use_ftps && ! function_exists( 'ftp_ssl_connect' ) ) {
            return new WP_Error( 'wss_no_ftps_support', 'FTPS was selected, but this PHP build does not support ftp_ssl_connect() (PHP FTP compiled without OpenSSL). Switch to plain FTP, or ask your host for a PHP build with FTPS support.' );
        }

        if ( $use_ftps ) {
            $connection = @ftp_ssl_connect( $host, $port, $timeout );
        } else {
            $connection = @ftp_connect( $host, $port, $timeout );
        }

        if ( ! $connection ) {
            $detail = $captured_warning ? ' Server said: ' . $captured_warning : '';
            return new WP_Error( 'wss_ftp_connect_failed', 'The FTP handshake to ' . $host . ':' . $port . ' failed after the TCP socket opened.' . $detail . ' This usually means the wrong connection type is selected (plain FTP vs FTPS) for what the supplier requires.' );
        }

        if ( ! @ftp_login( $connection, $settings['username'], WSS_Admin::decrypt_secret( $settings['password'] ) ) ) {
            ftp_close( $connection );
            $detail = $captured_warning ? ' Server said: ' . $captured_warning : '';
            return new WP_Error( 'wss_ftp_login_failed', 'Connected to the FTP server, but the login was rejected.' . $detail . ' Re-check the username and password saved in Supplier Sync → Settings (re-enter the password, since it is not shown once saved).' );
        }

        // Passive mode is required on almost all shared hosting / VPS setups,
        // because active mode needs the server to open an inbound connection
        // back to the client, which hosting firewalls block. If this fails we
        // do not hard-fail the connection test itself (login already
        // succeeded), but we do surface it, since it is the most common cause
        // of the *download* failing later even though "Test Connection" passes.
        if ( ! @ftp_pasv( $connection, true ) ) {
            $detail = $captured_warning ? ' Server said: ' . $captured_warning : '';
            error_log( '[WSS Supplier Sync] Warning: could not enable FTP passive mode.' . $detail );
        }

        return $connection;
    }

    private static function local_file_path( $settings ) {
        $directory = trailingslashit( $settings['local_path'] );

        if ( ! wp_mkdir_p( $directory ) ) {
            return new WP_Error(
                'wss_local_dir_failed',
                'Could not create the local CSV directory: ' . $directory .
                '. Check that this is a valid absolute server filesystem path (not a web URL) and that the parent folder is writable by PHP.'
            );
        }

        if ( ! is_writable( $directory ) ) {
            return new WP_Error( 'wss_local_not_writable', 'The local CSV directory exists but is not writable by PHP: ' . $directory );
        }

        // Keep the CSV (which contains supplier pricing) out of direct web
        // access, since wp-content/uploads is otherwise publicly served.
        $htaccess = $directory . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            @file_put_contents( $htaccess, "Require all denied\n" );
        }
        $index = $directory . 'index.php';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, "<?php\n// Silence is golden.\n" );
        }

        return $directory . 'supplier-latest.csv';
    }

    private static function download_file( $settings, $local_file, $remote_path = null ) {
        $remote_path = null !== $remote_path ? (string) $remote_path : (string) $settings['remote_path'];
        $temporary_file = $local_file . '.part';

        // Prefer curl for the actual file transfer: PHP's native ftp_get()
        // has no way to work around an extremely common hosting scenario
        // where the FTP server sits behind NAT and replies to the passive-mode
        // request with its own private/internal IP address (e.g. 192.168.x.x
        // or 10.x.x.x) instead of its real public one. The control connection
        // (login, "Test Connection") works fine either way, but the actual
        // data transfer then tries to connect to an unreachable private IP
        // and fails silently. Curl's CURLOPT_FTP_SKIP_PASV_IP tells it to
        // ignore that bad IP and reuse the address already used for the
        // control connection instead, which is the standard fix for this.
        if ( function_exists( 'curl_init' ) ) {
            $result = self::download_via_curl( $settings, $temporary_file, $remote_path );
        } else {
            $result = self::download_via_native_ftp( $settings, $temporary_file, $remote_path );
        }

        if ( is_wp_error( $result ) ) {
            if ( file_exists( $temporary_file ) ) {
                @unlink( $temporary_file );
            }
            return $result;
        }

        if ( file_exists( $local_file ) ) {
            @unlink( $local_file );
        }

        if ( ! @rename( $temporary_file, $local_file ) ) {
            @unlink( $temporary_file );
            return new WP_Error( 'wss_rename_failed', 'Downloaded file could not be finalized.' );
        }

        return true;
    }

    private static function download_via_curl( $settings, $temporary_file, $remote_path ) {
        $host = trim( (string) $settings['host'] );
        $host = preg_replace( '#^[a-z]+://#i', '', $host );
        $host = strtok( $host, '/' );
        $port = ! empty( $settings['port'] ) ? absint( $settings['port'] ) : 21;
        $use_ftps = 'ftps' === ( $settings['connection_type'] ?? 'ftp' );
        $scheme = $use_ftps ? 'ftps' : 'ftp';
        $remote_path = ltrim( (string) $remote_path, '/' );

        $url = sprintf( '%s://%s:%d/%s', $scheme, $host, $port, $remote_path );

        $fp = @fopen( $temporary_file, 'wb' );
        if ( ! $fp ) {
            return new WP_Error( 'wss_local_write_failed', 'Could not open a local file to write the download to: ' . $temporary_file );
        }

        $curl_log = fopen( 'php://temp', 'w+' );

        $ch = curl_init();
        curl_setopt_array( $ch, array(
            CURLOPT_URL => $url,
            CURLOPT_USERPWD => $settings['username'] . ':' . WSS_Admin::decrypt_secret( $settings['password'] ),
            CURLOPT_FILE => $fp,
            CURLOPT_UPLOAD => false,
            CURLOPT_FTP_USE_EPSV => true,
            // The key fix: ignore the (often private/unreachable) IP address
            // the FTP server proposes for the passive data connection, and
            // reuse the address of the already-working control connection.
            CURLOPT_FTP_SKIP_PASV_IP => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 0, // no cap; a 222MB transfer may take a while
            CURLOPT_FTPSSLAUTH => $use_ftps ? CURLFTPAUTH_TLS : CURLFTPAUTH_DEFAULT,
            CURLOPT_USE_SSL => $use_ftps ? CURLUSESSL_ALL : CURLUSESSL_NONE,
            CURLOPT_VERBOSE => true,
            CURLOPT_STDERR => $curl_log,
        ) );

        $success = curl_exec( $ch );
        $errno = curl_errno( $ch );
        $error = curl_error( $ch );
        $http_ftp_code = curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
        curl_close( $ch );
        fclose( $fp );

        if ( ! $success || $errno ) {
            rewind( $curl_log );
            $verbose_log = stream_get_contents( $curl_log );
            fclose( $curl_log );

            return new WP_Error(
                'wss_curl_download_failed',
                sprintf(
                    'FTP download failed (curl error %d: %s, FTP response code %s). Remote path requested: "%s". Recent transfer log: %s',
                    $errno,
                    $error,
                    $http_ftp_code ?: 'none',
                    $remote_path,
                    self::trim_log( $verbose_log )
                )
            );
        }
        fclose( $curl_log );

        if ( ! file_exists( $temporary_file ) || filesize( $temporary_file ) < 1 ) {
            return new WP_Error( 'wss_download_empty', 'The FTP transfer reported success but produced an empty file. Double-check the remote path "' . $remote_path . '" is exact and case-sensitive.' );
        }

        return true;
    }

    private static function trim_log( $log ) {
        $log = trim( (string) $log );
        if ( strlen( $log ) > 800 ) {
            $log = '...' . substr( $log, -800 );
        }
        return str_replace( array( "\r\n", "\n" ), ' | ', $log );
    }

    private static function download_via_native_ftp( $settings, $temporary_file, $remote_path ) {
        $captured_warning = '';
        set_error_handler(
            function ( $errno, $errstr ) use ( &$captured_warning ) {
                $captured_warning = $errstr;
                return true;
            }
        );

        $connection = self::connect( $settings, $captured_warning );

        if ( is_wp_error( $connection ) ) {
            restore_error_handler();
            return $connection;
        }

        $downloaded = @ftp_get( $connection, $temporary_file, $remote_path, FTP_BINARY );
        ftp_close( $connection );
        restore_error_handler();

        if ( ! $downloaded || ! file_exists( $temporary_file ) || filesize( $temporary_file ) < 1 ) {
            $detail = $captured_warning ? ' Server said: ' . $captured_warning : '';
            return new WP_Error( 'wss_download_failed', 'Supplier CSV download of "' . $remote_path . '" failed or produced an empty file.' . $detail . ' Double-check the remote path is exact (case-sensitive, and confirm it is not actually in a subdirectory on the FTP account). Note: this server does not have curl available, which is the more NAT/passive-mode-tolerant transfer method — ask your host to enable the PHP curl extension if this keeps failing.' );
        }

        return true;
    }


    /**
     * Normalize SKU values for matching only. SKU matching is case-insensitive
     * while preserving meaningful characters such as hyphens and leading zeros.
     */
    private static function normalize_sku( $sku ) {
        return strtolower( trim( (string) $sku ) );
    }

    private static function exclusion_local_file_path( $settings, $main_local_file ) {
        $directory = trailingslashit( dirname( $main_local_file ) );
        return $directory . 'supplier-skip-skus.csv';
    }

    private static function load_excluded_skus( $file ) {
        $handle = @fopen( $file, 'rb' );
        if ( ! $handle ) {
            return new WP_Error( 'wss_exclusion_csv_open_failed', 'Could not open the Skip-SKU CSV: ' . $file );
        }

        $headers = fgetcsv( $handle );
        if ( false === $headers ) {
            fclose( $handle );
            return new WP_Error( 'wss_exclusion_csv_empty', 'The Skip-SKU CSV is empty: ' . $file );
        }

        $headers = array_map( 'trim', $headers );
        $sku_index = WSS_CSV_Processor::get_sku_index( $headers );
        if ( is_wp_error( $sku_index ) ) {
            fclose( $handle );
            return $sku_index;
        }

        $excluded = array();
        $row_count = 0;
        while ( false !== ( $row = fgetcsv( $handle ) ) ) {
            $sku = isset( $row[ $sku_index ] ) ? self::normalize_sku( $row[ $sku_index ] ) : '';
            if ( '' === $sku ) {
                continue;
            }
            $excluded[ $sku ] = true;
            $row_count++;
        }
        fclose( $handle );

        return $excluded;
    }

    private static function sync_csv( $file, $checkpoint = null, $excluded_skus = array() ) {
        global $wpdb;

        $handle = @fopen( $file, 'rb' );
        if ( ! $handle ) {
            return new WP_Error( 'wss_csv_open_failed', 'Could not open downloaded CSV.' );
        }

        $resuming = ! empty( $checkpoint ) && isset( $checkpoint['offset'], $checkpoint['indexes'] );

        if ( $resuming ) {
            $indexes = $checkpoint['indexes'];
            $totals = $checkpoint['totals'];
            if ( empty( $excluded_skus ) && ! empty( $checkpoint['excluded_skus'] ) && is_array( $checkpoint['excluded_skus'] ) ) {
                $excluded_skus = array_fill_keys( array_map( 'strval', $checkpoint['excluded_skus'] ), true );
            }
            $batches = get_option( 'wss_sync_batches', array() );
            $batch_number = (int) $checkpoint['batch_number'];
            // Whether this run has been recording matched products. Taken from
            // the checkpoint, NOT the current setting: zeroing is only safe if
            // matches were recorded from the very first batch of this run.
            $track_seen = ! empty( $checkpoint['track_seen'] );
            fseek( $handle, (int) $checkpoint['offset'] );
        } else {
            $run_settings = self::get_settings();
            $track_seen = ! empty( $run_settings['zero_unmatched'] );
            $headers = fgetcsv( $handle );
            if ( false === $headers ) {
                fclose( $handle );
                return new WP_Error( 'wss_csv_empty', 'Downloaded CSV is empty.' );
            }

            $headers = array_map( 'trim', $headers );
            $indexes = WSS_CSV_Processor::get_required_indexes( $headers );

            if ( is_wp_error( $indexes ) ) {
                fclose( $handle );
                return $indexes;
            }

            $batches = array();
            $batch_number = 0;
        }

        // One database query creates an in-memory SKU => product ID map.
        // This avoids calling wc_get_product_id_by_sku() for every CSV row.
        // Rebuilt fresh even on resume, in case a product was added/removed
        // between the original run and the resumed one.
        $sku_rows = $wpdb->get_results(
            "SELECT meta.meta_value AS sku, meta.post_id AS product_id
             FROM {$wpdb->postmeta} meta
             INNER JOIN {$wpdb->posts} posts ON posts.ID = meta.post_id
             WHERE meta.meta_key = '_sku'
             AND meta.meta_value <> ''
             AND posts.post_type IN ('product', 'product_variation')
             AND posts.post_status NOT IN ('trash', 'auto-draft')",
            ARRAY_A
        );

        if ( null === $sku_rows && $wpdb->last_error ) {
            // A real DB error (not just "zero rows") deserves to be surfaced
            // loudly rather than silently treated as an empty store — an
            // empty SKU map otherwise looks identical to "no products exist"
            // and would incorrectly mark every single CSV row as unknown.
            fclose( $handle );
            return new WP_Error( 'wss_sku_query_failed', 'Could not load existing product SKUs from the database: ' . $wpdb->last_error );
        }

        $sku_rows = is_array( $sku_rows ) ? $sku_rows : array();

        $sku_map = array();
        foreach ( $sku_rows as $sku_row ) {
            $sku_map[ self::normalize_sku( $sku_row['sku'] ) ] = absint( $sku_row['product_id'] );
        }
        unset( $sku_rows );

        if ( 0 === count( $sku_map ) ) {
            // Zero legitimate products found is almost certainly wrong for a
            // real store and worth stopping for, rather than proceeding to
            // mark 100% of rows "unknown" — a confusing, misleading result.
            fclose( $handle );
            return new WP_Error( 'wss_no_skus_found', 'Found 0 existing product SKUs in the database — refusing to continue, since that would mark every CSV row as unknown. If the store genuinely has no products with a SKU set, check WooCommerce → Products for at least one product with a SKU filled in.' );
        }

        if ( ! $resuming ) {
            $totals = self::empty_stats( count( $sku_map ) );
            // Reset the persisted batch log at the start of a fresh run, so the
            // dashboard doesn't show stale batches from a previous day mixed in.
            update_option( 'wss_sync_batches', array(), false );
        } else {
            $totals['existing_skus_loaded'] = count( $sku_map );
        }

        $seen_file = trailingslashit( dirname( $file ) ) . 'supplier-seen-ids.txt';
        if ( ! $resuming ) {
            if ( $track_seen ) {
                file_put_contents( $seen_file, '' ); // fresh, empty record for this run
            } elseif ( file_exists( $seen_file ) ) {
                @unlink( $seen_file );
            }
        }

        update_option( 'wss_sync_in_progress', array(
            'status' => 'running',
            'batch_number' => $batch_number,
            'totals' => $totals,
        ), false );

        while ( ! feof( $handle ) ) {
            $batch_number++;
            $batch_started = microtime( true );
            $batch_stats = self::empty_stats( 0 );
            $rows_in_batch = 0;
            $seen_ids = array();

            while ( $rows_in_batch < self::BATCH_SIZE && false !== ( $row = fgetcsv( $handle ) ) ) {
                $rows_in_batch++;
                self::process_row( $row, $indexes, $sku_map, $excluded_skus, $seen_ids, $batch_stats );
                if ( 0 === $rows_in_batch % 5000 ) {
                    self::refresh_lock();
                }
            }

            if ( 0 === $rows_in_batch ) {
                // Trailing blank line at end of file with nothing to process.
                $batch_number--; // this empty pass was not a real batch
                break;
            }

            // Append this batch's matched product IDs to disk BEFORE the
            // checkpoint below is written. If the process dies in between, the
            // batch is simply re-run on resume and the IDs are appended again
            // (harmless duplicates) — they can never be lost.
            if ( $track_seen && ! empty( $seen_ids ) ) {
                file_put_contents( $seen_file, implode( "\n", array_keys( $seen_ids ) ) . "\n", FILE_APPEND | LOCK_EX );
            }
            unset( $seen_ids );
            self::refresh_lock();

            $batch_stats['batch_number'] = $batch_number;
            $batch_stats['duration_seconds'] = round( microtime( true ) - $batch_started, 1 );

            foreach ( $batch_stats as $stat_key => $stat_value ) {
                if ( isset( $totals[ $stat_key ] ) && is_numeric( $stat_value ) && 'existing_skus_loaded' !== $stat_key ) {
                    $totals[ $stat_key ] += $stat_value;
                }
            }

            $batches[] = $batch_stats;

            // Persist after every batch (not just at the end) so the dashboard
            // shows real progress, and so a run that dies partway still leaves
            // a true record of exactly how far it got — AND a checkpoint that
            // lets the next run pick up exactly here rather than starting over.
            update_option( 'wss_sync_batches', $batches, false );
            update_option( 'wss_sync_in_progress', array(
                'status' => 'running',
                'batch_number' => $batch_number,
                'totals' => $totals,
            ), false );
            update_option( self::CHECKPOINT_KEY, array(
                'file' => $file,
                'offset' => ftell( $handle ),
                'batch_number' => $batch_number,
                'totals' => $totals,
                'indexes' => $indexes,
                'excluded_skus' => array_keys( $excluded_skus ),
                'track_seen' => $track_seen,
                'status' => 'incomplete',
            ), false );
        }

        $eof_offset = ftell( $handle );
        fclose( $handle );

        // Phase 2: every website SKU that was neither matched by the supplier
        // file nor listed in the Skip-SKU file is set to price 0 / stock 0.
        // This only ever runs after the WHOLE supplier file was read.
        self::zero_unmatched_products(
            $sku_map,
            $excluded_skus,
            $seen_file,
            $track_seen,
            array(
                'file' => $file,
                'offset' => $eof_offset,
                'indexes' => $indexes,
                'excluded_skus' => array_keys( $excluded_skus ),
                'track_seen' => $track_seen,
                'status' => 'incomplete',
            ),
            $totals,
            $batches,
            $batch_number
        );

        if ( file_exists( $seen_file ) ) {
            @unlink( $seen_file );
        }

        update_option( 'wss_sync_in_progress', array(
            'status' => 'complete',
            'batch_number' => $batch_number,
            'totals' => $totals,
        ), false );
        update_option( self::CHECKPOINT_KEY, array( 'status' => 'complete' ), false );

        $totals['batches'] = $batch_number;
        return $totals;
    }

    /**
     * Phase 2 of a sync: set price and stock to 0 for every website product
     * that was NOT matched by the supplier CSV and is NOT in the Skip-SKU CSV.
     *
     * Protected (never touched), in priority order:
     *   1. SKUs in the Skip-SKU file (managed manually) - even if they are not
     *      in the supplier file at all.
     *   2. SKUs found in the supplier file (updated or already identical).
     * Everything else with a SKU is zeroed. Products with no SKU at all are
     * never touched.
     *
     * Safety rules - zeroing is skipped (and you are emailed) when:
     *   - it was not enabled when this sync started (matches weren't recorded),
     *   - the record of matched products is missing,
     *   - not a single website product matched the supplier file,
     *   - the share of products that would be zeroed exceeds the configured limit.
     * "Preview only" mode reports what would happen without changing anything.
     */
    private static function zero_unmatched_products( $sku_map, $excluded_skus, $seen_file, $track_seen, $checkpoint_base, &$totals, &$batches, &$batch_number ) {
        $settings = self::get_settings();

        if ( ! $track_seen ) {
            if ( ! empty( $settings['zero_unmatched'] ) ) {
                $totals['zeroing_status'] = 'Not run: "Zero unmatched products" was switched on after this sync had already started, so matched products were not recorded. It will run on the next sync.';
            }
            return;
        }

        if ( ! file_exists( $seen_file ) ) {
            self::zeroing_skipped( $totals, 'Not run: the record of matched products is missing, so zeroing was skipped to avoid wiping products that did match the supplier file.' );
            return;
        }

        $protected = array();
        $lines = file( $seen_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        foreach ( (array) $lines as $line ) {
            $id = absint( $line );
            if ( $id ) {
                $protected[ $id ] = true;
            }
        }
        unset( $lines );
        $matched_count = count( $protected );

        if ( 0 === $matched_count ) {
            self::zeroing_skipped( $totals, 'Not run: not one website product matched the supplier file. That almost certainly means the wrong or an empty file was downloaded, and zeroing would have wiped the whole store.' );
            return;
        }

        // Skip-SKU products are protected even when absent from the supplier file.
        foreach ( $excluded_skus as $excluded_sku => $unused ) {
            if ( isset( $sku_map[ $excluded_sku ] ) ) {
                $protected[ $sku_map[ $excluded_sku ] ] = true;
            }
        }

        $candidates = array();
        foreach ( $sku_map as $product_id ) {
            if ( ! isset( $protected[ $product_id ] ) ) {
                $candidates[] = $product_id;
            }
        }

        $total_products = count( $sku_map );
        $percent = $total_products > 0 ? round( count( $candidates ) / $total_products * 100, 1 ) : 0;
        $max_percent = isset( $settings['zero_max_percent'] ) ? max( 1, min( 100, absint( $settings['zero_max_percent'] ) ) ) : 85;

        $totals['matched_products'] = $matched_count;
        $totals['zero_candidates'] = count( $candidates );

        if ( $percent > $max_percent ) {
            self::zeroing_skipped( $totals, sprintf(
                'Not run (safety limit): %1$s%% of website products (%2$s of %3$s) would be zeroed, which is above your %4$d%% limit. This usually means the supplier file is incomplete. Check the file, or raise the limit in Settings if this is expected.',
                $percent,
                number_format( count( $candidates ) ),
                number_format( $total_products ),
                $max_percent
            ) );
            return;
        }

        // Products already at price 0 / stock 0 are skipped without loading
        // them, so after the first run only newly-unmatched products cost time.
        $already_zero = self::already_zero_product_ids();
        $to_process = array();
        foreach ( $candidates as $product_id ) {
            if ( ! isset( $already_zero[ $product_id ] ) ) {
                $to_process[] = $product_id;
            }
        }
        $already_zero_count = count( $candidates ) - count( $to_process );
        $totals['already_zero'] = $already_zero_count;

        $dry_run = ! isset( $settings['zero_dry_run'] ) || ! empty( $settings['zero_dry_run'] );
        if ( $dry_run ) {
            $totals['zeroing_status'] = sprintf(
                'Preview only - nothing was changed. %1$s products would be set to price 0 / stock 0 (%2$s more are already 0, %3$s protected as matched/skipped). Untick "Preview only" in Settings to apply.',
                number_format( count( $to_process ) ),
                number_format( $already_zero_count ),
                number_format( count( $protected ) )
            );
            return;
        }

        $zeroed_total = 0;
        foreach ( array_chunk( $to_process, self::BATCH_SIZE ) as $chunk ) {
            $batch_number++;
            $batch_started = microtime( true );
            $batch_stats = self::empty_stats( 0 );
            $batch_stats['phase'] = 'zeroing';

            foreach ( $chunk as $product_id ) {
                $product = wc_get_product( $product_id );
                if ( ! $product ) {
                    $batch_stats['errors']++;
                    continue;
                }

                $product->set_regular_price( '0' );
                $product->set_manage_stock( true );
                $product->set_stock_quantity( 0 );
                // Backorders must be off, otherwise WooCommerce flips a zero-stock
                // product to "on backorder" and it stays purchasable at price 0.
                $product->set_backorders( 'no' );
                $product->set_stock_status( 'outofstock' );
                $product->save();

                $batch_stats['zeroed']++;
                unset( $product );

                if ( 0 === $batch_stats['zeroed'] % 1000 ) {
                    self::refresh_lock();
                }
            }
            self::refresh_lock();

            $batch_stats['batch_number'] = $batch_number;
            $batch_stats['duration_seconds'] = round( microtime( true ) - $batch_started, 1 );
            $zeroed_total += $batch_stats['zeroed'];

            $totals['zeroed'] = ( $totals['zeroed'] ?? 0 ) + $batch_stats['zeroed'];
            $totals['errors'] = ( $totals['errors'] ?? 0 ) + $batch_stats['errors'];

            $batches[] = $batch_stats;
            update_option( 'wss_sync_batches', $batches, false );
            update_option( 'wss_sync_in_progress', array(
                'status' => 'running',
                'batch_number' => $batch_number,
                'totals' => $totals,
            ), false );
            // Keep the checkpoint pointing at end-of-file. If the process dies
            // mid-zeroing, a resume skips straight back to this phase, and
            // products already zeroed are filtered out by the check above.
            update_option( self::CHECKPOINT_KEY, array_merge( $checkpoint_base, array(
                'batch_number' => $batch_number,
                'totals' => $totals,
            ) ), false );
        }

        $totals['zeroing_status'] = sprintf(
            'Completed - %1$s products set to price 0 / stock 0 (%2$s were already 0, %3$s protected as matched/skipped).',
            number_format( $zeroed_total ),
            number_format( $already_zero_count ),
            number_format( count( $protected ) )
        );
    }

    private static function zeroing_skipped( &$totals, $reason ) {
        $totals['zeroing_status'] = $reason;
        self::notify_failure( new WP_Error( 'wss_zeroing_skipped', 'Zeroing of unmatched products was skipped. ' . $reason ) );
    }

    /**
     * One query returning the IDs of products whose regular price is already 0
     * AND whose stock quantity is already 0. Those are left exactly as they
     * are (not loaded, not saved). Any other product that reaches the zeroing
     * step is set to price 0 / stock 0.
     */
    private static function already_zero_product_ids() {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT p.ID AS id,
                MAX(CASE WHEN m.meta_key = '_regular_price' THEN m.meta_value END) AS rp,
                MAX(CASE WHEN m.meta_key = '_stock' THEN m.meta_value END) AS stock
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
                AND m.meta_key IN ('_regular_price', '_stock')
             WHERE p.post_type IN ('product', 'product_variation')
             AND p.post_status NOT IN ('trash', 'auto-draft')
             GROUP BY p.ID",
            ARRAY_A
        );

        $zero = array();
        if ( ! is_array( $rows ) ) {
            return $zero; // on a DB hiccup just treat nothing as done: slower, never unsafe
        }

        foreach ( $rows as $row ) {
            // A blank price is NOT 0 (it means "no price"), so it still gets set to 0.
            if ( null !== $row['rp'] && '' !== $row['rp'] && is_numeric( $row['rp'] ) && 0.0 === (float) $row['rp']
                && null !== $row['stock'] && '' !== $row['stock'] && is_numeric( $row['stock'] ) && 0.0 === (float) $row['stock']
            ) {
                $zero[ (int) $row['id'] ] = true;
            }
        }

        return $zero;
    }

    private static function empty_stats( $existing_skus_loaded ) {
        return array(
            'rows_read' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'unknown_sku' => 0,
            'excluded_sku' => 0,
            'zeroed' => 0,
            'blank_sku' => 0,
            'invalid_price' => 0,
            'invalid_stock' => 0,
            'errors' => 0,
            'existing_skus_loaded' => $existing_skus_loaded,
        );
    }

    private static function process_row( $row, $indexes, $sku_map, $excluded_skus, &$seen_ids, &$stats ) {
        $stats['rows_read']++;

        $sku = isset( $row[ $indexes['SKU'] ] ) ? self::normalize_sku( $row[ $indexes['SKU'] ] ) : '';
        if ( '' === $sku ) {
            $stats['blank_sku']++;
            return;
        }

        // Excluded SKUs take priority over every update rule. We check this
        // before loading the WooCommerce product, so a listed SKU can never
        // reach price/stock update code during this sync.
        if ( isset( $excluded_skus[ $sku ] ) ) {
            $stats['excluded_sku']++;
            return;
        }

        // Critical speed rule: unknown SKUs are skipped before loading a product.
        if ( ! isset( $sku_map[ $sku ] ) ) {
            $stats['unknown_sku']++;
            return;
        }

        // This website product IS present in the supplier file, so it must
        // never be zeroed at the end of the run. Recorded before loading the
        // product so that even a product that errors below stays protected.
        $seen_ids[ $sku_map[ $sku ] ] = true;

        $product = wc_get_product( $sku_map[ $sku ] );
        if ( ! $product ) {
            $stats['errors']++;
            return;
        }

        $supplier_price_raw = isset( $row[ $indexes['Price'] ] )
            ? trim( (string) $row[ $indexes['Price'] ] )
            : '';

        $supplier_stock_raw = isset( $row[ $indexes['1stCheapestDistributorStock'] ] )
            ? trim( (string) $row[ $indexes['1stCheapestDistributorStock'] ] )
            : '';

        $changed = false;

        if ( '' !== $supplier_price_raw && is_numeric( $supplier_price_raw ) ) {
            $supplier_price = wc_format_decimal( $supplier_price_raw );
            $current_price = (string) $product->get_regular_price();

            if ( (string) $current_price !== (string) $supplier_price ) {
                $product->set_regular_price( $supplier_price );
                $changed = true;
            }
        } elseif ( '' !== $supplier_price_raw ) {
            $stats['invalid_price']++;
        }

        if ( '' !== $supplier_stock_raw && is_numeric( $supplier_stock_raw ) ) {
            $supplier_stock = max( 0, (int) round( (float) $supplier_stock_raw ) );
            $current_stock = $product->get_stock_quantity();

            if ( ! $product->managing_stock() || (int) $current_stock !== $supplier_stock ) {
                $product->set_manage_stock( true );
                $product->set_stock_quantity( $supplier_stock );
                $product->set_stock_status( $supplier_stock > 0 ? 'instock' : 'outofstock' );
                $changed = true;
            }
        } elseif ( '' !== $supplier_stock_raw ) {
            $stats['invalid_stock']++;
        }

        if ( $changed ) {
            $product->save();
            $stats['updated']++;
        } else {
            $stats['unchanged']++;
        }

        unset( $product );
    }
}

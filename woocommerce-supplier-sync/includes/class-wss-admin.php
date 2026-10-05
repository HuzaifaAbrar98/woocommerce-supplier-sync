<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WSS_Admin {

    const OPTION_KEY = 'wss_settings';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_post_wss_test_connection', array( __CLASS__, 'test_connection' ) );
        add_action( 'admin_post_wss_run_sync', array( __CLASS__, 'run_sync' ) );
        add_action( 'admin_post_wss_resume_sync', array( __CLASS__, 'resume_sync' ) );
        add_action( 'admin_post_wss_discard_checkpoint', array( __CLASS__, 'discard_checkpoint' ) );
        add_action( 'admin_post_wss_process_existing_csv', array( __CLASS__, 'process_existing_csv' ) );
        add_action( 'admin_post_wss_upload_csvs', array( __CLASS__, 'upload_csvs' ) );
    }

    public static function admin_menu() {
        add_menu_page(
            'Supplier Sync',
            'Supplier Sync',
            'manage_options',
            'wss-supplier-sync',
            array( __CLASS__, 'dashboard_page' ),
            'dashicons-update',
            56
        );

        add_submenu_page(
            'wss-supplier-sync',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'wss-supplier-sync',
            array( __CLASS__, 'dashboard_page' )
        );

        add_submenu_page(
            'wss-supplier-sync',
            'Settings',
            'Settings',
            'manage_options',
            'wss-settings',
            array( __CLASS__, 'settings_page' )
        );
    }

    public static function register_settings() {
        register_setting(
            'wss_settings_group',
            self::OPTION_KEY,
            array(
                'type' => 'array',
                'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
                'default' => array(),
            )
        );

        add_settings_section(
            'wss_connection_section',
            'Supplier FTP Settings',
            function() {
                echo '<p>Enter the FTP details supplied by your distributor. The CSV will be downloaded directly on the server.</p>';
            },
            'wss-settings'
        );

        $fields = array(
            'connection_type' => array( 'Connection Type', 'select' ),
            'host'            => array( 'FTP Host', 'text' ),
            'port'            => array( 'Port', 'number' ),
            'username'        => array( 'Username', 'text' ),
            'password'        => array( 'Password', 'password' ),
            'remote_path'       => array( 'Main Supplier CSV Remote Path', 'text' ),
            'local_path'        => array( 'Local CSV Storage Path', 'text' ),
            'exclude_remote_path' => array( 'Skip-SKU CSV Remote Path', 'text' ),
        );

        foreach ( $fields as $key => $field ) {
            add_settings_field(
                'wss_' . $key,
                $field[0],
                array( __CLASS__, 'render_field' ),
                'wss-settings',
                'wss_connection_section',
                array( 'key' => $key, 'type' => $field[1] )
            );
        }

        add_settings_field(
            'wss_delete_after_sync',
            'Delete CSV After Sync',
            array( __CLASS__, 'render_field' ),
            'wss-settings',
            'wss_connection_section',
            array( 'key' => 'delete_after_sync', 'type' => 'checkbox' )
        );

        add_settings_section(
            'wss_zero_section',
            'Unmatched Products (Zero Out)',
            function() {
                echo '<p>After the supplier file has been fully read, any website product with a SKU that is <strong>not</strong> in the supplier file and <strong>not</strong> in the Skip-SKU file can be set to <strong>price 0 and stock 0</strong> (out of stock, backorders off). Skip-SKU products are always left alone, even when they are missing from the supplier file. Products with no SKU are never touched.</p>';
            },
            'wss-settings'
        );

        add_settings_field( 'wss_zero_unmatched', 'Zero Unmatched Products', array( __CLASS__, 'render_field' ), 'wss-settings', 'wss_zero_section', array( 'key' => 'zero_unmatched', 'type' => 'checkbox' ) );
        add_settings_field( 'wss_zero_dry_run', 'Preview Only', array( __CLASS__, 'render_field' ), 'wss-settings', 'wss_zero_section', array( 'key' => 'zero_dry_run', 'type' => 'checkbox' ) );
        add_settings_field( 'wss_zero_max_percent', 'Safety Limit (%)', array( __CLASS__, 'render_field' ), 'wss-settings', 'wss_zero_section', array( 'key' => 'zero_max_percent', 'type' => 'number' ) );
    }

    public static function sanitize_settings( $input ) {
        $old = get_option( self::OPTION_KEY, array() );
        $output = array();

        $output['connection_type'] = isset( $input['connection_type'] ) ? sanitize_key( $input['connection_type'] ) : 'ftp';
        $output['host'] = isset( $input['host'] ) ? self::clean_host( $input['host'] ) : '';
        $output['port'] = isset( $input['port'] ) ? absint( $input['port'] ) : 21;
        $output['username'] = isset( $input['username'] ) ? sanitize_text_field( $input['username'] ) : '';
        $output['remote_path'] = isset( $input['remote_path'] ) ? sanitize_text_field( $input['remote_path'] ) : '/Products.csv';
        // Optional second FTP CSV containing SKUs that must never be updated by this sync.
        // Leave blank to disable the exclusion feature.
        $output['exclude_remote_path'] = isset( $input['exclude_remote_path'] ) ? sanitize_text_field( trim( $input['exclude_remote_path'] ) ) : ''; 
        $output['local_path'] = isset( $input['local_path'] ) ? sanitize_text_field( trim( $input['local_path'] ) ) : '';

        // If someone leaves it blank, or types a web path like "/wp-content/uploads/..."
        // instead of the real server filesystem path, fall back to a safe default
        // computed from WP_CONTENT_DIR, which is always the correct absolute path.
        if ( empty( $output['local_path'] ) || false === strpos( $output['local_path'], WP_CONTENT_DIR ) ) {
            $output['local_path'] = trailingslashit( WP_CONTENT_DIR ) . 'uploads/wss-supplier-sync/';
        }

        if ( ! empty( $input['password'] ) ) {
            $output['password'] = self::encrypt_secret( sanitize_text_field( $input['password'] ) );
        } elseif ( isset( $old['password'] ) ) {
            $output['password'] = $old['password'];
        }

        $output['delete_after_sync'] = ! empty( $input['delete_after_sync'] ) ? 1 : 0;
        $output['zero_unmatched'] = ! empty( $input['zero_unmatched'] ) ? 1 : 0;
        $output['zero_dry_run'] = ! empty( $input['zero_dry_run'] ) ? 1 : 0;
        $output['zero_max_percent'] = isset( $input['zero_max_percent'] ) && '' !== $input['zero_max_percent']
            ? max( 1, min( 100, absint( $input['zero_max_percent'] ) ) )
            : 85;

        return $output;
    }

    /**
     * Encrypts the FTP password before it's stored in wp_options, using a key
     * derived from this site's own AUTH_KEY/AUTH_SALT. This is not a substitute
     * for keeping the site itself secure (anyone who can execute PHP on the
     * site can still decrypt it), but it prevents the password from being
     * readable in plain text from a database export/backup/leak alone.
     */
    private static function encrypt_secret( $plaintext ) {
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            return $plaintext; // fall back gracefully if OpenSSL isn't available
        }
        $key = hash( 'sha256', wp_salt( 'auth' ), true );
        $iv = random_bytes( 16 );
        $cipher = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        return 'wssenc:' . base64_encode( $iv . $cipher );
    }

    public static function decrypt_secret( $stored ) {
        if ( 0 !== strpos( (string) $stored, 'wssenc:' ) ) {
            return $stored; // legacy plaintext value saved before this version
        }
        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return '';
        }
        $raw = base64_decode( substr( $stored, 7 ) );
        $iv = substr( $raw, 0, 16 );
        $cipher = substr( $raw, 16 );
        $key = hash( 'sha256', wp_salt( 'auth' ), true );
        $plain = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        return false === $plain ? '' : $plain;
    }

    /**
     * Normalizes a pasted FTP host value: strips a leading ftp:// or ftp:// scheme,
     * strips any trailing slash or path/query someone pastes along with the host
     * (e.g. "ftp.example.co.uk/" or "ftp://ftp.example.co.uk/files/"), and trims
     * whitespace. Prevents the whole hostname lookup from failing because of a
     * stray character that has nothing to do with DNS.
     */
    private static function clean_host( $raw ) {
        $host = sanitize_text_field( $raw );
        $host = trim( $host );
        $host = preg_replace( '#^[a-z]+://#i', '', $host );
        $host = strtok( $host, '/' ); // drop anything from the first slash onward
        $host = strtok( $host, ':' ); // drop an accidentally-included :port too
        return trim( $host );
    }

    public static function render_field( $args ) {
        $settings = get_option( self::OPTION_KEY, array() );
        $key = $args['key'];
        $type = $args['type'];
        $defaults = array( 'delete_after_sync' => 1, 'zero_dry_run' => 1, 'zero_max_percent' => 85 );
        $value = isset( $settings[ $key ] ) ? $settings[ $key ] : ( $defaults[ $key ] ?? '' );

        if ( 'select' === $type ) {
            echo '<select name="' . esc_attr( self::OPTION_KEY . '[' . $key . ']' ) . '">';
            echo '<option value="ftp"' . selected( $value, 'ftp', false ) . '>FTP</option>';
            echo '<option value="ftps"' . selected( $value, 'ftps', false ) . '>FTPS</option>';
            echo '<option value="sftp"' . selected( $value, 'sftp', false ) . '>SFTP (not supported by native PHP FTP)</option>';
            echo '</select>';
            return;
        }

        if ( 'checkbox' === $type ) {
            $checkbox_labels = array(
                'delete_after_sync' => 'Recommended: keeps the supplier CSV (contains cost pricing) from sitting on disk between runs.',
                'zero_unmatched'    => 'Set price and stock to 0 for website SKUs that are in neither the supplier file nor the Skip-SKU file.',
                'zero_dry_run'      => 'Recommended for the first run: report how many products WOULD be zeroed, without changing anything. Untick to apply for real.',
            );
            echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_KEY . '[' . $key . ']' ) . '" value="1"' . checked( ! empty( $value ), true, false ) . '> ' . esc_html( $checkbox_labels[ $key ] ?? '' ) . '</label>';
            return;
        }

        $placeholder = '';
        if ( 'password' === $type ) {
            $value = '';
            $placeholder = 'Leave blank to keep saved password';
        }

        echo '<input class="regular-text" type="' . esc_attr( $type ) . '" name="' .
            esc_attr( self::OPTION_KEY . '[' . $key . ']' ) . '" value="' .
            esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '">';

        if ( 'local_path' === $key ) {
            echo '<p class="description">Leave blank to use the safe default: <code>' . esc_html( trailingslashit( WP_CONTENT_DIR ) . 'uploads/wss-supplier-sync/' ) . '</code>. This must be a real server filesystem path (starting with <code>' . esc_html( WP_CONTENT_DIR ) . '</code> on this install), not a web URL like <code>/wp-content/uploads/2026</code> — those look similar but are not the same thing, and typing the web-style version will be automatically replaced with the safe default above.</p>';
        }

        if ( 'zero_max_percent' === $key ) {
            echo '<p class="description">Zeroing is refused if it would affect more than this share of your website products (1-100, default 85). It protects you if the supplier sends a partial or wrong file. Take a database backup before applying zeroing for the first time: the plugin does not keep an undo history.</p>';
        }

        if ( 'exclude_remote_path' === $key ) {
            echo '<p class="description">Optional. Enter the exact FTP path of the small CSV containing SKUs that must be skipped. Example: <code>/SkipSKUs.csv</code>. Leave blank to disable SKU exclusions. The CSV must contain a <code>SKU</code> header.</p>';
        }
    }

    public static function dashboard_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $last = get_option( 'wss_last_sync', array() );
        $batches = get_option( 'wss_sync_batches', array() );
        $in_progress = get_option( 'wss_sync_in_progress', array() );
        $checkpoint = get_option( 'wss_sync_checkpoint', array() );
        $checkpoint_incomplete = ! empty( $checkpoint ) && 'incomplete' === ( $checkpoint['status'] ?? '' );
        ?>
        <div class="wrap">
            <h1>WooCommerce Supplier Sync</h1>

            <?php $flash = self::get_flash_message(); ?>
            <?php if ( $flash ) : ?>
                <div class="notice notice-info inline"><p><?php echo esc_html( $flash ); ?></p></div>
            <?php endif; ?>

            <p>This phase downloads the supplier CSV on the server and synchronizes existing WooCommerce products by SKU.</p>

            <h2>Processing Rules</h2>
            <ul style="list-style:disc;padding-left:24px;">
                <li>Only SKU, supplier price, and supplier stock are read.</li>
                <li>Blank SKUs are skipped.</li>
                <li>SKUs not present on the website are skipped immediately.</li>
                <li>Only changed price and stock values are updated.</li>
                <li>SKUs listed in the optional Skip-SKU CSV are never updated.</li>
                <li>Unknown CSV columns are ignored.</li>
                <li>The laptop/browser does not need to remain connected during a scheduled server run.</li>
            </ul>

            <h2>Actions</h2>
            <p>
                <a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wss_test_connection' ), 'wss_test_connection' ) ); ?>">Test FTP Connection</a>
                <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wss_run_sync' ), 'wss_run_sync' ) ); ?>">Download &amp; Run Sync</a>
                <a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wss_process_existing_csv' ), 'wss_process_existing_csv' ) ); ?>">Process Existing Local CSV</a>
                <?php if ( $checkpoint_incomplete ) : ?>
                    <a class="button button-primary" style="background:#d63638;border-color:#d63638;" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wss_resume_sync' ), 'wss_resume_sync' ) ); ?>">Resume Incomplete Sync (Batch <?php echo esc_html( $checkpoint['batch_number'] ?? '?' ); ?>+)</a>
                    <a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wss_discard_checkpoint' ), 'wss_discard_checkpoint' ) ); ?>" onclick="return confirm('This discards the incomplete sync progress. The next sync will start over from row 1. Continue?');">Discard Incomplete Sync</a>
                <?php endif; ?>
            </p>
            <p class="description">
                "Process Existing Local CSV" skips FTP entirely and processes whatever file is already sitting at your configured Local CSV Storage Path — useful for testing the sync logic while an FTP connection issue is still being fixed, or as a manual fallback (upload the file yourself via File Manager/SFTP first, using the exact filename <code>supplier-latest.csv</code>).
            </p>

            <h2>Upload CSV Files</h2>
            <p>Use this when you want to bypass FTP. Uploading replaces the corresponding local file only after the new CSV passes validation. After uploading, click <strong>Process Existing Local CSV</strong>.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="max-width:900px;">
                <input type="hidden" name="action" value="wss_upload_csvs">
                <?php wp_nonce_field( 'wss_upload_csvs', 'wss_upload_csvs_nonce' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wss_supplier_csv">Main Supplier CSV</label></th>
                        <td>
                            <input type="file" id="wss_supplier_csv" name="supplier_csv" accept=".csv,text/csv">
                            <p class="description">Saved as <code>supplier-latest.csv</code>. Required headers: <code>SKU</code>, <code>Price</code>, <code>1stCheapestDistributorStock</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wss_skip_sku_csv">Skip-SKU CSV</label></th>
                        <td>
                            <input type="file" id="wss_skip_sku_csv" name="skip_sku_csv" accept=".csv,text/csv">
                            <p class="description">Saved as <code>supplier-skip-skus.csv</code>. It must contain a <code>SKU</code> header. Leave this field empty if you only want to replace the main supplier file.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Upload Selected CSV Files', 'secondary', 'submit', false ); ?>
            </form>

            <?php $local_files = self::local_file_statuses(); ?>
            <h3>Current Local Files</h3>
            <table class="widefat striped" style="max-width:900px;">
                <thead><tr><th>File</th><th>Status</th><th>Size</th><th>Last Modified</th></tr></thead>
                <tbody>
                    <?php foreach ( $local_files as $file ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $file['name'] ); ?></code></td>
                            <td><?php echo esc_html( $file['exists'] ? 'Present' : 'Not uploaded' ); ?></td>
                            <td><?php echo esc_html( $file['exists'] ? size_format( $file['size'] ) : '—' ); ?></td>
                            <td><?php echo esc_html( $file['exists'] ? wp_date( 'Y-m-d H:i:s', $file['mtime'] ) : '—' ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( $checkpoint_incomplete ) : ?>
                <div class="notice notice-warning inline"><p><strong>An incomplete sync was found</strong> — it stopped partway through batch <?php echo esc_html( $checkpoint['batch_number'] ?? '?' ); ?>. It will be resumed automatically on the next scheduled run, or click "Resume Incomplete Sync" above to continue it right now.</p></div>
            <?php endif; ?>

            <h2>Last Sync</h2>
            <?php if ( ! empty( $last ) ) : ?>
                <table class="widefat striped" style="max-width:760px;">
                    <?php foreach ( $last as $key => $value ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( ucwords( str_replace( '_', ' ', $key ) ) ); ?></strong></td>
                            <td><?php echo esc_html( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php else : ?>
                <p>No synchronization has been completed yet.</p>
            <?php endif; ?>

            <h2>Batches (<?php echo esc_html( self::batch_size_label() ); ?> products per batch)</h2>
            <?php if ( ! empty( $batches ) ) : ?>
                <table class="widefat striped" style="max-width:1100px;">
                    <thead>
                        <tr>
                            <th>Batch</th>
                            <th>Rows Read</th>
                            <th>Updated</th>
                            <th>Unchanged</th>
                            <th>Unknown SKU</th>
                            <th>Skipped by Exclusion</th>
                            <th>Zeroed (unmatched)</th>
                            <th>Blank SKU</th>
                            <th>Invalid Price</th>
                            <th>Invalid Stock</th>
                            <th>Errors</th>
                            <th>Time (s)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $batches as $batch ) : ?>
                            <tr>
                                <td>#<?php echo esc_html( $batch['batch_number'] ); ?><?php echo ! empty( $batch['phase'] ) ? ' (' . esc_html( $batch['phase'] ) . ')' : ''; ?></td>
                                <td><?php echo esc_html( $batch['rows_read'] ); ?></td>
                                <td><?php echo esc_html( $batch['updated'] ); ?></td>
                                <td><?php echo esc_html( $batch['unchanged'] ); ?></td>
                                <td><?php echo esc_html( $batch['unknown_sku'] ); ?></td>
                                <td><?php echo esc_html( $batch['excluded_sku'] ?? 0 ); ?></td>
                                <td><?php echo esc_html( $batch['zeroed'] ?? 0 ); ?></td>
                                <td><?php echo esc_html( $batch['blank_sku'] ); ?></td>
                                <td><?php echo esc_html( $batch['invalid_price'] ); ?></td>
                                <td><?php echo esc_html( $batch['invalid_stock'] ); ?></td>
                                <td><?php echo esc_html( $batch['errors'] ); ?></td>
                                <td><?php echo esc_html( $batch['duration_seconds'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ( ! empty( $in_progress ) && 'running' === $in_progress['status'] ) : ?>
                    <p><em>A sync is currently running — refresh this page to see new batches as they complete.</em></p>
                <?php endif; ?>
            <?php else : ?>
                <p>No batches recorded yet. Batches appear here as a sync runs, so you can watch progress in real time by refreshing this page.</p>
            <?php endif; ?>

            <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=wss-settings' ) ); ?>">Configure Supplier Settings</a></p>
        </div>
        <?php
    }

    /**
     * Result messages are stored server-side and shown once, rather than
     * placed in the redirect URL's query string. This isn't just tidier —
     * some hosting-level web application firewalls (WAF/ModSecurity) block
     * requests whose URL contains patterns that look like code execution,
     * e.g. "ftp_get():" with parentheses in a raw error message, causing a
     * hard "403 Forbidden" on the redirect itself. Keeping the message out
     * of the URL avoids that entirely.
     */
    private static function set_flash_message( $message ) {
        set_transient( 'wss_flash_' . get_current_user_id(), $message, MINUTE_IN_SECONDS );
    }

    private static function get_flash_message() {
        $key = 'wss_flash_' . get_current_user_id();
        $message = get_transient( $key );
        if ( $message ) {
            delete_transient( $key );
        }
        return $message;
    }

    private static function zero_summary( $result ) {
        return ! empty( $result['zeroing_status'] ) ? ' Zeroing: ' . $result['zeroing_status'] : '';
    }

    private static function batch_size_label() {
        return number_format( WSS_Sync_Engine::BATCH_SIZE );
    }

    public static function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Supplier Sync Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'wss_settings_group' );
                do_settings_sections( 'wss-settings' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    private static function local_csv_directory() {
        $settings = get_option( self::OPTION_KEY, array() );
        $path = isset( $settings['local_path'] ) ? trim( (string) $settings['local_path'] ) : '';
        if ( '' === $path || 0 !== strpos( $path, WP_CONTENT_DIR ) ) {
            $path = trailingslashit( WP_CONTENT_DIR ) . 'uploads/wss-supplier-sync/';
        }
        return trailingslashit( $path );
    }

    private static function local_file_statuses() {
        $directory = self::local_csv_directory();
        $files = array(
            'supplier-latest.csv',
            'supplier-skip-skus.csv',
        );
        $statuses = array();
        foreach ( $files as $name ) {
            $path = $directory . $name;
            $statuses[] = array(
                'name'   => $name,
                'exists' => file_exists( $path ),
                'size'   => file_exists( $path ) ? (int) filesize( $path ) : 0,
                'mtime'  => file_exists( $path ) ? (int) filemtime( $path ) : 0,
            );
        }
        return $statuses;
    }

    public static function upload_csvs() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        check_admin_referer( 'wss_upload_csvs', 'wss_upload_csvs_nonce' );

        $directory = self::local_csv_directory();
        if ( ! wp_mkdir_p( $directory ) || ! is_writable( $directory ) ) {
            self::set_flash_message( 'CSV upload failed: the local CSV directory is missing or not writable: ' . $directory );
            self::redirect_dashboard();
        }

        $results = array();
        $uploads = array(
            'supplier_csv' => array(
                'filename' => 'supplier-latest.csv',
                'label'    => 'Main Supplier CSV',
                'validator' => array( __CLASS__, 'validate_main_csv' ),
            ),
            'skip_sku_csv' => array(
                'filename' => 'supplier-skip-skus.csv',
                'label'    => 'Skip-SKU CSV',
                'validator' => array( __CLASS__, 'validate_skip_csv' ),
            ),
        );

        foreach ( $uploads as $field => $config ) {
            if ( empty( $_FILES[ $field ] ) || ! isset( $_FILES[ $field ]['error'] ) || UPLOAD_ERR_NO_FILE === (int) $_FILES[ $field ]['error'] ) {
                continue;
            }

            $file = $_FILES[ $field ];
            if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
                $results[] = $config['label'] . ': upload failed with PHP upload error ' . (int) $file['error'] . '.';
                continue;
            }

            $original_name = isset( $file['name'] ) ? (string) $file['name'] : '';
            if ( 'csv' !== strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) ) ) {
                $results[] = $config['label'] . ': only .csv files are allowed.';
                continue;
            }

            $tmp = isset( $file['tmp_name'] ) ? $file['tmp_name'] : '';
            if ( ! is_uploaded_file( $tmp ) ) {
                $results[] = $config['label'] . ': the uploaded file could not be verified as a browser upload.';
                continue;
            }

            if ( ! is_readable( $tmp ) || 0 === (int) filesize( $tmp ) ) {
                $results[] = $config['label'] . ': the uploaded CSV is empty or unreadable.';
                continue;
            }

            $validation = call_user_func( $config['validator'], $tmp );
            if ( is_wp_error( $validation ) ) {
                $results[] = $config['label'] . ': ' . $validation->get_error_message();
                continue;
            }

            $destination = $directory . $config['filename'];
            $temporary_destination = $destination . '.uploading-' . wp_generate_password( 12, false, false );

            if ( ! @move_uploaded_file( $tmp, $temporary_destination ) ) {
                $results[] = $config['label'] . ': WordPress could not move the uploaded file into the sync directory.';
                continue;
            }

            // Protect the uploaded supplier data from direct web access.
            self::protect_local_directory( $directory );

            $backup = $destination . '.previous-' . wp_generate_password( 12, false, false );
            if ( file_exists( $destination ) && ! @rename( $destination, $backup ) ) {
                @unlink( $temporary_destination );
                $results[] = $config['label'] . ': the existing local file could not be safely replaced.';
                continue;
            }

            if ( ! @rename( $temporary_destination, $destination ) ) {
                @unlink( $temporary_destination );
                if ( file_exists( $backup ) ) {
                    @rename( $backup, $destination );
                }
                $results[] = $config['label'] . ': the uploaded file could not be finalized; the previous file was kept.';
                continue;
            }

            if ( file_exists( $backup ) ) {
                @unlink( $backup );
            }

            $results[] = $config['label'] . ' uploaded successfully (' . size_format( (int) filesize( $destination ) ) . ').';
        }

        if ( empty( $results ) ) {
            $results[] = 'No CSV file was selected.';
        }

        self::set_flash_message( implode( ' ', $results ) );
        self::redirect_dashboard();
    }

    private static function validate_main_csv( $file ) {
        $handle = @fopen( $file, 'rb' );
        if ( ! $handle ) {
            return new WP_Error( 'wss_upload_open_failed', 'Could not read the uploaded CSV.' );
        }
        $headers = fgetcsv( $handle );
        fclose( $handle );
        if ( false === $headers ) {
            return new WP_Error( 'wss_upload_empty', 'The uploaded CSV contains no header row.' );
        }
        $headers = array_map( function( $header ) {
            $header = trim( (string) $header );
            return preg_replace( '/^\xEF\xBB\xBF/', '', $header );
        }, $headers );
        return WSS_CSV_Processor::get_required_indexes( $headers );
    }

    private static function validate_skip_csv( $file ) {
        $handle = @fopen( $file, 'rb' );
        if ( ! $handle ) {
            return new WP_Error( 'wss_upload_open_failed', 'Could not read the uploaded Skip-SKU CSV.' );
        }
        $headers = fgetcsv( $handle );
        fclose( $handle );
        if ( false === $headers ) {
            return new WP_Error( 'wss_upload_empty', 'The uploaded Skip-SKU CSV contains no header row.' );
        }
        return WSS_CSV_Processor::get_sku_index( $headers );
    }

    private static function protect_local_directory( $directory ) {
        $htaccess = $directory . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            @file_put_contents( $htaccess, "Require all denied\n" );
        }
        $index = $directory . 'index.php';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, "<?php\n// Silence is golden.\n" );
        }
    }

    private static function redirect_dashboard() {
        wp_safe_redirect( add_query_arg( array(
            'page' => 'wss-supplier-sync',
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function test_connection() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        check_admin_referer( 'wss_test_connection' );
        $result = WSS_Sync_Engine::test_connection();

        $message = is_wp_error( $result ) ? 'FTP test failed: ' . $result->get_error_message() : 'FTP connection successful.';
        self::set_flash_message( $message );
        wp_safe_redirect( add_query_arg( array(
            'page' => 'wss-supplier-sync',
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function run_sync() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        check_admin_referer( 'wss_run_sync' );
        $result = WSS_Sync_Engine::run();

        $message = is_wp_error( $result ) ? 'Sync failed: ' . $result->get_error_message() : 'Sync completed. Updated: ' . absint( $result['updated'] ) . ', skipped unknown SKUs: ' . absint( $result['unknown_sku'] ) . '.' . self::zero_summary( $result );
        self::set_flash_message( $message );
        wp_safe_redirect( add_query_arg( array(
            'page' => 'wss-supplier-sync',
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function process_existing_csv() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        check_admin_referer( 'wss_process_existing_csv' );
        $result = WSS_Sync_Engine::run( false, true );

        $message = is_wp_error( $result ) ? 'Local CSV sync failed: ' . $result->get_error_message() : 'Local CSV sync completed. Updated: ' . absint( $result['updated'] ) . ', skipped unknown SKUs: ' . absint( $result['unknown_sku'] ) . '.' . self::zero_summary( $result );
        self::set_flash_message( $message );
        wp_safe_redirect( add_query_arg( array(
            'page' => 'wss-supplier-sync',
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function resume_sync() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        check_admin_referer( 'wss_resume_sync' );
        $result = WSS_Sync_Engine::run( true );

        $message = is_wp_error( $result ) ? 'Resume failed: ' . $result->get_error_message() : 'Resumed sync completed. Updated: ' . absint( $result['updated'] ) . ', skipped unknown SKUs: ' . absint( $result['unknown_sku'] ) . '.' . self::zero_summary( $result );
        self::set_flash_message( $message );
        wp_safe_redirect( add_query_arg( array(
            'page' => 'wss-supplier-sync',
        ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function discard_checkpoint() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        check_admin_referer( 'wss_discard_checkpoint' );
        delete_option( 'wss_sync_checkpoint' );

        self::set_flash_message( 'Incomplete sync progress discarded. The next sync will start from the beginning.' );
        wp_safe_redirect( add_query_arg( array(
            'page' => 'wss-supplier-sync',
        ), admin_url( 'admin.php' ) ) );
        exit;
    }
}

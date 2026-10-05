<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WSS_CSV_Processor {


    public static function get_sku_index( $headers ) {
        foreach ( $headers as $index => $header ) {
            $header = trim( (string) $header );
            $header = preg_replace( '/^\xEF\xBB\xBF/', '', $header );
            if ( 0 === strcasecmp( $header, 'SKU' ) ) {
                return (int) $index;
            }
        }

        return new WP_Error( 'wss_missing_sku_header', 'Skip-SKU CSV must contain a SKU header.' );
    }

    public static function get_required_indexes( $headers ) {
        $required = array( 'SKU', 'Price', '1stCheapestDistributorStock' );
        $indexes = array();

        foreach ( $required as $column ) {
            $index = array_search( $column, $headers, true );
            if ( false === $index ) {
                return new WP_Error( 'wss_missing_header', 'Required CSV header missing: ' . $column );
            }
            $indexes[ $column ] = $index;
        }

        return $indexes;
    }
}

<?php
/**
 * Sunrise loader for PorkPress SSL.
 *
 * Maps incoming domains to sites using alias table.
 *
 * @package PorkPress\\SSL
 */

defined( 'ABSPATH' ) || exit;

add_filter(
    'pre_get_site_by_path',
    static function ( $site, $domain, $path, $segments ) {
        global $wpdb;

        $table = $wpdb->base_prefix . 'porkpress_domain_aliases';
        $domain = strtolower( preg_replace( '/:\d+$/', '', $domain ) );
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT site_id FROM {$table} WHERE domain = %s", $domain ) );

        if ( ! $row ) {
            return $site;
        }

        $site = get_site( (int) $row->site_id );
        if ( ! $site ) {
            return $site;
        }

        if ( ! empty( $site->archived ) ) {
            header( 'HTTP/1.1 410 Gone' );
            header( 'Content-Type: text/html; charset=utf-8' );
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Site Offline</title></head><body><h1>Site Offline</h1><p>This site is currently unavailable.</p></body></html>';
            exit;
        }

        $primary = $wpdb->get_var(
            $wpdb->prepare( "SELECT domain FROM {$table} WHERE site_id = %d AND is_primary = 1 LIMIT 1", $row->site_id )
        );

        if ( $primary ) {
            $site->domain = $primary;
        }

        return $site;
    },
    1,
    4
);

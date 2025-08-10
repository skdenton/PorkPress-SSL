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

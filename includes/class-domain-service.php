<?php
/**
 * Domain service.
 *
 * @package PorkPress\SSL
 */

namespace PorkPress\SSL;

defined( 'ABSPATH' ) || exit;

/**
 * Class Domain_Service
 */
class Domain_Service {
       /**
        * Porkbun API client.
        *
        * @var Porkbun_Client
        */
       protected Porkbun_Client $client;

       /**
        * Whether the API credentials are missing.
        */
       protected bool $missing_credentials = false;

       /**
        * Get the domain aliases table name.
        *
        * @return string
        */
       public static function get_alias_table_name(): string {
               global $wpdb;

               return $wpdb->base_prefix . 'porkpress_domain_aliases';
       }

       /**
        * Create the domain aliases table if it does not exist.
        */
       public static function create_alias_table(): void {
               global $wpdb;

               $table_name      = self::get_alias_table_name();
               $charset_collate = $wpdb->get_charset_collate();

               $sql = "CREATE TABLE {$table_name} (
site_id bigint(20) unsigned NOT NULL,
domain varchar(191) NOT NULL,
is_primary tinyint(1) NOT NULL DEFAULT 0,
status varchar(20) NOT NULL DEFAULT '',
PRIMARY KEY  (site_id, domain),
KEY domain (domain),
KEY status (status)
) {$charset_collate};";

               require_once ABSPATH . 'wp-admin/includes/upgrade.php';
               dbDelta( $sql );
       }

        /**
         * Constructor.
         */
        public function __construct() {
                $api_key    = defined( 'PORKPRESS_API_KEY' ) ? PORKPRESS_API_KEY : get_site_option( 'porkpress_ssl_api_key', '' );
                $api_secret = defined( 'PORKPRESS_API_SECRET' ) ? PORKPRESS_API_SECRET : get_site_option( 'porkpress_ssl_api_secret', '' );

                if ( empty( $api_key ) || empty( $api_secret ) ) {
                        $this->missing_credentials = true;
                }

                $this->client = new Porkbun_Client( $api_key, $api_secret );
        }

        /**
         * Whether the service has the required API credentials.
         */
        public function has_credentials(): bool {
                return ! $this->missing_credentials;
        }

        /**
         * List domains.
         *
         * @param int $page     Page number.
        * @param int $per_page Domains per page.
        *
        * @return array|Porkbun_Client_Error
        */
       public function list_domains( int $page = 1, int $per_page = 100 ) {
               $result = $this->client->listDomains( $page, $per_page );

               if ( $result instanceof Porkbun_Client_Error ) {
                       return $result;
               }

               if ( isset( $result['domains'] ) && is_array( $result['domains'] ) ) {
                       $result['domains'] = array_map(
                               function ( $domain ) {
                                       if ( isset( $domain['tld'] ) && ! isset( $domain['type'] ) ) {
                                               $domain['type'] = $domain['tld'];
                                       }
                                       if ( isset( $domain['expireDate'] ) && ! isset( $domain['expiry'] ) ) {
                                               $domain['expiry'] = $domain['expireDate'];
                                       }

                                       return $domain;
                               },
                               $result['domains']
                       );
               }

               return $result;
       }

       /**
        * Attach a domain to a site.
        *
        * @param string $domain Domain name.
        * @param int    $site_id Site ID.
        *
        * @return bool|Porkbun_Client_Error
        */
       public function attach_to_site( string $domain, int $site_id ) {
               if ( function_exists( 'update_site_meta' ) ) {
                       update_site_meta( $site_id, 'porkpress_domain', $domain );
               }

               return true;
       }

       /**
        * Detach a domain from any site.
        *
        * @param string $domain Domain name.
        *
        * @return bool
        */
       public function detach_from_site( string $domain ): bool {
               if ( function_exists( 'get_sites' ) && function_exists( 'delete_site_meta' ) ) {
                       $sites = get_sites( array( 'meta_key' => 'porkpress_domain', 'meta_value' => $domain ) );
                       foreach ( $sites as $site ) {
                               delete_site_meta( $site->blog_id, 'porkpress_domain', $domain );
                       }
               }

               return true;
       }

       /**
        * Create a domain alias record.
        *
        * @param int    $site_id    Site ID.
        * @param string $domain     Alias domain.
        * @param bool   $is_primary Whether this is the primary alias.
        * @param string $status     Alias status.
        *
        * @return bool Whether the insert succeeded.
        */
       public function add_alias( int $site_id, string $domain, bool $is_primary = false, string $status = '' ): bool {
               global $wpdb;

               $result = $wpdb->insert(
                       self::get_alias_table_name(),
                       array(
                               'site_id'    => $site_id,
                               'domain'     => $domain,
                               'is_primary' => $is_primary ? 1 : 0,
                               'status'     => $status,
                       ),
                       array( '%d', '%s', '%d', '%s' )
               );

               return false !== $result;
       }

       /**
        * Retrieve all aliases for a site.
        *
        * @param int $site_id Site ID.
        *
        * @return array List of alias records.
        */
       public function get_aliases( int $site_id ): array {
               global $wpdb;

               $sql = $wpdb->prepare( 'SELECT * FROM ' . self::get_alias_table_name() . ' WHERE site_id = %d', $site_id );

               return $wpdb->get_results( $sql, ARRAY_A );
       }

       /**
        * Update a domain alias.
        *
        * @param int    $site_id Site ID.
        * @param string $domain  Alias domain.
        * @param array  $data    Data to update (is_primary, status).
        *
        * @return bool Whether the update succeeded.
        */
       public function update_alias( int $site_id, string $domain, array $data ): bool {
               global $wpdb;

               $allowed = array( 'is_primary', 'status' );
               $update  = array();
               $format  = array();

               foreach ( $data as $key => $value ) {
                       if ( in_array( $key, $allowed, true ) ) {
                               if ( 'is_primary' === $key ) {
                                       $update[ $key ] = $value ? 1 : 0;
                                       $format[]       = '%d';
                               } else {
                                       $update[ $key ] = $value;
                                       $format[]       = '%s';
                               }
                       }
               }

               if ( empty( $update ) ) {
                       return false;
               }

               $where  = array( 'site_id' => $site_id, 'domain' => $domain );
               $wformat = array( '%d', '%s' );

               $result = $wpdb->update( self::get_alias_table_name(), $update, $where, $format, $wformat );

               return false !== $result;
       }

       /**
        * Delete a domain alias.
        *
        * @param int    $site_id Site ID.
        * @param string $domain  Alias domain.
        *
        * @return bool Whether the delete succeeded.
        */
       public function delete_alias( int $site_id, string $domain ): bool {
               global $wpdb;

               $result = $wpdb->delete(
                       self::get_alias_table_name(),
                       array(
                               'site_id' => $site_id,
                               'domain'  => $domain,
                       ),
                       array( '%d', '%s' )
               );

               return false !== $result;
       }

       /**
        * Disable a domain in Porkbun.
        *
        * @param string $domain Domain name.
        *
        * @return array|Porkbun_Client_Error
        */
       public function disable_domain( string $domain ) {
               return $this->client->disableDomain( $domain );
       }

       /**
        * Remove a domain from Porkbun.
        *
        * @param string $domain Domain name.
        *
        * @return array|Porkbun_Client_Error
        */
       public function remove_domain( string $domain ) {
               return $this->client->deleteDomain( $domain );
       }
}

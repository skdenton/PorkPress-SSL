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
        * Create a new site and attach a domain as its primary alias.
        *
        * @param string $domain       Domain to attach.
        * @param string $title        Site title.
        * @param string $admin_email  Administrator email for the site.
        * @param string $template     Optional template/locale for the site.
        *
        * @return int|\WP_Error Site ID on success or WP_Error on failure.
        */
       public function create_site( string $domain, string $title, string $admin_email, string $template = '' ) {
               \PorkPress\SSL\Logger::info(
                       'create_site_start',
                       array(
                               'domain'      => $domain,
                               'title'       => $title,
                               'admin_email' => $admin_email,
                               'template'    => $template,
                       )
               );

               $user_id = email_exists( $admin_email );
               if ( ! $user_id ) {
                       $username = sanitize_user( current( explode( '@', $admin_email ) ), true );
                       $password = wp_generate_password();
                       $user_id  = wp_create_user( $username, $password, $admin_email );
                       if ( is_wp_error( $user_id ) ) {
                               \PorkPress\SSL\Logger::error( 'create_user', array( 'email' => $admin_email ), $user_id->get_error_message() );
                               return $user_id;
                       }
                       \PorkPress\SSL\Logger::info( 'create_user', array( 'user_id' => $user_id, 'email' => $admin_email ), 'created' );
               } else {
                       \PorkPress\SSL\Logger::info( 'create_user', array( 'user_id' => $user_id, 'email' => $admin_email ), 'existing' );
               }

               $site_id = wpmu_create_blog( $domain, '/', $title, $user_id, array( 'template' => $template ), get_current_network_id() );
               if ( is_wp_error( $site_id ) ) {
                       \PorkPress\SSL\Logger::error( 'create_site', array( 'domain' => $domain ), $site_id->get_error_message() );
                       return $site_id;
               }

               \PorkPress\SSL\Logger::info( 'create_site', array( 'domain' => $domain, 'site_id' => $site_id ), 'created' );

               if ( function_exists( 'update_site_meta' ) ) {
                       update_site_meta( $site_id, 'porkpress_domain', $domain );
               }

               $this->add_alias( $site_id, $domain, true, 'active' );
               \PorkPress\SSL\Logger::info( 'set_primary_domain', array( 'site_id' => $site_id, 'domain' => $domain ), 'alias_created' );

               $url = 'https://' . $domain;
               update_blog_option( $site_id, 'siteurl', $url );
               update_blog_option( $site_id, 'home', $url );
               \PorkPress\SSL\Logger::info( 'update_siteurl_home', array( 'site_id' => $site_id, 'url' => $url ), 'updated' );

               return $site_id;
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
KEY domain (domain)
) {$charset_collate};";

               require_once ABSPATH . 'wp-admin/includes/upgrade.php';
               dbDelta( $sql );
       }

       /**
        * Create a domain alias entry.
        *
        * @param int    $site_id    Site ID.
        * @param string $domain     Domain name.
        * @param bool   $is_primary Whether the alias is primary.
        * @param string $status     Alias status.
        *
        * @return bool True on success, false on failure.
        */
       public function add_alias( int $site_id, string $domain, bool $is_primary = false, string $status = '' ): bool {
               global $wpdb;

               $table = self::get_alias_table_name();
               $data  = array(
                       'site_id'    => $site_id,
                       'domain'     => strtolower( sanitize_text_field( $domain ) ),
                       'is_primary' => $is_primary ? 1 : 0,
                       'status'     => sanitize_text_field( $status ),
               );

               return false !== $wpdb->insert( $table, $data, array( '%d', '%s', '%d', '%s' ) );
       }

       /**
        * Retrieve domain aliases.
        *
        * @param int|null    $site_id Optional site ID to filter.
        * @param string|null $domain  Optional domain to filter.
        *
        * @return array List of alias records.
        */
       public function get_aliases( ?int $site_id = null, ?string $domain = null ): array {
               global $wpdb;

               $table  = self::get_alias_table_name();
               $where  = array();
               $params = array();

               if ( null !== $site_id ) {
                       $where[]  = 'site_id = %d';
                       $params[] = $site_id;
               }

               if ( null !== $domain ) {
                       $where[]  = 'domain = %s';
                       $params[] = strtolower( sanitize_text_field( $domain ) );
               }

               $sql = "SELECT * FROM {$table}";
               if ( $where ) {
                       $sql .= ' WHERE ' . implode( ' AND ', $where );
                       $sql  = $wpdb->prepare( $sql, $params );
               }

               return $wpdb->get_results( $sql, ARRAY_A );
       }

       /**
        * Update a domain alias.
        *
        * @param int    $site_id Site ID.
        * @param string $domain  Domain name.
        * @param array  $data    Data to update (is_primary, status).
        *
        * @return bool True on success, false on failure.
        */
       public function update_alias( int $site_id, string $domain, array $data ): bool {
               global $wpdb;

               $fields  = array();
               $formats = array();

               if ( array_key_exists( 'is_primary', $data ) ) {
                       $fields['is_primary'] = $data['is_primary'] ? 1 : 0;
                       $formats[]            = '%d';
               }

               if ( array_key_exists( 'status', $data ) ) {
                       $fields['status'] = sanitize_text_field( $data['status'] );
                       $formats[]        = '%s';
               }

               if ( empty( $fields ) ) {
                       return false;
               }

               return false !== $wpdb->update(
                       self::get_alias_table_name(),
                       $fields,
                       array(
                               'site_id' => $site_id,
                               'domain'  => strtolower( sanitize_text_field( $domain ) ),
                       ),
                       $formats,
                       array( '%d', '%s' )
               );
       }

       /**
        * Delete a domain alias.
        *
        * @param int    $site_id Site ID.
        * @param string $domain  Domain name.
        *
        * @return bool True on success, false on failure.
        */
       public function delete_alias( int $site_id, string $domain ): bool {
               global $wpdb;

               return false !== $wpdb->delete(
                       self::get_alias_table_name(),
                       array(
                               'site_id' => $site_id,
                               'domain'  => strtolower( sanitize_text_field( $domain ) ),
                       ),
                       array( '%d', '%s' )
               );
       }

}

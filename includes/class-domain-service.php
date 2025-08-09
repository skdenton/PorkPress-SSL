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

}

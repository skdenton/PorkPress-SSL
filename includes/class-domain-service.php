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
         * Constructor.
         */
        public function __construct() {
                $api_key    = defined( 'PORKPRESS_API_KEY' ) ? PORKPRESS_API_KEY : get_site_option( 'porkpress_ssl_api_key', '' );
                $api_secret = defined( 'PORKPRESS_API_SECRET' ) ? PORKPRESS_API_SECRET : get_site_option( 'porkpress_ssl_api_secret', '' );
                $this->client = new Porkbun_Client( $api_key, $api_secret );
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
                return $this->client->listDomains( $page, $per_page );
        }
}

<?php
/**
 * SSL service.
 *
 * @package PorkPress\SSL
 */

namespace PorkPress\SSL;

defined( 'ABSPATH' ) || exit;

/**
 * Class SSL_Service
 *
 * Basic queuing and execution of SSL certificate issuance tasks. Issuance is
 * represented by logging the domains that would be included in the SAN
 * certificate. The queue is stored as a site option when WordPress functions
 * are available. In testing environments without WordPress, a static property
 * is used as a fallback.
 */
class SSL_Service {
       /**
        * Fallback queue used when WordPress option functions are unavailable.
        *
        * @var array<int>
        */
       protected static array $fallback_queue = array();

       /**
        * Queue a site for SSL certificate issuance.
        *
        * @param int $site_id Site ID.
        */
       public static function queue_issuance( int $site_id ): void {
               $site_id = (int) $site_id;

               if ( function_exists( '\\get_site_option' ) && function_exists( '\\update_site_option' ) ) {
                       $queue = \get_site_option( 'porkpress_ssl_issuance_queue', array() );
                       if ( ! in_array( $site_id, $queue, true ) ) {
                               $queue[] = $site_id;
                               \update_site_option( 'porkpress_ssl_issuance_queue', $queue );
                       }

                       if ( function_exists( '\\wp_next_scheduled' ) && function_exists( '\\wp_schedule_single_event' ) ) {
                               if ( ! \wp_next_scheduled( 'porkpress_ssl_run_issuance' ) ) {
                                       \wp_schedule_single_event( time(), 'porkpress_ssl_run_issuance' );
                               }
                       }

                       return;
               }

               if ( ! in_array( $site_id, self::$fallback_queue, true ) ) {
                       self::$fallback_queue[] = $site_id;
               }
       }

       /**
        * Retrieve the queued site IDs.
        *
        * @return array<int>
        */
       public static function get_queue(): array {
               if ( function_exists( '\\get_site_option' ) && function_exists( '\\update_site_option' ) ) {
                       $queue = \get_site_option( 'porkpress_ssl_issuance_queue', array() );
                       return array_map( 'intval', $queue );
               }

               return array_map( 'intval', self::$fallback_queue );
       }

       /**
        * Clear the issuance queue.
        */
       public static function clear_queue(): void {
               if ( function_exists( '\\get_site_option' ) && function_exists( '\\update_site_option' ) ) {
                       \update_site_option( 'porkpress_ssl_issuance_queue', array() );
               }
               self::$fallback_queue = array();
       }

       /**
        * Execute the issuance queue.
        *
        * @param Domain_Service|null $domains Optional domain service instance.
        */
       public static function run_queue( ?Domain_Service $domains = null ): void {
               $domains = $domains ?: new Domain_Service();
               $queue   = self::get_queue();

               if ( empty( $queue ) ) {
                       return;
               }

               // Gather the full set of domains across the network so that
               // certbot is invoked with all current SANs. This avoids
               // creating unintended new certificate lineages or dropping
               // existing names when expanding or shrinking the set.
               $records     = $domains->get_aliases();
               $all_domains = array_values(
                       array_unique(
                               array_map( fn( $a ) => $a['domain'], $records )
                       )
               );

               // Log queued sites for traceability but don't rely on them for
               // constructing the final domain list.
               foreach ( $queue as $site_id ) {
                       $aliases = $domains->get_aliases( $site_id );
                       $names   = array_map( fn( $a ) => $a['domain'], $aliases );

                       Logger::info(
                               'issue_certificate',
                               array(
                                       'site_id' => $site_id,
                                       'domains' => $names,
                               ),
                               'queued'
                       );
               }

               if ( empty( $all_domains ) ) {
                       self::clear_queue();
                       return;
               }

               $cert_name = \get_site_option(
                       'porkpress_ssl_cert_name',
                       defined( 'PORKPRESS_CERT_NAME' ) ? PORKPRESS_CERT_NAME : 'porkpress-network'
               );
               $staging   = function_exists( '\\get_site_option' ) ? (bool) \get_site_option( 'porkpress_ssl_le_staging', 0 ) : false;

               $cmd = Renewal_Service::build_certbot_command( $all_domains, $cert_name, $staging, false );

               $result = null;
               if ( is_callable( Renewal_Service::$runner ) ) {
                       $result = call_user_func( Renewal_Service::$runner, $cmd );
               } else {
                       $output = array();
                       $code   = 0;
                       exec( $cmd . ' 2>&1', $output, $code );
                       $result = array(
                               'code'   => $code,
                               'output' => implode( "\n", $output ),
                       );
               }

               if ( 0 !== $result['code'] ) {
                       Logger::error(
                               'issue_certificate',
                               array(
                                       'site_ids' => $queue,
                                       'domains'  => $all_domains,
                                       'output'   => $result['output'],
                               ),
                               'certbot failed'
                       );
                       Notifier::notify(
                               'error',
                               __( 'SSL issuance failed', 'porkpress-ssl' ),
                               __( 'Certbot failed during issuance.', 'porkpress-ssl' )
                       );
                       return;
               }

               Renewal_Service::write_manifest( $all_domains, $cert_name );
               Logger::info(
                       'issue_certificate',
                       array(
                               'site_ids' => $queue,
                               'domains'  => $all_domains,
                       ),
                       'success'
               );
               Notifier::notify(
                       'success',
                       __( 'SSL certificate issued', 'porkpress-ssl' ),
                       __( 'Certificate issuance completed successfully.', 'porkpress-ssl' )
               );

               self::clear_queue();
       }
}


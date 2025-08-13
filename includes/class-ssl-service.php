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

               // Gather the full set of domains across the network, excluding
               // internal subdomains so that certbot is invoked only with
               // external SANs.
               $records = $domains->get_aliases();
               $records = array_filter(
                       $records,
                       fn( $a ) => ! $domains->is_internal_subdomain( (int) $a['site_id'], $a['domain'] )
               );
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

               $staging = function_exists( '\\get_site_option' ) ? (bool) \get_site_option( 'porkpress_ssl_le_staging', 0 ) : false;

               $shards = self::shard_domains( $all_domains );
               $all_ok = true;
               foreach ( $shards as $index => $names ) {
                       $cert_name = 'porkpress-shard-' . $index;
                       $cmd       = Renewal_Service::build_certbot_command( $names, $cert_name, $staging, false );

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
                               $all_ok = false;
                               Logger::error(
                                       'issue_certificate',
                                       array(
                                               'site_ids' => $queue,
                                               'domains'  => $names,
                                               'output'   => $result['output'],
                                       ),
                                       'certbot failed'
                               );
                               continue;
                       }

                       $manifest_ok = Renewal_Service::write_manifest( $names, $cert_name );
                       $deploy_ok   = Renewal_Service::deploy_to_apache( $cert_name );
                       if ( ! $manifest_ok || ! $deploy_ok ) {
                               $all_ok = false;
                               Logger::error(
                                       'issue_certificate',
                                       array(
                                               'site_ids' => $queue,
                                               'domains'  => $names,
                                       ),
                                       'post-deploy failed'
                               );
                               continue;
                       }

                       Logger::info(
                               'issue_certificate',
                               array(
                                       'site_ids' => $queue,
                                       'domains'  => $names,
                               ),
                               'success'
                       );
               }

               Notifier::notify(
                       $all_ok ? 'success' : 'error',
                       $all_ok ? __( 'SSL certificate issued', 'porkpress-ssl' ) : __( 'SSL issuance failed', 'porkpress-ssl' ),
                       $all_ok ? __( 'Certificate issuance completed successfully.', 'porkpress-ssl' ) : __( 'Certbot failed during issuance.', 'porkpress-ssl' )
               );

               self::clear_queue();
       }

       /**
        * Group domains into deterministic shards of at most 90 names.
        *
        * @param array $domains List of domains.
        * @return array<int, array<int, string>> Map of shard index => domains.
        */
       public static function shard_domains( array $domains ): array {
               $domains = array_values( array_unique( $domains ) );
               $count   = count( $domains );
               if ( $count <= 0 ) {
                       return array();
               }

               $shard_count = max( 1, (int) ceil( $count / 90 ) );

               do {
                       $buckets = array_fill( 0, $shard_count, array() );
                       foreach ( $domains as $d ) {
                               $hash   = crc32( $d );
                               $bucket = $hash % $shard_count;
                               $buckets[ $bucket ][] = $d;
                       }
                       $max = 0;
                       foreach ( $buckets as $b ) {
                               if ( count( $b ) > $max ) {
                                       $max = count( $b );
                               }
                       }
                       if ( $max > 90 ) {
                               $shard_count++;
                       }
               } while ( $max > 90 );

               $result = array();
               $i      = 1;
               foreach ( $buckets as $bucket ) {
                       if ( ! empty( $bucket ) ) {
                               $result[ $i ] = $bucket;
                               $i++;
                       }
               }

               return $result;
       }
}


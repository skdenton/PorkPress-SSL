<?php
/**
 * TXT propagation waiter.
 *
 * Polls multiple resolvers for _acme-challenge TXT records until found or timeout.
 *
 * @package PorkPress\SSL
 */

namespace PorkPress\SSL;

require_once __DIR__ . '/class-runner.php';

defined( 'ABSPATH' ) || exit;

/**
 * Class TXT_Propagation_Waiter
 */
class TXT_Propagation_Waiter {
        /**
         * DNS resolvers to query.
         *
         * @var array<int, string>
         */
        protected array $resolvers;

        /**
         * Constructor.
         *
         * @param array<int, string> $resolvers Resolver IPs.
         */
        public function __construct( array $resolvers = array() ) {
                if ( empty( $resolvers ) ) {
                        $resolvers = array( '8.8.8.8', '1.1.1.1' );
                }

                if ( function_exists( 'apply_filters' ) ) {
                        $resolvers = apply_filters( 'porkpress_ssl_txt_propagation_resolvers', $resolvers );
                }

                $this->resolvers = $resolvers;
        }

        /**
         * Wait for TXT record propagation.
         *
         * Polls all resolvers until the TXT record exists (and optionally matches
         * the provided value) or the timeout is reached.
         *
         * @param string      $domain  Domain without the _acme-challenge prefix.
         * @param string|null $value   Optional TXT value to match.
         * @param int         $timeout Maximum time to wait in seconds.
         * @param int         $sleep   Sleep interval between checks in seconds.
         *
         * @return bool True if record found on all resolvers, false on timeout.
         */
        public function wait( string $domain, ?string $value = null, int $timeout = 60, int $sleep = 5 ): bool {
                $deadline = time() + $timeout;
                $name     = '_acme-challenge.' . $domain;

                do {
                        $all_found = true;
                        foreach ( $this->resolvers as $resolver ) {
                                $records = $this->query_txt( $name, $resolver );
                                if ( empty( $records ) ) {
                                        $all_found = false;
                                        break;
                                }
                                if ( null !== $value && ! in_array( $value, $records, true ) ) {
                                        $all_found = false;
                                        break;
                                }
                        }
                        if ( $all_found ) {
                                return true;
                        }
                        $this->do_sleep( $sleep );
                } while ( time() <= $deadline );

                return false;
        }

        /**
         * Query a resolver for TXT records.
         *
         * @param string $name     FQDN to query.
         * @param string $resolver Resolver address.
         *
         * @return array<int, string> List of TXT records.
         */
        protected function query_txt( string $name, string $resolver ): array {
                $cmd    = sprintf( 'dig +short @%s TXT %s', escapeshellarg( $resolver ), escapeshellarg( $name ) );
                $result = Runner::run( $cmd );
                if ( 0 !== $result['code'] ) {
                        $message = sprintf( 'dig command failed for resolver %s (exit code %d)', $resolver, $result['code'] );
                        trigger_error( $message, E_USER_WARNING );
                        return array();
                }
                $records = array();
                foreach ( preg_split( '/\r?\n/', trim( $result['output'] ) ) as $line ) {
                        $line = trim( $line );
                        if ( '' === $line ) {
                                continue;
                        }
                        $records[] = trim( $line, '"' );
                }
                return $records;
        }

        /**
         * Sleep wrapper to allow mocking in tests.
         *
         * @param int $seconds Seconds to sleep.
         */
        protected function do_sleep( int $seconds ): void {
                sleep( $seconds );
        }
}

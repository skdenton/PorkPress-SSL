<?php
/**
 * Dry-run Porkbun API client.
 *
 * Records planned requests without performing external HTTP calls.
 *
 * @package PorkPress\SSL
 */

namespace PorkPress\SSL;

defined( 'ABSPATH' ) || exit;

/**
 * Class Porkbun_Client_DryRun
 */
class Porkbun_Client_DryRun extends Porkbun_Client {
    /**
     * Recorded request plan.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $plan = array();

    /**
     * {@inheritDoc}
     */
    protected function request( string $endpoint, array $payload, string $method = 'POST' ) {
        $this->plan[] = array(
            'endpoint' => $endpoint,
            'payload'  => $payload,
            'method'   => $method,
        );

        if ( 'domain/listAll' === $endpoint ) {
            return array( 'status' => 'SUCCESS', 'domains' => array() );
        }

        if ( 0 === strpos( $endpoint, 'domain/getNs/' ) ) {
            return array( 'status' => 'SUCCESS', 'ns' => array() );
        }

        return array( 'status' => 'SUCCESS' );
    }
}

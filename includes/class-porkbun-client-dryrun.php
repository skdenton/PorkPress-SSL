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
    public function ping() {
        return parent::ping();
    }

    /**
     * {@inheritDoc}
     */
    public function get_pricing( string $tld ) {
        return parent::get_pricing( $tld );
    }

    /**
     * {@inheritDoc}
     */
    public function update_nameservers( string $domain, array $nameservers ) {
        return parent::update_nameservers( $domain, $nameservers );
    }

    /**
     * {@inheritDoc}
     */
    public function add_url_forward( string $domain, string $subdomain, string $destination, string $type = '302', bool $wildcard = false ) {
        return parent::add_url_forward( $domain, $subdomain, $destination, $type, $wildcard );
    }

    /**
     * {@inheritDoc}
     */
    public function get_url_forwarding( string $domain ) {
        return parent::get_url_forwarding( $domain );
    }

    /**
     * {@inheritDoc}
     */
    public function delete_url_forward( string $domain, int $id ) {
        return parent::delete_url_forward( $domain, $id );
    }

    /**
     * {@inheritDoc}
     */
    public function create_glue_record( string $domain, string $hostname, array $ips ) {
        return parent::create_glue_record( $domain, $hostname, $ips );
    }

    /**
     * {@inheritDoc}
     */
    public function update_glue_record( string $domain, string $hostname, array $ips ) {
        return parent::update_glue_record( $domain, $hostname, $ips );
    }

    /**
     * {@inheritDoc}
     */
    public function delete_glue_record( string $domain, string $hostname ) {
        return parent::delete_glue_record( $domain, $hostname );
    }

    /**
     * {@inheritDoc}
     */
    public function get_glue_records( string $domain ) {
        return parent::get_glue_records( $domain );
    }

    /**
     * {@inheritDoc}
     */
    public function delete_by_name_type( string $domain, string $subdomain, string $type ) {
        return parent::delete_by_name_type( $domain, $subdomain, $type );
    }

    /**
     * {@inheritDoc}
     */
    public function create_dnssec( string $domain, string $key_tag, string $algorithm, string $digest_type, string $digest ) {
        return parent::create_dnssec( $domain, $key_tag, $algorithm, $digest_type, $digest );
    }

    /**
     * {@inheritDoc}
     */
    public function retrieve_dnssec( string $domain ) {
        return parent::retrieve_dnssec( $domain );
    }

    /**
     * {@inheritDoc}
     */
    public function delete_dnssec( string $domain ) {
        return parent::delete_dnssec( $domain );
    }

    /**
     * {@inheritDoc}
     */
    public function retrieve_ssl_bundle( string $domain ) {
        return parent::retrieve_ssl_bundle( $domain );
    }

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

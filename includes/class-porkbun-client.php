<?php
/**
 * Porkbun API client.
 *
 * @package PorkPress\SSL
 */

namespace PorkPress\SSL;

defined( 'ABSPATH' ) || exit;

/**
 * Structured error object for Porkbun API failures.
 */
class Porkbun_Client_Error {
	public string $code;
	public string $message;
	public int $status;
	public $data;

	public function __construct( string $code, string $message, int $status = 0, $data = null ) {
		$this->code	   = $code;
		$this->message = $message;
		$this->status  = $status;
		$this->data	   = $data;
	}
}

/**
 * Class Porkbun_Client
 */
class Porkbun_Client {
	/**
	 * Base URL for Porkbun API.
	 *
	 * @var string
	 */
	private string $base_url = 'https://api.porkbun.com/api/json/v3/';

	/**
	 * API key.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Secret key.
	 *
	 * @var string
	 */
	private string $secret_key;

	/**
	 * Maximum number of retries.
	 */
	private int $max_retries = 5;

	/**
	 * Base delay in seconds for backoff.
	 */
	private float $base_delay = 1.0;

	/**
	 * Request timeout in seconds.
	 */
	private int $timeout = 20;

	/**
	 * Constructor.
	 */
	public function __construct( string $api_key, string $secret_key, ?string $base_url = null, int $timeout = 20 ) {
		$this->api_key	  = $api_key;
		$this->secret_key = $secret_key;
		$this->timeout    = $timeout;
		if ( null !== $base_url ) {
			$this->base_url = rtrim( $base_url, '/' ) . '/';
		}
	}

       /**
        * List domains with pagination.
        *
        * Porkbun's API returns up to 1000 domains per request and uses a
        * "start" offset for paging. The client performs slicing to honour the
        * requested per-page size while only fetching the necessary chunk.
        */
       public function list_domains( int $page = 1, int $per_page = 100 ) {
               $offset      = max( 0, ( $page - 1 ) * $per_page );
               $chunk_start = (int) ( floor( $offset / 1000 ) * 1000 );

               $result = $this->request( 'domain/listAll', [
                       'start'         => (string) $chunk_start,
                       'includeLabels' => 'yes',
               ] );

               if ( $result instanceof Porkbun_Client_Error ) {
                       return $result;
               }

               if ( isset( $result['domains'] ) && is_array( $result['domains'] ) ) {
                       $result['domains'] = array_slice( $result['domains'], $offset - $chunk_start, $per_page );
               }

               return $result;
       }

       /**
        * Retrieve nameservers for a domain.
        */
       public function get_nameservers( string $domain ) {
               $domain = strtolower( $domain );

               return $this->request( "domain/getNs/{$domain}", [] );
       }

       /**
        * Retrieve details for a single domain via listAll.
        *
        * Porkbun's API does not expose a dedicated endpoint for domain
        * details, so we fetch the full list and filter by domain name.
        *
        * @return array|Porkbun_Client_Error Domain info on success, error on failure.
        */
       public function get_domain_details( string $domain ) {
               $domain = strtolower( $domain );

               $result = $this->request( 'domain/listAll', [ 'start' => '0' ] );

               if ( $result instanceof Porkbun_Client_Error ) {
                       return $result;
               }

               foreach ( $result['domains'] ?? array() as $info ) {
                       if ( isset( $info['domain'] ) && strtolower( $info['domain'] ) === $domain ) {
                               return $info;
                       }
               }

               return array();
       }

       /**
        * Check whether a domain is available for registration.
        */
       public function check_domain( string $domain ) {
               $domain = strtolower( $domain );

               return $this->request( "domain/checkDomain/{$domain}", [] );
       }

        /**
         * Retrieve DNS records for a domain.
 */
        public function get_records( string $domain ) {
                return $this->request( "dns/retrieve/{$domain}", [] );
        }

       /**
        * Create a TXT record. Default TTL is 600 seconds.
        */
       public function create_txt_record( string $domain, string $name, string $content, int $ttl = 600 ) {
		$name    = sanitize_text_field( $name );
		$content = sanitize_text_field( $content );

		return $this->request( "dns/create/{$domain}", [
			'type'	  => 'TXT',
			'name'	  => $name,
			'content' => $content,
			'ttl'	  => $ttl,
		] );
	}

	/**
	 * Delete a TXT record by ID.
	 */
	public function delete_txt_record( string $domain, int $record_id ) {
		return $this->delete_record( $domain, $record_id );
	}

       /**
        * Create an A or AAAA record. Default TTL is 600 seconds.
        *
        * The {@see $name} parameter should be empty for apex records and contain
        * only the subdomain portion for others.
        *
        * Uses Porkbun's v3 DNS create endpoint: `/api/json/v3/dns/create/{domain}`.
        */
       public function create_a_record( string $domain, string $name, string $content, int $ttl = 600, string $type = 'A' ) {
               $name    = sanitize_text_field( $name );
               $content = sanitize_text_field( $content );

               // Hitting Porkbun v3 DNS create endpoint.
               return $this->request( "dns/create/{$domain}", [
                       'type'    => $type,
                       'name'    => $name,
                       'content' => $content,
                       'ttl'     => $ttl,
               ] );
       }

       /**
        * Retrieve a single DNS record by ID.
        */
       public function get_record( string $domain, int $record_id ) {
               return $this->request( "dns/retrieve/{$domain}/{$record_id}", [] );
       }

       /**
        * Create a DNS record of any type. Default TTL is 600 seconds.
        *
        * Accepted record types: A, AAAA, CNAME, TXT, MX, SRV, NS, PTR, CAA, ALIAS.
        * Optional fields:
        * - ttl (int): Time to live in seconds.
        * - prio (int): Priority for MX and SRV records.
        *
        * The {@see $name} parameter should be empty for apex records and contain
        * only the subdomain portion for others.
        *
        * Uses Porkbun's v3 DNS create endpoint: `/api/json/v3/dns/create/{domain}`.
        */
       public function create_record( string $domain, string $type, string $name, string $content, int $ttl = 600, ?int $prio = null ) {
               $type    = strtoupper( sanitize_text_field( $type ) );
               $name    = sanitize_text_field( $name );
               $content = sanitize_text_field( $content );

               $error = $this->validate_dns_args( $type, $content, $ttl, $prio );
               if ( $error instanceof Porkbun_Client_Error ) {
                       return $error;
               }

               $payload = [
                       'type'    => $type,
                       'name'    => $name,
                       'content' => $content,
                       'ttl'     => $ttl,
               ];

               if ( null !== $prio ) {
                       $payload['prio'] = $prio;
               }

               // Hitting Porkbun v3 DNS create endpoint.
               return $this->request( "dns/create/{$domain}", $payload );
       }

       /**
        * Edit a DNS record by ID. Default TTL is 600 seconds.
        *
        * Accepted record types: A, AAAA, CNAME, TXT, MX, SRV, NS, PTR, CAA, ALIAS.
        * Optional fields:
        * - ttl (int): Time to live in seconds.
        * - prio (int): Priority for MX and SRV records.
        */
       public function edit_record( string $domain, int $record_id, string $type, string $name, string $content, int $ttl = 600, ?int $prio = null ) {
               $type    = strtoupper( sanitize_text_field( $type ) );
               $name    = sanitize_text_field( $name );
               $content = sanitize_text_field( $content );

               $error = $this->validate_dns_args( $type, $content, $ttl, $prio );
               if ( $error instanceof Porkbun_Client_Error ) {
                       return $error;
               }

               $payload = [
                       'type'    => $type,
                       'name'    => $name,
                       'content' => $content,
                       'ttl'     => $ttl,
               ];

               if ( null !== $prio ) {
                       $payload['prio'] = $prio;
               }

               return $this->request( "dns/edit/{$domain}/{$record_id}", $payload );
       }

       /**
        * Validate DNS record arguments.
        *
        * @return Porkbun_Client_Error|null Error if validation fails, null otherwise.
        */
       private function validate_dns_args( string $type, string $content, $ttl, $prio = null ) {
               $allowed_types = array( 'A', 'AAAA', 'CNAME', 'TXT', 'MX', 'SRV', 'NS', 'PTR', 'CAA', 'ALIAS' );

               if ( ! in_array( $type, $allowed_types, true ) ) {
                       return new Porkbun_Client_Error( 'invalid_type', 'Invalid DNS record type.' );
               }

               if ( '' === trim( $content ) ) {
                       return new Porkbun_Client_Error( 'invalid_content', 'DNS record content cannot be empty.' );
               }

               if ( ! is_int( $ttl ) ) {
                       return new Porkbun_Client_Error( 'invalid_ttl', 'TTL must be an integer.' );
               }

               if ( null !== $prio && ! is_int( $prio ) ) {
                       return new Porkbun_Client_Error( 'invalid_prio', 'Priority must be an integer.' );
               }

               return null;
       }

       /**
        * Retrieve DNS records by subdomain and type.
        */
       public function retrieve_by_name_type( string $domain, string $subdomain, string $type ) {
                $subdomain = sanitize_text_field( $subdomain );

               return $this->request( "dns/retrieveByNameType/{$domain}/{$type}/{$subdomain}", [] );
       }

       /**
        * Edit or create a DNS record by subdomain and type.
        */
       public function edit_by_name_type( string $domain, string $subdomain, string $type, string $content, ?int $ttl = null ) {
                $subdomain = sanitize_text_field( $subdomain );
                $content   = sanitize_text_field( $content );

                $payload = [
                        'content' => $content,
                ];

                if ( null !== $ttl ) {
                        $payload['ttl'] = $ttl;
                }

               return $this->request( "dns/editByNameType/{$domain}/{$type}/{$subdomain}", $payload );
       }

       /**
        * Retrieve authoritative nameservers for a domain.
        */
       public function get_ns( string $domain ) {
               $domain = strtolower( $domain );
               return $this->request( "domain/getNs/{$domain}", [] );
       }

        /**
         * Delete a record by ID.
         */
        public function delete_record( string $domain, int $record_id ) {
                return $this->request( "dns/delete/{$domain}/{$record_id}", [] );
	}

	/**
	 * Perform API request with retries and backoff.
	 */
	protected function request( string $endpoint, array $payload, string $method = 'POST' ) {
		$url			  = $this->base_url . ltrim( $endpoint, '/' );
		$payload['apikey']		 = $this->api_key;
		$payload['secretapikey'] = $this->secret_key;
		$attempt		  = 0;

		while ( true ) {
			$attempt++;
			$response = $this->perform_http_request( $url, $payload, $method );
			$status	  = $response['status'];
			$body	  = $response['body'];

			if ( $status >= 200 && $status < 300 ) {
				$data = json_decode( $body, true );
				if ( isset( $data['status'] ) && 'SUCCESS' === $data['status'] ) {
					return $data;
				}
				$message = $data['message'] ?? 'API error';
				return new Porkbun_Client_Error( 'api_error', $message, $status, $data );
			}

			if ( ( 429 === $status || ( $status >= 500 && $status < 600 ) ) && $attempt < $this->max_retries ) {
				$delay = $this->calculate_backoff( $attempt );
				$this->sleep( $delay );
				continue;
			}

                        $data    = json_decode( $body, true );
                        $message = $body;
                        if ( is_array( $data ) && isset( $data['message'] ) ) {
                                $message = $data['message'];
                        }
                        $message = sprintf( 'HTTP %d: %s', $status, $message );

                        return new Porkbun_Client_Error( 'http_error', $message, $status, $data ?? $body );
                }
        }

        /**
         * Low-level HTTP request using WP HTTP API if available, falling back to cURL.
         */
        protected function perform_http_request( string $url, array $payload, string $method ): array {
                if ( function_exists( 'wp_remote_request' ) ) {
                        $args = [
                                'method'  => $method,
                                'headers' => [ 'Content-Type' => 'application/json' ],
                                'body'    => wp_json_encode( $payload ),
                                'timeout' => $this->timeout,
                        ];

                        if ( 'POST' === strtoupper( $method ) ) {
                                $response = wp_remote_post( $url, $args );
                        } else {
                                $response = wp_remote_request( $url, $args );
                        }

                        if ( is_wp_error( $response ) ) {
                                return [ 'status' => 0, 'body' => $response->get_error_message() ];
                        }

                        $status = wp_remote_retrieve_response_code( $response );
                        $body   = wp_remote_retrieve_body( $response );

                        return [ 'status' => $status, 'body' => $body ];
                }

                $ch = curl_init( $url );
                curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
                curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $method );
                curl_setopt( $ch, CURLOPT_HTTPHEADER, [ 'Content-Type: application/json' ] );
                curl_setopt( $ch, CURLOPT_TIMEOUT, $this->timeout );
                curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, $this->timeout );
                curl_setopt( $ch, CURLOPT_POSTFIELDS, ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload ) ) );
                $body = curl_exec( $ch );
                if ( false === $body ) {
                        $error = curl_error( $ch );
                        curl_close( $ch );
                        return [ 'status' => 0, 'body' => $error ];
                }
                $status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
                curl_close( $ch );
                return [ 'status' => $status, 'body' => $body ];
        }

	/**
	 * Calculate exponential backoff with jitter.
	 */
	protected function calculate_backoff( int $attempt ): float {
		$base = $this->base_delay * pow( 2, $attempt - 1 );
		return $base + $this->jitter( $base );
	}

	/**
	 * Generate jitter value.
	 */
	protected function jitter( float $base ): float {
		return mt_rand( 0, (int) ( $base * 1000 ) ) / 1000;
	}

	/**
	 * Sleep helper that accepts float seconds.
	 */
	protected function sleep( float $seconds ): void {
		usleep( (int) ( $seconds * 1000000 ) );
	}
       /**
        * Ping the Porkbun API.
        */
       public function ping() {
               return $this->request( 'ping', array() );
       }

       /**
        * Retrieve pricing information for a TLD.
        */
       public function get_pricing( string $tld ) {
               $tld = strtolower( sanitize_text_field( $tld ) );

               return $this->request( "pricing/get/{$tld}", array() );
       }

       /**
        * Update nameservers for a domain.
        */
       public function update_nameservers( string $domain, array $nameservers ) {
               $domain  = strtolower( $domain );
               $payload = array( 'ns' => array_values( array_map( 'sanitize_text_field', $nameservers ) ) );

               return $this->request( "domain/updateNs/{$domain}", $payload );
       }

       /**
        * Add a URL forward.
        */
       public function add_url_forward( string $domain, string $subdomain, string $destination, string $type = '302', bool $wildcard = false ) {
               $domain      = strtolower( $domain );
               $subdomain   = sanitize_text_field( $subdomain );
               $destination = sanitize_text_field( $destination );

               $payload = array(
                       'name'        => $subdomain,
                       'destination' => $destination,
                       'type'        => $type,
               );

               if ( $wildcard ) {
                       $payload['wildcard'] = '1';
               }

               return $this->request( "domain/addUrlForward/{$domain}", $payload );
       }

       /**
        * Retrieve URL forwarding configuration.
        */
       public function get_url_forwarding( string $domain ) {
               $domain = strtolower( $domain );

               return $this->request( "domain/getUrlForwarding/{$domain}", array() );
       }

       /**
        * Delete a URL forward by ID.
        */
       public function delete_url_forward( string $domain, int $id ) {
               $domain = strtolower( $domain );

               return $this->request( "domain/deleteUrlForward/{$domain}/{$id}", array() );
       }

       /**
        * Create a glue record.
        */
       public function create_glue_record( string $domain, string $hostname, array $ips ) {
               $domain   = strtolower( $domain );
               $hostname = sanitize_text_field( $hostname );
               $ips      = array_values( array_map( 'sanitize_text_field', $ips ) );

               return $this->request( "domain/createGlue/{$domain}", array( 'hostname' => $hostname, 'ips' => $ips ) );
       }

       /**
        * Update a glue record.
        */
       public function update_glue_record( string $domain, string $hostname, array $ips ) {
               $domain   = strtolower( $domain );
               $hostname = sanitize_text_field( $hostname );
               $ips      = array_values( array_map( 'sanitize_text_field', $ips ) );

               return $this->request( "domain/updateGlue/{$domain}/{$hostname}", array( 'ips' => $ips ) );
       }

       /**
        * Delete a glue record.
        */
       public function delete_glue_record( string $domain, string $hostname ) {
               $domain   = strtolower( $domain );
               $hostname = sanitize_text_field( $hostname );

               return $this->request( "domain/deleteGlue/{$domain}/{$hostname}", array() );
       }

       /**
        * Retrieve glue records for a domain.
        */
       public function get_glue_records( string $domain ) {
               $domain = strtolower( $domain );

               return $this->request( "domain/getGlue/{$domain}", array() );
       }

       /**
        * Delete DNS records by subdomain and type.
        */
       public function delete_by_name_type( string $domain, string $subdomain, string $type ) {
               $subdomain = sanitize_text_field( $subdomain );
               $type      = sanitize_text_field( $type );

               return $this->request( "dns/deleteByNameType/{$domain}/{$type}/{$subdomain}", array() );
       }

       /**
        * Create DNSSEC DS record.
        */
       public function create_dnssec( string $domain, string $key_tag, string $algorithm, string $digest_type, string $digest ) {
               $domain  = strtolower( $domain );
               $payload = array(
                       'keyTag'     => sanitize_text_field( $key_tag ),
                       'algorithm'  => sanitize_text_field( $algorithm ),
                       'digestType' => sanitize_text_field( $digest_type ),
                       'digest'     => sanitize_text_field( $digest ),
               );

               return $this->request( "dnssec/create/{$domain}", $payload );
       }

       /**
        * Retrieve DNSSEC DS records.
        */
       public function retrieve_dnssec( string $domain ) {
               $domain = strtolower( $domain );

               return $this->request( "dnssec/retrieve/{$domain}", array() );
       }

       /**
        * Delete DNSSEC DS records for a domain.
        */
       public function delete_dnssec( string $domain ) {
               $domain = strtolower( $domain );

               return $this->request( "dnssec/delete/{$domain}", array() );
       }

       /**
        * Retrieve SSL bundle for a domain.
        */
       public function retrieve_ssl_bundle( string $domain ) {
               $domain = strtolower( $domain );

               return $this->request( "ssl/retrieve/{$domain}", array() );
       }

}

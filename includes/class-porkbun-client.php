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
	 * Constructor.
	 */
	public function __construct( string $api_key, string $secret_key, ?string $base_url = null ) {
		$this->api_key	  = $api_key;
		$this->secret_key = $secret_key;
		if ( null !== $base_url ) {
			$this->base_url = rtrim( $base_url, '/' ) . '/';
		}
	}

	/**
	 * List domains with pagination.
	 */
        public function listDomains( int $page = 1, int $per_page = 100 ) {
                $start = max( 0, ( $page - 1 ) * $per_page );

                return $this->request( 'domain/listAll', [
                        'start' => (string) $start,
                ] );
        }

       /**
        * Disable a domain.
        */
       public function disableDomain( string $domain ) {
               return $this->request( "domain/disableDomain/{$domain}", [] );
       }

       /**
        * Delete a domain from Porkbun.
        */
       public function deleteDomain( string $domain ) {
               return $this->request( "domain/deleteDomain/{$domain}", [] );
       }

	/**
	 * Retrieve DNS records for a domain.
	 */
	public function getRecords( string $domain ) {
		return $this->request( "dns/retrieve/{$domain}", [] );
	}

	/**
	 * Create a TXT record.
	 */
	public function createTxtRecord( string $domain, string $name, string $content, int $ttl = 300 ) {
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
	public function deleteTxtRecord( string $domain, int $record_id ) {
		return $this->deleteRecord( $domain, $record_id );
	}

	/**
	 * Create an A record.
	 */
	public function createARecord( string $domain, string $name, string $content, int $ttl = 300 ) {
		return $this->request( "dns/create/{$domain}", [
			'type'	  => 'A',
			'name'	  => $name,
			'content' => $content,
			'ttl'	  => $ttl,
		] );
	}

	/**
	 * Delete a record by ID.
	 */
	public function deleteRecord( string $domain, int $record_id ) {
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
			$response = $this->performHttpRequest( $url, $payload, $method );
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
				$delay = $this->calculateBackoff( $attempt );
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
	 * Low-level HTTP request using cURL.
	 */
	protected function performHttpRequest( string $url, array $payload, string $method ): array {
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $method );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ 'Content-Type: application/json' ] );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $payload ) );
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
	protected function calculateBackoff( int $attempt ): float {
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
}

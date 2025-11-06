<?php
/**
 * Utilities for parsing fully qualified domain names.
 *
 * @package PorkPress\SSL
 */

namespace PorkPress\SSL;

defined( 'ABSPATH' ) || exit;

/**
 * Domain parsing helpers.
 */
class Domain_Parser {
	/**
	 * Split a fully qualified domain into zone and subdomain components.
	 *
	 * @param string        $fqdn       Fully qualified domain name.
	 * @param array<string> $known_roots Optional list of known registered domains.
	 * @param callable|null $validator   Optional validator that returns true when the provided
	 *                                   candidate zone is registered.
	 *
	 * @return array{zone:string,name:string}
	 */
	public static function split( string $fqdn, array $known_roots = array(), ?callable $validator = null ): array {
		$normalized = self::normalize_domain( $fqdn );
		if ( '' === $normalized ) {
			return array(
				'zone' => '',
				'name' => '',
			);
		}

		$parts = array_values( array_filter( explode( '.', $normalized ), static function ( $part ) {
			return '' !== $part;
		} ) );
		if ( count( $parts ) < 2 ) {
			return array(
				'zone' => $normalized,
				'name' => '',
			);
		}

		$root_index = self::index_roots( $known_roots );
		if ( empty( $root_index ) ) {
			$root_index = self::index_roots( self::load_roots_from_cache() );
		}

		$zone       = '';
		$part_count = count( $parts );
		for ( $i = 2; $i <= $part_count; $i++ ) {
			$candidate = implode( '.', array_slice( $parts, -$i ) );
			if ( isset( $root_index[ $candidate ] ) ) {
				$zone = $candidate;
				break;
			}
		}

		if ( '' === $zone && null !== $validator ) {
			for ( $i = $part_count; $i >= 2; $i-- ) {
				$candidate = implode( '.', array_slice( $parts, -$i ) );
				if ( self::validate_candidate( $validator, $candidate ) ) {
					$zone = $candidate;
					break;
				}
			}
		}

		if ( '' === $zone ) {
			$zone = implode( '.', array_slice( $parts, -2 ) );
		}

		if ( $zone === $normalized ) {
			return array(
				'zone' => $zone,
				'name' => '',
			);
		}

		$name = substr( $normalized, 0, -strlen( $zone ) );
		$name = rtrim( $name, '.' );

		return array(
			'zone' => $zone,
			'name' => $name,
		);
	}

	/**
	 * Extract registered domains from cached dataset.
	 *
	 * @param array<string,mixed> $data Cached domain dataset.
	 * @return array<string>
	 */
	public static function known_roots_from_dataset( array $data ): array {
		$roots = array();

		if ( isset( $data['root_domains'] ) && is_array( $data['root_domains'] ) ) {
			foreach ( $data['root_domains'] as $root ) {
				if ( is_string( $root ) ) {
					$roots[] = $root;
				} elseif ( is_array( $root ) && isset( $root['domain'] ) ) {
					$roots[] = $root['domain'];
				}
			}
		}

		if ( empty( $roots ) && isset( $data['domains'] ) && is_array( $data['domains'] ) ) {
			foreach ( $data['domains'] as $info ) {
				if ( is_string( $info ) ) {
					$roots[] = $info;
					continue;
				}
				if ( ! is_array( $info ) ) {
					continue;
				}
				if ( isset( $info['domain'] ) ) {
					$roots[] = $info['domain'];
				} elseif ( isset( $info['name'] ) ) {
					$roots[] = $info['name'];
				}
			}
		}

		return array_keys( self::index_roots( $roots ) );
	}

	/**
	 * Load known roots from the cached site option.
	 *
	 * @return array<string>
	 */
	private static function load_roots_from_cache(): array {
		if ( ! function_exists( 'get_site_option' ) ) {
			return array();
		}

		$cached = get_site_option( 'porkpress_ssl_domain_cache' );
		if ( ! is_array( $cached ) || ! isset( $cached['data'] ) || ! is_array( $cached['data'] ) ) {
			return array();
		}

		return self::known_roots_from_dataset( $cached['data'] );
	}

	/**
	 * Build an associative map of normalized root domains.
	 *
	 * @param array<string> $roots Roots to index.
	 * @return array<string, true>
	 */
	private static function index_roots( array $roots ): array {
		$index = array();
		foreach ( $roots as $root ) {
			$normalized = self::normalize_domain( $root );
			if ( '' === $normalized ) {
				continue;
			}
			$index[ $normalized ] = true;
		}

		return $index;
	}

	/**
	 * Normalize a domain string.
	 *
	 * @param string $domain Domain value.
	 * @return string
	 */
	private static function normalize_domain( $domain ): string {
		if ( ! is_string( $domain ) ) {
			return '';
		}

		$domain = strtolower( trim( $domain ) );
		$domain = trim( $domain, ". 	\n\r\0\x0B" );

		return $domain;
	}

	/**
	 * Invoke validator safely.
	 *
	 * @param callable $validator Validator callback.
	 * @param string   $candidate Candidate zone.
	 * @return bool
	 */
	private static function validate_candidate( callable $validator, string $candidate ): bool {
		try {
			return (bool) $validator( $candidate );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}

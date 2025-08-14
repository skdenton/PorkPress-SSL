<?php
/**
 * Domain service.
 *
 * @package PorkPress\SSL
 */

namespace PorkPress\SSL;

require_once __DIR__ . '/class-runner.php';

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
 * Whether the service is in dry-run mode.
 */
protected bool $dry_run = false;

/**
 * Cached domain list for the current request.
 *
 * @var array|null
 */
protected ?array $domain_list_cache = null;

private const DOMAIN_CACHE_OPTION = 'porkpress_ssl_domain_cache';
private const DOMAIN_LIST_MAX_PAGES = 100;

private const DNS_PROPAGATION_OPTION = 'porkpress_ssl_dns_propagation';

       /**
        * Constructor.
        *
        * @param Porkbun_Client|null $client  Optional client instance.
        * @param bool|null           $dry_run Force dry-run mode.
        */
       public function __construct( ?Porkbun_Client $client = null, ?bool $dry_run = null ) {
               $this->dry_run = $dry_run ?? (bool) get_site_option( 'porkpress_ssl_dry_run', 0 );

               $api_key    = defined( 'PORKPRESS_API_KEY' ) ? PORKPRESS_API_KEY : get_site_option( 'porkpress_ssl_api_key', '' );
               $api_secret = defined( 'PORKPRESS_API_SECRET' ) ? PORKPRESS_API_SECRET : get_site_option( 'porkpress_ssl_api_secret', '' );
		$timeout   = max( 1, (int) get_site_option( 'porkpress_ssl_api_timeout', 20 ) );

               $this->missing_credentials = empty( $api_key ) || empty( $api_secret );
               if ( $this->dry_run ) {
                       $this->missing_credentials = false;
               } elseif ( $this->missing_credentials ) {
                       Notifier::notify(
                               'error',
                               __( 'Porkbun API credentials missing', 'porkpress-ssl' ),
                               __( 'Add your Porkbun API key and secret in the Settings tab or via constants.', 'porkpress-ssl' )
                       );
               }

               if ( $client ) {
                       $this->client = $client;
               } else {
                       $this->client = $this->dry_run
                               ? new Porkbun_Client_DryRun( $api_key, $api_secret, null, $timeout )
                               : new Porkbun_Client( $api_key, $api_secret, null, $timeout );
               }
       }

       /**
        * Whether the service is in dry-run mode.
        */
       public function is_dry_run(): bool {
               return $this->dry_run;
       }

       /**
        * Retrieve recorded plan steps when in dry-run mode.
        *
        * @return array<int, array<string, mixed>>
        */
       public function get_plan(): array {
               return $this->client instanceof Porkbun_Client_DryRun ? $this->client->plan : array();
       }

        /**
         * Whether the service has the required API credentials.
         */
       public function has_credentials(): bool {
               return ! $this->missing_credentials;
       }

       /**
        * Perform a DNS health check for a domain.
        *
        * Resolves A, AAAA and CNAME records and verifies they point to the
        * expected host or IP of this network. If a mismatch is detected, a
        * WP_Error is returned with a descriptive message.
        *
        * @param string $domain Domain name to check.
        *
        * @return true|\WP_Error True if healthy, error on mismatch or lookup failure.
        */
       public function check_dns_health( string $domain ) {
               $home = '';
               if ( function_exists( 'network_home_url' ) ) {
                       $home = network_home_url();
               } elseif ( function_exists( 'home_url' ) ) {
                       $home = home_url();
               }

               if ( ! $home ) {
                       return true;
               }

               $parse        = function_exists( 'wp_parse_url' ) ? 'wp_parse_url' : 'parse_url';
               $expected_host = (string) $parse( $home, PHP_URL_HOST );

               if ( ! $expected_host ) {
                       return true;
               }

               $expected_ipv4 = array();
               $expected_ipv6 = array();
               if ( function_exists( 'get_site_option' ) ) {
                       $v4 = trim( (string) get_site_option( 'porkpress_ssl_ipv4_override', '' ) );
                       if ( '' !== $v4 ) {
                               $expected_ipv4 = array_filter( preg_split( '/[,\s]+/', $v4 ) );
                       }
                       $v6 = trim( (string) get_site_option( 'porkpress_ssl_ipv6_override', '' ) );
                       if ( '' !== $v6 ) {
                               $expected_ipv6 = array_filter( preg_split( '/[,\s]+/', $v6 ) );
                       }
               }

               if ( empty( $expected_ipv4 ) && function_exists( 'gethostbynamel' ) ) {
                       $expected_ipv4 = (array) gethostbynamel( $expected_host );
               }
               if ( empty( $expected_ipv6 ) && $this->has_dns_get_record() ) {
                       $ipv6_records = dns_get_record( $expected_host, DNS_AAAA );
                       if ( is_array( $ipv6_records ) ) {
                               foreach ( $ipv6_records as $r ) {
                                       if ( ! empty( $r['ipv6'] ) ) {
                                               $expected_ipv6[] = $r['ipv6'];
                                       }
                               }
                       }
               }

               $degraded = false;
               $records  = $this->fetch_dns_records( $domain, $degraded );
               if ( empty( $records ) ) {
                       return $degraded ? true : new \WP_Error( 'dns_lookup_failed', __( 'DNS lookup failed or returned no records.', 'porkpress-ssl' ) );
               }

               $found_a      = array();
               $found_aaaa   = array();
               $cname_target = '';
               foreach ( $records as $record ) {
                       switch ( $record['type'] ) {
                               case 'A':
                                       $found_a[] = $record['ip'];
                                       break;
                               case 'AAAA':
                                       $found_aaaa[] = $record['ipv6'];
                                       break;
                               case 'CNAME':
                                       $cname_target = rtrim( $record['target'], '.' );
                                       break;
                       }
               }

               if ( $cname_target && $cname_target !== $expected_host ) {
                       return new \WP_Error(
                               'dns_mismatch',
                               sprintf(
                                       __( 'Domain %1$s CNAMEs to %2$s instead of %3$s.', 'porkpress-ssl' ),
                                       $domain,
                                       $cname_target,
                                       $expected_host
                               )
                       );
               }

               if ( empty( $found_a ) ) {
                       $this->record_dns_propagation_issue( $domain, $found_a, $expected_ipv4, $found_aaaa, $expected_ipv6 );
                       return new \WP_Error( 'dns_missing_a_record', sprintf( __( 'Domain %s has no A record.', 'porkpress-ssl' ), $domain ) );
               }

               if ( $expected_ipv4 && empty( array_intersect( $found_a, $expected_ipv4 ) ) ) {
                       $this->record_dns_propagation_issue( $domain, $found_a, $expected_ipv4, $found_aaaa, $expected_ipv6 );
                       return new \WP_Error( 'dns_mismatch', sprintf( __( 'Domain %s does not point to expected IPv4 address.', 'porkpress-ssl' ), $domain ) );
               }

               if ( $expected_ipv6 && $found_aaaa && empty( array_intersect( $found_aaaa, $expected_ipv6 ) ) ) {
                       $this->record_dns_propagation_issue( $domain, $found_a, $expected_ipv4, $found_aaaa, $expected_ipv6 );
                       return new \WP_Error( 'dns_mismatch', sprintf( __( 'Domain %s does not point to expected IPv6 address.', 'porkpress-ssl' ), $domain ) );
               }

               $this->clear_dns_propagation_issue( $domain );

               return true;
       }

       protected function has_dns_get_record(): bool {
               return function_exists( 'dns_get_record' );
       }

       protected function fetch_dns_records( string $domain, bool &$degraded = false ): array {
               if ( $this->has_dns_get_record() ) {
                       $records = dns_get_record( $domain, DNS_A | DNS_AAAA | DNS_CNAME );
                       return is_array( $records ) ? $records : array();
               }

               $records = $this->dig_dns_records( $domain );
               if ( empty( $records ) ) {
                       $degraded = true;
               }
               return $records;
       }

       protected function dig_dns_records( string $domain ): array {
               $ns_result = $this->client->get_ns( $domain );
               $nameservers = array();
               if ( is_array( $ns_result ) && isset( $ns_result['ns'] ) && is_array( $ns_result['ns'] ) ) {
                       $nameservers = $ns_result['ns'];
               }
               if ( empty( $nameservers ) ) {
                       return array();
               }

               $lookup = function ( string $type ) use ( $domain, $nameservers ): array {
                       $results = array();
                       $d = escapeshellarg( $domain );
                       foreach ( $nameservers as $ns ) {
                               $ns_esc = escapeshellarg( $ns );
                               $cmd    = sprintf( 'dig +short %s %s @%s', $d, $type, $ns_esc );
                               $result = Runner::run( $cmd );
                               if ( $result['output'] ) {
                                       $lines = preg_split( '/\s+/', trim( $result['output'] ) );
                                       foreach ( $lines as $line ) {
                                               if ( '' !== $line ) {
                                                       $results[] = rtrim( $line, '.' );
                                               }
                                       }
                               }
                       }
                       return array_unique( $results );
               };

               $records = array();
               foreach ( $lookup( 'A' ) as $ip ) {
                       $records[] = array( 'type' => 'A', 'ip' => $ip );
               }
               foreach ( $lookup( 'AAAA' ) as $ip ) {
                       $records[] = array( 'type' => 'AAAA', 'ipv6' => $ip );
               }
               foreach ( $lookup( 'CNAME' ) as $target ) {
                       $records[] = array( 'type' => 'CNAME', 'target' => $target );
               }

               return $records;
       }

       protected function record_dns_propagation_issue( string $domain, array $found_a, array $expected_ipv4, array $found_aaaa, array $expected_ipv6 ): void {
               Logger::warn( 'dns_propagation_pending', array(
                       'domain'        => $domain,
                       'found_a'       => $found_a,
                       'expected_ipv4' => $expected_ipv4,
                       'found_aaaa'    => $found_aaaa,
                       'expected_ipv6' => $expected_ipv6,
               ) );

               if ( ! function_exists( 'get_site_option' ) || ! function_exists( 'update_site_option' ) ) {
                       return;
               }

               $failures = get_site_option( self::DNS_PROPAGATION_OPTION, array() );
               $now      = time();
		$timeout  = (int) get_site_option( 'porkpress_ssl_dns_timeout', 900 );

               if ( ! isset( $failures[ $domain ] ) ) {
                       $failures[ $domain ] = $now;
                       update_site_option( self::DNS_PROPAGATION_OPTION, $failures );
                       return;
               }

               if ( $now - (int) $failures[ $domain ] > $timeout ) {
                       Notifier::notify( 'warning', __( 'DNS propagation delay', 'porkpress-ssl' ), sprintf( __( 'Domain %s DNS records have not propagated after %d seconds.', 'porkpress-ssl' ), $domain, $timeout ) );
                       $failures[ $domain ] = $now;
               }

               update_site_option( self::DNS_PROPAGATION_OPTION, $failures );
       }

       protected function clear_dns_propagation_issue( string $domain ): void {
               if ( ! function_exists( 'get_site_option' ) || ! function_exists( 'update_site_option' ) ) {
                       return;
               }
               $failures = get_site_option( self::DNS_PROPAGATION_OPTION, array() );
               if ( isset( $failures[ $domain ] ) ) {
                       unset( $failures[ $domain ] );
                       update_site_option( self::DNS_PROPAGATION_OPTION, $failures );
               }
       }

        /**
         * List domains.
         *
         * @param int $page     Page number.
        * @param int $per_page Domains per page.
        *
        * @return array|Porkbun_Client_Error
        */
    /**
     * Clear the domain list cache.
     */
    protected function clear_domain_cache(): void {
        $this->domain_list_cache = null;
        if ( function_exists( 'delete_site_transient' ) ) {
            delete_site_transient( self::DOMAIN_LIST_CACHE_KEY );
        }
    }

    /**
     * Queue issuance for a site, accounting for network wildcards.
     *
     * If the network wildcard option is enabled and the provided domain is a
     * subdomain of {@see DOMAIN_CURRENT_SITE}, the main network certificate is
     * queued instead of the specific site.
     *
     * @param int    $site_id Site ID.
     * @param string $domain  Domain name.
     */
    protected function queue_wildcard_aware_issuance( int $site_id, string $domain ): void {
        // Skip issuance for internal network subdomains unless explicitly marked external.
        if ( $this->is_internal_subdomain( $site_id, $domain ) ) {
            return;
        }

        if ( function_exists( 'get_site_option' ) && defined( 'DOMAIN_CURRENT_SITE' ) ) {
            if ( get_site_option( 'porkpress_ssl_network_wildcard', 0 ) ) {
                $suffix = '.' . DOMAIN_CURRENT_SITE;
                if ( substr( $domain, -strlen( $suffix ) ) === $suffix ) {
                    SSL_Service::queue_issuance( 0 );
                    return;
                }
            }
        }
        SSL_Service::queue_issuance( $site_id );
    }

    /**
     * Determine whether a domain is an internal subdomain of the network.
     *
     * Internal subdomains are those that match the site's default domain under
     * the network's primary domain. Sites can opt-out by setting the
     * `porkpress_ssl_external` site meta flag.
     */
    public function is_internal_subdomain( int $site_id, string $domain ): bool {
        if ( ! defined( 'DOMAIN_CURRENT_SITE' ) || ! function_exists( 'get_site' ) ) {
            return false;
        }

        $site = get_site( $site_id );
        if ( ! $site ) {
            return false;
        }

        $domain   = strtolower( $domain );
        $site_dom = strtolower( $site->domain );
        if ( $domain !== $site_dom ) {
            return false;
        }

        $suffix = '.' . DOMAIN_CURRENT_SITE;
        if ( substr( $domain, -strlen( $suffix ) ) !== $suffix ) {
            return false;
        }

        if ( function_exists( 'get_site_meta' ) ) {
            $external = get_site_meta( $site_id, 'porkpress_ssl_external', true );
            if ( $external ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Retrieve cached domain list.
     *
     * @return array|Porkbun_Client_Error
     */
    public function list_domains() {
        if ( null !== $this->domain_list_cache ) {
            return $this->domain_list_cache;
        }

        if ( function_exists( 'get_site_option' ) ) {
            $cached = get_site_option( self::DOMAIN_CACHE_OPTION );
            if ( is_array( $cached ) && isset( $cached['data'] ) ) {
                $this->domain_list_cache = $cached['data'];
                return $this->domain_list_cache;
            }
        }

        return new Porkbun_Client_Error(
            'no_cache',
            __( 'Domain list not cached. Please refresh in the Domains tab.', 'porkpress-ssl' )
        );
    }

    /**
     * Refresh domain list from Porkbun and store in cache.
     * Includes DNS records for each root domain.
     *
     * @param int $page     Page number to start from.
     * @param int $per_page Domains per page.
     *
     * @return array|Porkbun_Client_Error
     */
    public function refresh_domains( int $page = 1, int $per_page = 100 ) {
        $status       = 'SUCCESS';
        $all_domains  = array();
        $current_page = $page;
        $page_count   = 0;
        $page_hashes  = array();

        do {
            $page_count++;
            if ( $page_count > self::DOMAIN_LIST_MAX_PAGES ) {
                return new Porkbun_Client_Error(
                    'page_limit_exceeded',
                    sprintf(
                        __( 'Exceeded maximum page count of %d while listing domains.', 'porkpress-ssl' ),
                        self::DOMAIN_LIST_MAX_PAGES
                    )
                );
            }

            $result = $this->client->list_domains( $current_page, $per_page );

            if ( $result instanceof Porkbun_Client_Error ) {
                Notifier::notify( 'error', __( 'Porkbun API error', 'porkpress-ssl' ), $result->message );
                return $result;
            }

            if ( isset( $result['status'] ) ) {
                $status = $result['status'];
            }

            $domains = isset( $result['domains'] ) && is_array( $result['domains'] ) ? $result['domains'] : array();
            $domains = array_map(
                function ( $domain ) {
                    if ( isset( $domain['tld'] ) && ! isset( $domain['type'] ) ) {
                        $domain['type'] = $domain['tld'];
                    }
                    if ( isset( $domain['expireDate'] ) && ! isset( $domain['expiry'] ) ) {
                        $domain['expiry'] = $domain['expireDate'];
                    }

                    return $domain;
                },
                $domains
            );

            $current_hash = md5( json_encode( $domains ) );
            if ( in_array( $current_hash, $page_hashes, true ) ) {
                return new Porkbun_Client_Error(
                    'duplicate_page',
                    __( 'Duplicate page response detected while listing domains.', 'porkpress-ssl' )
                );
            }
            $page_hashes[] = $current_hash;

            $all_domains = array_merge( $all_domains, $domains );
            $current_page++;
        } while ( ! empty( $domains ) );

        $extra = array();
        foreach ( $all_domains as &$domain_info ) {
            $root = $domain_info['domain'] ?? $domain_info['name'] ?? '';
            if ( ! $root ) {
                $domain_info['dns'] = array();
                continue;
            }

            $records = $this->client->get_records( $root );
            if ( $records instanceof Porkbun_Client_Error ) {
                $domain_info['dns']         = array();
                $domain_info['nameservers'] = array();
                $domain_info['details']     = array();
                continue;
            }

            $domain_info['dns'] = $records['records'] ?? array();

            $detail = $this->client->get_domain( $root );
            if ( $detail instanceof Porkbun_Client_Error ) {
                $domain_info['nameservers'] = array();
                $domain_info['details']     = array();
            } else {
                $info                        = $detail['domain'] ?? $detail;
                $domain_info['details']      = $info;
                $domain_info['nameservers']  = $info['ns'] ?? array();
            }

            $seen = array();
            foreach ( $domain_info['dns'] as $rec ) {
                $name = $rec['name'] ?? '';
                if ( '' === $name || '@' === $name ) {
                    continue;
                }
                $fqdn = $name . '.' . $root;
                $key  = strtolower( $fqdn );
                if ( isset( $seen[ $key ] ) ) {
                    continue;
                }
                $seen[ $key ] = true;
                $extra[]      = array(
                    'domain'      => $fqdn,
                    'status'      => $domain_info['status'] ?? $domain_info['dnsstatus'] ?? '',
                    'expiry'      => $domain_info['expiry'] ?? $domain_info['expiration'] ?? $domain_info['exdate'] ?? '',
                    'dns'         => array( $rec ),
                    'nameservers' => $domain_info['nameservers'] ?? array(),
                    'details'     => $domain_info['details'] ?? array(),
                );
            }
        }
        unset( $domain_info );

        $final = array(
            'status'       => $status,
            'root_domains' => $all_domains,
            'domains'      => array_merge( $all_domains, $extra ),
        );

        $this->domain_list_cache = $final;

        if ( function_exists( 'update_site_option' ) ) {
            update_site_option( self::DOMAIN_CACHE_OPTION, array(
                'data'      => $final,
                'timestamp' => time(),
            ) );
        }

        return $final;
    }

    /**
     * Get timestamp of last domain refresh.
     */
    public function get_last_refresh(): int {
        if ( function_exists( 'get_site_option' ) ) {
            $cached = get_site_option( self::DOMAIN_CACHE_OPTION );
            if ( is_array( $cached ) && isset( $cached['timestamp'] ) ) {
                return (int) $cached['timestamp'];
            }
        }
        return 0;
    }

    /**
     * Check domain availability via Porkbun.
     *
     * @param string $domain Domain name.
     * @return array|Porkbun_Client_Error
     */
    public function check_domain( string $domain ) {
        $result = $this->client->check_domain( $domain );
        if ( $result instanceof Porkbun_Client_Error ) {
            Notifier::notify( 'error', __( 'Porkbun API error', 'porkpress-ssl' ), $result->message );
        }
        return $result;
    }

       /**
        * Attach a domain to a site.
        *
        * @param string   $domain Domain name.
        * @param int      $site_id Site ID.
        * @param int|null $ttl     Optional TTL override.
        *
        * @return bool|Porkbun_Client_Error|\WP_Error
        */
       public function attach_to_site( string $domain, int $site_id, ?int $ttl = null ) {
               $skip_check = function_exists( 'apply_filters' )
                       ? apply_filters( 'porkpress_ssl_skip_dns_check', false, $domain, $site_id )
                       : false;

               if ( function_exists( 'update_site_meta' ) ) {
                       update_site_meta( $site_id, 'porkpress_domain', $domain );
               }

               $ttl = $ttl ?? 600;
               if ( function_exists( 'apply_filters' ) ) {
                       $ttl = (int) apply_filters( 'porkpress_ssl_a_record_ttl', $ttl, $domain, $site_id );
               }

               $result = $this->add_alias( $site_id, $domain, true, 'active', $ttl );
               if ( $result instanceof Porkbun_Client_Error ) {
                       return $result;
               }

               if ( ! $skip_check ) {
                       $check = $this->check_dns_health( $domain );
                       if ( $check instanceof \WP_Error ) {
                               return $check;
                       }
               }

               return true;
       }

       /**
        * Create a new site and attach a domain as its primary alias.
        *
        * @param string $domain       Domain to attach.
        * @param string $title        Site title.
        * @param string $admin_email  Administrator email for the site.
        * @param string $template     Optional template/locale for the site.
        *
        * @return int|\WP_Error Site ID on success or WP_Error on failure.
        */
       public function create_site( string $domain, string $title, string $admin_email, string $template = '' ) {
               $domain = sanitize_text_field( $domain );
               $domain = $this->validate_fqdn( $domain );
               if ( false === $domain ) {
                       return new \WP_Error( 'invalid_domain', __( 'Invalid domain name.', 'porkpress-ssl' ) );
               }

               \PorkPress\SSL\Logger::info(
                       'create_site_start',
                       array(
                               'domain'           => $domain,
                               'title'            => $title,
                               'admin_email_hash' => hash( 'sha256', strtolower( trim( $admin_email ) ) ),
                               'template'         => $template,
                       )
               );

               $user_id = email_exists( $admin_email );
               if ( ! $user_id ) {
                       $username = sanitize_user( current( explode( '@', $admin_email ) ), true );
                       $password = wp_generate_password();
                       $user_id  = wp_create_user( $username, $password, $admin_email );
                       if ( is_wp_error( $user_id ) ) {
                               \PorkPress\SSL\Logger::error( 'create_user', array( 'email' => $admin_email ), $user_id->get_error_message() );
                               return $user_id;
                       }
                       \PorkPress\SSL\Logger::info( 'create_user', array( 'user_id' => $user_id, 'email' => $admin_email ), 'created' );
               } else {
                       \PorkPress\SSL\Logger::info( 'create_user', array( 'user_id' => $user_id, 'email' => $admin_email ), 'existing' );
               }

               $site_id = wpmu_create_blog( $domain, '/', $title, $user_id, array( 'template' => $template ), get_current_network_id() );
               if ( is_wp_error( $site_id ) ) {
                       \PorkPress\SSL\Logger::error( 'create_site', array( 'domain' => $domain ), $site_id->get_error_message() );
                       return $site_id;
               }

               \PorkPress\SSL\Logger::info( 'create_site', array( 'domain' => $domain, 'site_id' => $site_id ), 'created' );

               if ( function_exists( 'update_site_meta' ) ) {
                       update_site_meta( $site_id, 'porkpress_domain', $domain );
               }

               $this->add_alias( $site_id, $domain, true, 'active' );
               \PorkPress\SSL\Logger::info( 'set_primary_domain', array( 'site_id' => $site_id, 'domain' => $domain ), 'alias_created' );

               $url = 'https://' . $domain;
               update_blog_option( $site_id, 'siteurl', $url );
               update_blog_option( $site_id, 'home', $url );
               \PorkPress\SSL\Logger::info( 'update_siteurl_home', array( 'site_id' => $site_id, 'url' => $url ), 'updated' );

               return $site_id;
       }

       /**
        * Detach a domain from any site.
        *
        * @param string $domain   Domain name.
        * @param bool   $override Whether to override content checks.
        *
        * @return bool|Porkbun_Client_Error|\WP_Error True on success, error on failure.
        */
       public function detach_from_site( string $domain, bool $override = false ) {
               if ( function_exists( 'get_sites' ) && function_exists( 'delete_site_meta' ) ) {
                       $sites = get_sites( array( 'meta_key' => 'porkpress_domain', 'meta_value' => $domain ) );
                       foreach ( $sites as $site ) {
                               if ( ! $override && $this->site_has_content( (int) $site->blog_id ) ) {
                                       return new \WP_Error( 'site_not_empty', __( 'Site has content. Type CONFIRM to detach.', 'porkpress-ssl' ) );
                               }
                               delete_site_meta( $site->blog_id, 'porkpress_domain', $domain );
                               $result = $this->delete_alias( $site->blog_id, $domain );
                               if ( $result instanceof Porkbun_Client_Error ) {
                                       return $result;
                               }
                       }
               }

               return true;
       }

       /**
        * Create an A record pointing the domain to the network IP.
        *
        * @param string $domain  Domain name.
        * @param int    $site_id Site ID.
        * @param int    $ttl     TTL for the record.
        *
        * @return bool|Porkbun_Client_Error
        */
       protected function create_a_record( string $domain, int $site_id, int $ttl ) {
               $ipv4 = $this->get_network_ip();
               $ipv6 = $this->get_network_ipv6();

               if ( ! $ipv4 && ! $ipv6 ) {
                       return true;
               }

               if ( $ipv4 ) {
                       $result = $this->ensure_dns_record( $domain, '', $ipv4, $ttl, 'A', $site_id );
                       if ( $result instanceof Porkbun_Client_Error ) {
                               return $result;
                       }
               }

               if ( $ipv6 ) {
                       $result = $this->ensure_dns_record( $domain, '', $ipv6, $ttl, 'AAAA', $site_id );
                       if ( $result instanceof Porkbun_Client_Error ) {
                               return $result;
                       }
               }

               $cname = $this->ensure_www_cname( $domain, $ttl );
               if ( $cname instanceof Porkbun_Client_Error ) {
                       return $cname;
               }

               return true;
       }

       /**
        * Ensure a DNS record exists with the desired content.
        */
       protected function ensure_dns_record( string $domain, string $name, string $content, int $ttl, string $type, int $site_id ) {
               if ( ! isset( $this->client ) || ! method_exists( $this->client, 'retrieve_by_name_type' ) || ! method_exists( $this->client, 'edit_by_name_type' ) ) {
                       if ( isset( $this->client ) && method_exists( $this->client, 'create_a_record' ) ) {
                               return $this->client->create_a_record( $domain, $name, $content, $ttl, $type );
                       }
                       return true;
               }

               try {
                       $existing = $this->client->retrieve_by_name_type( $domain, $name, $type );
               } catch ( \Throwable $e ) {
                       $existing = new Porkbun_Client_Error( 'client_error', $e->getMessage() );
               }
               if ( $existing instanceof Porkbun_Client_Error ) {
                       if ( isset( $this->client ) && method_exists( $this->client, 'create_a_record' ) ) {
                               $result = $this->client->create_a_record( $domain, $name, $content, $ttl, $type );
                               if ( $result instanceof Porkbun_Client_Error ) {
                                       \PorkPress\SSL\Logger::error(
                                               'create_a_record',
                                               array(
                                                       'domain' => $domain,
                                                       'site_id' => $site_id,
                                                       'type' => $type,
                                                       'name' => $name,
                                                       'ttl' => $ttl,
                                               ),
                                               $result->message
                                       );
                               }
                               return $result;
                       }
                       \PorkPress\SSL\Logger::error(
                               'create_a_record',
                               array(
                                       'domain' => $domain,
                                       'site_id' => $site_id,
                                       'type' => $type,
                                       'name' => $name,
                                       'ttl' => $ttl,
                               ),
                               $existing->message
                       );
                       return $existing;
               }

               if ( is_array( $existing ) && ! empty( $existing['records'] ) ) {
                       $result = $this->client->edit_by_name_type( $domain, $name, $type, $content, $ttl );
               } else {
                       $result = $this->client->create_a_record( $domain, $name, $content, $ttl, $type );
               }
               if ( $result instanceof Porkbun_Client_Error ) {
                       \PorkPress\SSL\Logger::error(
                               'create_a_record',
                               array(
                                       'domain' => $domain,
                                       'site_id' => $site_id,
                                       'type' => $type,
                                       'name' => $name,
                                       'ttl' => $ttl,
                               ),
                               $result->message
                       );
                       return $result;
               }

               return true;
       }

       /**
        * Ensure a www CNAME exists.
        */
       protected function ensure_www_cname( string $domain, int $ttl ) {
               // Only create a www CNAME for apex domains.
               if ( substr_count( $domain, '.' ) > 1 ) {
                       return true;
               }

               if ( ! isset( $this->client ) || ! method_exists( $this->client, 'retrieve_by_name_type' ) || ! method_exists( $this->client, 'edit_by_name_type' ) ) {
                       return true;
               }

               try {
                       $this->client->retrieve_by_name_type( $domain, 'www', 'CNAME' );
               } catch ( \Throwable $e ) {
                       return true;
               }

               return $this->ensure_dns_record( $domain, 'www', $domain, $ttl, 'CNAME', 0 );
       }

       /**
        * Remove A/AAAA records pointing to the network IPs.
        *
        * @param string $domain  Domain name.
        * @param int    $site_id Site ID.
        *
        * @return bool|Porkbun_Client_Error
        */
       protected function delete_a_record( string $domain, int $site_id ) {
               $ipv4 = $this->get_network_ip();
               $ipv6 = $this->get_network_ipv6();

               $records = $this->client->get_records( $domain );
               if ( $records instanceof Porkbun_Client_Error ) {
                       \PorkPress\SSL\Logger::error( 'delete_a_record', array( 'domain' => $domain, 'site_id' => $site_id ), $records->message );
                       return $records;
               }

               foreach ( $records['records'] ?? array() as $rec ) {
                       $name = $rec['name'] ?? '';
                       if ( '' !== $name && '@' !== $name ) {
                               continue;
                       }
                       $type    = $rec['type'] ?? '';
                       $content = $rec['content'] ?? '';

                       if ( ( 'A' === $type && $ipv4 && $content === $ipv4 ) || ( 'AAAA' === $type && $ipv6 && $content === $ipv6 ) ) {
                               $del = $this->client->delete_record( $domain, (int) $rec['id'] );
                               if ( $del instanceof Porkbun_Client_Error ) {
                                       \PorkPress\SSL\Logger::error( 'delete_a_record', array( 'domain' => $domain, 'site_id' => $site_id, 'record_id' => $rec['id'] ), $del->message );
                                       return $del;
                               }
                       }
               }

               return true;
       }

       /**
        * Detect the network's IP address.
        *
        * @return string IPv4 address or empty string if unresolved.
        */
       protected function get_network_ip(): string {
               if ( function_exists( 'get_site_option' ) ) {
                       $override = trim( (string) get_site_option( 'porkpress_ssl_ipv4_override', '' ) );
                       if ( '' !== $override ) {
                               return $override;
                       }
               }

               $home = '';
               if ( function_exists( 'network_home_url' ) ) {
                       $home = network_home_url();
               } elseif ( function_exists( 'home_url' ) ) {
                       $home = home_url();
               }

               if ( ! $home ) {
                       return '';
               }

               $parse_fn = function_exists( 'wp_parse_url' ) ? 'wp_parse_url' : 'parse_url';
               $host     = (string) $parse_fn( $home, PHP_URL_HOST );
               if ( ! $host ) {
                       return '';
               }

               if ( function_exists( 'gethostbynamel' ) ) {
                       $ips = gethostbynamel( $host );
                       if ( is_array( $ips ) && ! empty( $ips ) ) {
                               return (string) $ips[0];
                       }
               } elseif ( function_exists( 'gethostbyname' ) ) {
                       $ip = gethostbyname( $host );
                       if ( $ip !== $host ) {
                               return $ip;
                       }
               }

               return '';
       }

       /**
        * Detect the network's IPv6 address.
        */
       protected function get_network_ipv6(): string {
               if ( function_exists( 'get_site_option' ) ) {
                       $override = trim( (string) get_site_option( 'porkpress_ssl_ipv6_override', '' ) );
                       if ( '' !== $override ) {
                               return $override;
                       }
               }

               $home = '';
               if ( function_exists( 'network_home_url' ) ) {
                       $home = network_home_url();
               } elseif ( function_exists( 'home_url' ) ) {
                       $home = home_url();
               }

               if ( ! $home ) {
                       return '';
               }

               $parse_fn = function_exists( 'wp_parse_url' ) ? 'wp_parse_url' : 'parse_url';
               $host     = (string) $parse_fn( $home, PHP_URL_HOST );
               if ( ! $host ) {
                       return '';
               }

               if ( function_exists( 'dns_get_record' ) ) {
                       $records = dns_get_record( $host, DNS_AAAA );
                       if ( is_array( $records ) ) {
                               foreach ( $records as $r ) {
                                       if ( ! empty( $r['ipv6'] ) ) {
                                               return (string) $r['ipv6'];
                                       }
                               }
                       }
               }

               return '';
       }

/**
 * Determine whether a site has content.
 *
 * @param int $site_id Site ID.
 *
 * @return bool
 */
protected function site_has_content( int $site_id ): bool {
               if ( ! function_exists( 'switch_to_blog' ) || ! function_exists( 'restore_current_blog' ) || ! function_exists( 'get_posts' ) ) {
                       return false;
               }

               switch_to_blog( $site_id );
               $posts = get_posts(
                       array(
                               'post_type'      => 'any',
                               'posts_per_page' => 1,
                               'post_status'    => 'any',
                       )
               );
               restore_current_blog();

               return ! empty( $posts );
       }

       /**
        * Get the domain aliases table name.
        *
        * @return string
        */
       public static function get_alias_table_name(): string {
               global $wpdb;

               return $wpdb->base_prefix . 'porkpress_domain_aliases';
       }

       /**
        * Create the domain aliases table if it does not exist.
        */
       public static function create_alias_table(): void {
               global $wpdb;

               $table_name      = self::get_alias_table_name();
               $charset_collate = $wpdb->get_charset_collate();

               $sql = "CREATE TABLE {$table_name} (
site_id bigint(20) unsigned NOT NULL,
domain varchar(191) NOT NULL,
is_primary tinyint(1) NOT NULL DEFAULT 0,
status varchar(20) NOT NULL DEFAULT '',
PRIMARY KEY  (site_id, domain),
UNIQUE KEY domain (domain)
) {$charset_collate};";

               require_once ABSPATH . 'wp-admin/includes/upgrade.php';
               dbDelta( $sql );
       }

       /**
        * Validate a fully-qualified domain name and return its ASCII form.
        *
        * @param string $domain Domain to validate.
        * @return string|false ASCII domain on success, false if invalid.
        */
       protected function validate_fqdn( string $domain ) {
               $domain = strtolower( trim( $domain ) );
               if ( '' === $domain ) {
                       return false;
               }

               if ( function_exists( 'idn_to_ascii' ) ) {
                       $variant = defined( 'INTL_IDNA_VARIANT_UTS46' ) ? INTL_IDNA_VARIANT_UTS46 : 0;
                       $ascii   = idn_to_ascii( $domain, IDNA_DEFAULT, $variant );
                       if ( false === $ascii ) {
                               return false;
                       }
               } else {
                       $ascii = $domain;
               }

               if ( false === filter_var( $ascii, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) ) {
                       return false;
               }

               if ( strlen( $ascii ) > 253 || false === strpos( $ascii, '.' ) ) {
                       return false;
               }

               foreach ( explode( '.', $ascii ) as $label ) {
                       if ( '' === $label || strlen( $label ) > 63 ) {
                               return false;
                       }
               }

               return $ascii;
       }

       /**
        * Create a domain alias entry.
        *
        * @param int    $site_id    Site ID.
        * @param string $domain     Domain name.
        * @param bool   $is_primary Whether the alias is primary.
        * @param string $status     Alias status.
        *
        * @param int|null $ttl Optional TTL for DNS records.
        *
        * @return bool|Porkbun_Client_Error True on success, false on failure, or error on API failure.
        */
       public function add_alias( int $site_id, string $domain, bool $is_primary = false, string $status = '', ?int $ttl = null ) {
               global $wpdb;

               $domain = sanitize_text_field( $domain );
               $domain = $this->validate_fqdn( $domain );
               if ( false === $domain ) {
                       return new \WP_Error( 'invalid_domain', __( 'Invalid domain name.', 'porkpress-ssl' ) );
               }

               $table = self::get_alias_table_name();
               $data  = array(
                       'site_id'    => $site_id,
                       'domain'     => $domain,
                       'is_primary' => $is_primary ? 1 : 0,
                       'status'     => sanitize_text_field( $status ),
               );

               $wpdb->query( 'START TRANSACTION' );
               $result = $wpdb->insert( $table, $data, array( '%d', '%s', '%d', '%s' ) );

               if ( false === $result ) {
                       $wpdb->query( 'ROLLBACK' );
                       if ( ! empty( $wpdb->last_error ) && false !== stripos( $wpdb->last_error, 'duplicate' ) ) {
                               return new \WP_Error( 'domain_exists', __( 'Domain already mapped to another site.', 'porkpress-ssl' ) );
                       }
                       return false;
               }

               $ttl = $ttl ?? 600;
               if ( function_exists( 'apply_filters' ) ) {
                       $ttl = (int) apply_filters( 'porkpress_ssl_a_record_ttl', $ttl, $domain, $site_id );
               }

               $dns = $this->create_a_record( $domain, $site_id, $ttl );
               if ( $dns instanceof Porkbun_Client_Error ) {
                       $wpdb->query( 'ROLLBACK' );
                       return $dns;
               }

               $wpdb->query( 'COMMIT' );
               $this->queue_wildcard_aware_issuance( $site_id, $domain );
               $this->clear_domain_cache();
               return true;
       }

       /**
        * Retrieve domain aliases.
        *
        * @param int|null    $site_id Optional site ID to filter.
        * @param string|null $domain  Optional domain to filter.
        *
        * @return array List of alias records.
        */
       public function get_aliases( ?int $site_id = null, ?string $domain = null ): array {
               global $wpdb;

               $table  = self::get_alias_table_name();
               $where  = array();
               $params = array();

               if ( null !== $site_id ) {
                       $where[]  = 'site_id = %d';
                       $params[] = $site_id;
               }

               if ( null !== $domain ) {
                       $where[]  = 'domain = %s';
                       $params[] = strtolower( sanitize_text_field( $domain ) );
               }

               $sql = "SELECT * FROM {$table}";
               if ( $where ) {
                       $sql .= ' WHERE ' . implode( ' AND ', $where );
                       $sql  = $wpdb->prepare( $sql, $params );
               }

               return $wpdb->get_results( $sql, ARRAY_A );
       }

       /**
        * Update a domain alias.
        *
        * @param int    $site_id Site ID.
        * @param string $domain  Domain name.
        * @param array  $data    Data to update (is_primary, status).
        *
        * @return bool True on success, false on failure.
        */
       public function update_alias( int $site_id, string $domain, array $data ): bool {
               global $wpdb;

               $fields  = array();
               $formats = array();

               if ( array_key_exists( 'is_primary', $data ) ) {
                       $fields['is_primary'] = $data['is_primary'] ? 1 : 0;
                       $formats[]            = '%d';
               }

               if ( array_key_exists( 'status', $data ) ) {
                       $fields['status'] = sanitize_text_field( $data['status'] );
                       $formats[]        = '%s';
               }

               if ( empty( $fields ) ) {
                       return false;
               }

               $result = $wpdb->update(
                       self::get_alias_table_name(),
                       $fields,
                       array(
                               'site_id' => $site_id,
                               'domain'  => strtolower( sanitize_text_field( $domain ) ),
                       ),
                       $formats,
                       array( '%d', '%s' )
               );

               if ( false !== $result ) {
                       $this->queue_wildcard_aware_issuance( $site_id, $domain );
                       return true;
               }

               return false;
       }

       /**
        * Delete a domain alias and associated DNS records.
        *
        * @param int    $site_id Site ID.
        * @param string $domain  Domain name.
        *
        * @return bool|Porkbun_Client_Error True on success, false on failure, or error on API failure.
        */
       public function delete_alias( int $site_id, string $domain ) {
               global $wpdb;

               $result = $wpdb->delete(
                       self::get_alias_table_name(),
                       array(
                               'site_id' => $site_id,
                               'domain'  => strtolower( sanitize_text_field( $domain ) ),
                       ),
                       array( '%d', '%s' )
               );

               if ( false === $result ) {
                       return false;
               }

               $dns = $this->delete_a_record( $domain, $site_id );
               if ( $dns instanceof Porkbun_Client_Error ) {
                       return $dns;
               }

               $this->queue_wildcard_aware_issuance( $site_id, $domain );
               $this->clear_domain_cache();
               return true;
       }

       /**
        * Set the primary alias for a site.
        *
        * @param int    $site_id Site ID.
        * @param string $domain  Domain to set as primary.
        *
        * @return bool True on success, false on failure.
        */
       public function set_primary_alias( int $site_id, string $domain ): bool {
               global $wpdb;

               $table  = self::get_alias_table_name();
               $domain = strtolower( sanitize_text_field( $domain ) );

               $result = $wpdb->query(
                       $wpdb->prepare(
                               "UPDATE {$table} SET is_primary = CASE WHEN domain = %s THEN 1 ELSE 0 END WHERE site_id = %d",
                               $domain,
                               $site_id
                       )
               );

       return false !== $result;
   }

  /**
   * Determine whether a domain is active in Porkbun.
   *
   * Uses the API's single-domain endpoint to avoid fetching the full domain
   * list.
   *
   * @param string $domain Domain name.
   *
   * @return bool True if the domain exists and is active, false otherwise. Logs and returns
   *              false if the API request fails.
   */
  public function is_domain_active( string $domain ): bool {
       $result = $this->client->get_domain( $domain );

       if ( $result instanceof Porkbun_Client_Error ) {
               Logger::error( 'get_domain', array( 'domain' => $domain ), $result->message );
               return false;
       }

       if ( ! is_array( $result ) ) {
               return false;
       }

       $info = $result['domain'] ?? $result;
       $status = strtoupper( $info['status'] ?? '' );

       if ( '' === $status ) {
               return false;
       }

       return 'ACTIVE' === $status;
   }

}

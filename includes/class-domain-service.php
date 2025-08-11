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

private const DOMAIN_LIST_CACHE_KEY = 'porkpress_ssl_domain_list';
private const DOMAIN_LIST_CACHE_TTL = 300; // 5 minutes
private const DOMAIN_LIST_MAX_PAGES = 100;

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
                               ? new Porkbun_Client_DryRun( $api_key, $api_secret )
                               : new Porkbun_Client( $api_key, $api_secret );
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
               if ( ! function_exists( 'dns_get_record' ) ) {
                       return true;
               }

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

               $expected_ipv4 = function_exists( 'gethostbynamel' ) ? (array) gethostbynamel( $expected_host ) : array();
               $expected_ipv6 = array();
               if ( function_exists( 'dns_get_record' ) ) {
                       $ipv6_records = dns_get_record( $expected_host, DNS_AAAA );
                       if ( is_array( $ipv6_records ) ) {
                               foreach ( $ipv6_records as $r ) {
                                       if ( ! empty( $r['ipv6'] ) ) {
                                               $expected_ipv6[] = $r['ipv6'];
                                       }
                               }
                       }
               }

               $records = dns_get_record( $domain, DNS_A | DNS_AAAA | DNS_CNAME );
               if ( false === $records || ! is_array( $records ) || empty( $records ) ) {
                       return new \WP_Error( 'dns_lookup_failed', __( 'DNS lookup failed or returned no records.', 'porkpress-ssl' ) );
               }

               $found_a = false;
               foreach ( $records as $record ) {
                       switch ( $record['type'] ) {
                               case 'A':
                                       $found_a = true;
                                       if ( ! in_array( $record['ip'], $expected_ipv4, true ) ) {
                                               return new \WP_Error(
                                                       'dns_mismatch',
                                                       sprintf(
                                                               __( 'Domain %1$s points to %2$s instead of %3$s.', 'porkpress-ssl' ),
                                                               $domain,
                                                               $record['ip'],
                                                               $expected_host
                                                       )
                                               );
                                       }
                                       break;
                               case 'AAAA':
                                       if ( ! in_array( $record['ipv6'], $expected_ipv6, true ) ) {
                                               return new \WP_Error(
                                                       'dns_mismatch',
                                                       sprintf(
                                                               __( 'Domain %1$s points to %2$s instead of %3$s.', 'porkpress-ssl' ),
                                                               $domain,
                                                               $record['ipv6'],
                                                               $expected_host
                                                       )
                                               );
                                       }
                                       break;
                               case 'CNAME':
                                       $target = rtrim( $record['target'], '.' );
                                       if ( $target !== $expected_host ) {
                                               return new \WP_Error(
                                                       'dns_mismatch',
                                                       sprintf(
                                                               __( 'Domain %1$s CNAMEs to %2$s instead of %3$s.', 'porkpress-ssl' ),
                                                               $domain,
                                                               $target,
                                                               $expected_host
                                                       )
                                               );
                                       }
                                       break;
                       }
               }

               if ( ! $found_a ) {
                       return new \WP_Error( 'dns_missing_a_record', sprintf( __( 'Domain %s has no A record.', 'porkpress-ssl' ), $domain ) );
               }

               return true;
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
     * List domains.
     *
     * Results are cached for the duration of the request and persisted in a
     * site transient for five minutes. Use
     * {@see delete_site_transient()} with the `porkpress_ssl_domain_list`
     * key to invalidate the cache when domain data changes.
     *
     * @param int $page     Page number.
     * @param int $per_page Domains per page.
     *
     * @return array|Porkbun_Client_Error
     */
    public function list_domains( int $page = 1, int $per_page = 100 ) {
        if ( null !== $this->domain_list_cache ) {
            return $this->domain_list_cache;
        }

        if ( function_exists( 'get_site_transient' ) ) {
            $cached = get_site_transient( self::DOMAIN_LIST_CACHE_KEY );
            if ( false !== $cached ) {
                $this->domain_list_cache = $cached;
                return $this->domain_list_cache;
            }
        }

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

            $result = $this->client->listDomains( $current_page, $per_page );

            if ( $result instanceof Porkbun_Client_Error ) {
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

        $final = array(
            'status'  => $status,
            'domains' => $all_domains,
        );

        $this->domain_list_cache = $final;

        if ( function_exists( 'set_site_transient' ) ) {
            set_site_transient( self::DOMAIN_LIST_CACHE_KEY, $final, self::DOMAIN_LIST_CACHE_TTL );
        }

        return $final;
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

               if ( ! $skip_check ) {
                       $check = $this->check_dns_health( $domain );
                       if ( $check instanceof \WP_Error ) {
                               return $check;
                       }
               }

               if ( function_exists( 'update_site_meta' ) ) {
                       update_site_meta( $site_id, 'porkpress_domain', $domain );
               }

               $ttl = $ttl ?? 300;
               if ( function_exists( 'apply_filters' ) ) {
                       $ttl = (int) apply_filters( 'porkpress_ssl_a_record_ttl', $ttl, $domain, $site_id );
               }

               $result = $this->add_alias( $site_id, $domain, true, 'active', $ttl );
               if ( $result instanceof Porkbun_Client_Error ) {
                       return $result;
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
               \PorkPress\SSL\Logger::info(
                       'create_site_start',
                       array(
                               'domain'      => $domain,
                               'title'       => $title,
                               'admin_email' => $admin_email,
                               'template'    => $template,
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
                       $result = $this->client->createARecord( $domain, '', $ipv4, $ttl, 'A' );
                       if ( $result instanceof Porkbun_Client_Error ) {
                               \PorkPress\SSL\Logger::error(
                                       'create_a_record',
                                       array(
                                               'domain'  => $domain,
                                               'site_id' => $site_id,
                                               'ip'      => $ipv4,
                                               'ttl'     => $ttl,
                                       ),
                                       $result->message
                               );
                               return $result;
                       }
               }

               if ( $ipv6 ) {
                       $result = $this->client->createARecord( $domain, '', $ipv6, $ttl, 'AAAA' );
                       if ( $result instanceof Porkbun_Client_Error ) {
                               \PorkPress\SSL\Logger::error(
                                       'create_a_record',
                                       array(
                                               'domain'  => $domain,
                                               'site_id' => $site_id,
                                               'ip'      => $ipv6,
                                               'ttl'     => $ttl,
                                       ),
                                       $result->message
                               );
                               return $result;
                       }
               }

               return true;
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

               $records = $this->client->getRecords( $domain );
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
                               $del = $this->client->deleteRecord( $domain, (int) $rec['id'] );
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
KEY domain (domain)
) {$charset_collate};";

               require_once ABSPATH . 'wp-admin/includes/upgrade.php';
               dbDelta( $sql );
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

               $table = self::get_alias_table_name();
               $data  = array(
                       'site_id'    => $site_id,
                       'domain'     => strtolower( sanitize_text_field( $domain ) ),
                       'is_primary' => $is_primary ? 1 : 0,
                       'status'     => sanitize_text_field( $status ),
               );

               $result = $wpdb->insert( $table, $data, array( '%d', '%s', '%d', '%s' ) );

               if ( false === $result ) {
                       return false;
               }

               $ttl = $ttl ?? 300;
               if ( function_exists( 'apply_filters' ) ) {
                       $ttl = (int) apply_filters( 'porkpress_ssl_a_record_ttl', $ttl, $domain, $site_id );
               }

               $dns = $this->create_a_record( $domain, $site_id, $ttl );
               if ( $dns instanceof Porkbun_Client_Error ) {
                       return $dns;
               }

               SSL_Service::queue_issuance( $site_id );
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
                       SSL_Service::queue_issuance( $site_id );
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

               SSL_Service::queue_issuance( $site_id );
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
   * @return bool True if the domain exists and is active, false otherwise.
   */
  public function is_domain_active( string $domain ): bool {
       $result = $this->client->getDomain( $domain );

       if ( $result instanceof Porkbun_Client_Error ) {
               // If the API fails, assume active to avoid false positives.
               return true;
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

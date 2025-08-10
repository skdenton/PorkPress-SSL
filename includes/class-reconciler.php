<?php
/**
 * Reconciler for keeping state in sync with Porkbun.
 *
 * @package PorkPress\SSL
 */

namespace PorkPress\SSL;

defined( 'ABSPATH' ) || exit;

/**
 * Class Reconciler
 */
class Reconciler {
    /**
     * Domain service instance.
     *
     * @var Domain_Service
     */
    protected Domain_Service $domains;

    /**
     * Constructor.
     *
     * @param Domain_Service|null $domains Optional domain service instance.
     */
    public function __construct( ?Domain_Service $domains = null ) {
        $this->domains = $domains ?: new Domain_Service();
    }

    /**
     * Reconcile a single site's primary domain with Porkbun.
     *
     * If the primary domain is disabled or removed, the site is archived and
     * all aliases are unmapped.
     *
     * @param int $site_id Site ID.
     *
     * @return bool True if the site was archived, false otherwise.
     */
    public function reconcile_site( int $site_id ): bool {
        $aliases = $this->domains->get_aliases( $site_id );

        if ( empty( $aliases ) ) {
            return false;
        }

        $primary = null;
        foreach ( $aliases as $alias ) {
            if ( ! empty( $alias['is_primary'] ) ) {
                $primary = $alias['domain'];
                break;
            }
        }

        if ( ! $primary ) {
            return false;
        }

        if ( $this->domains->is_domain_active( $primary ) ) {
            return false;
        }

        if ( function_exists( 'update_blog_status' ) ) {
            update_blog_status( $site_id, 'archived', 1 );
        } elseif ( function_exists( 'wp_update_site' ) ) {
            wp_update_site( $site_id, array( 'archived' => 1 ) );
        }

        foreach ( $aliases as $alias ) {
            $this->domains->delete_alias( $site_id, $alias['domain'] );
        }

        return true;
    }

    /**
     * Reconcile all sites and aliases with Porkbun.
     *
     * Compares the list of domains from Porkbun with the local alias table and
     * site metadata, removing stray aliases, creating missing ones and
     * re-enabling archived sites whose domains are now active.
     *
     * @param bool $apply_changes Whether to remediate drift automatically.
     *
     * @return array List of drift details indexed by type.
     */
    public function reconcile_all( bool $apply_changes = true ): array {
        $drift = array(
            'missing_aliases' => array(),
            'stray_aliases'   => array(),
            'disabled_sites'  => array(),
        );

        $porkbun = array();
        $domains = $this->domains->list_domains();
        if ( ! ( $domains instanceof Porkbun_Client_Error ) && ! empty( $domains['domains'] ) ) {
            foreach ( $domains['domains'] as $info ) {
                if ( ! empty( $info['domain'] ) ) {
                    $porkbun[] = strtolower( $info['domain'] );
                }
            }
        }

        $aliases    = $this->domains->get_aliases();
        $alias_map  = array();
        foreach ( $aliases as $alias ) {
            $domain = strtolower( $alias['domain'] );
            $alias_map[ $domain ] = $alias;
            if ( ! in_array( $domain, $porkbun, true ) ) {
                $drift['stray_aliases'][] = $alias;
                if ( $apply_changes ) {
                    $this->domains->delete_alias( (int) $alias['site_id'], $domain );
                }
            }
        }

        if ( function_exists( 'get_sites' ) && function_exists( 'get_site_meta' ) ) {
            $sites = get_sites( array( 'number' => 0 ) );
            foreach ( $sites as $site ) {
                $domain = get_site_meta( $site->blog_id, 'porkpress_domain', true );
                if ( ! $domain ) {
                    continue;
                }
                $domain = strtolower( $domain );

                if ( in_array( $domain, $porkbun, true ) ) {
                    if ( ! isset( $alias_map[ $domain ] ) ) {
                        $drift['missing_aliases'][] = array(
                            'site_id' => $site->blog_id,
                            'domain'  => $domain,
                        );
                        if ( $apply_changes ) {
                            $this->domains->add_alias( $site->blog_id, $domain, true, 'active' );
                        }
                    }

                    $archived = property_exists( $site, 'archived' ) ? (int) $site->archived : 0;
                    if ( $archived ) {
                        $drift['disabled_sites'][] = array(
                            'site_id' => $site->blog_id,
                            'domain'  => $domain,
                        );
                        if ( $apply_changes ) {
                            if ( function_exists( 'update_blog_status' ) ) {
                                update_blog_status( $site->blog_id, 'archived', 0 );
                            } elseif ( function_exists( 'wp_update_site' ) ) {
                                wp_update_site( $site->blog_id, array( 'archived' => 0 ) );
                            }
                        }
                    }
                } else {
                    $drift['stray_aliases'][] = array(
                        'site_id' => $site->blog_id,
                        'domain'  => $domain,
                    );
                    if ( $apply_changes ) {
                        $this->domains->delete_alias( $site->blog_id, $domain );
                    }
                }
            }
        }

        return $drift;
    }
}


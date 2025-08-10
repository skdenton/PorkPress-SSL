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
}


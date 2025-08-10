<?php
/**
 * Notification helper.
 *
 * @package PorkPress\SSL
 */

namespace PorkPress\SSL;

defined( 'ABSPATH' ) || exit;

/**
 * Sends email and admin notices for plugin events.
 */
class Notifier {
/** Option key for stored notices. */
public const OPTION = 'porkpress_ssl_notices';

/**
 * Send a notification and store an admin notice.
 *
 * @param string $type    Notice type (success|error|warning).
 * @param string $subject Email subject.
 * @param string $message Message body.
 */
public static function notify( string $type, string $subject, string $message ): void {
$logs_url    = \network_admin_url( 'admin.php?page=porkpress-ssl&tab=logs' );
$domains_url = \network_admin_url( 'admin.php?page=porkpress-ssl&tab=domains' );

$notice  = $message;
$notice .= sprintf(
' <a href="%s">%s</a> | <a href="%s">%s</a>',
\esc_url( $logs_url ),
\esc_html__( 'View logs', 'porkpress-ssl' ),
\esc_url( $domains_url ),
\esc_html__( 'Reconcile now', 'porkpress-ssl' )
);

$notices   = \get_site_option( self::OPTION, array() );
$notices[] = array(
'type'    => $type,
'message' => $notice,
);
\update_site_option( self::OPTION, $notices );

$email = \get_site_option( 'admin_email' );
if ( $email ) {
$body = $message . "\n\n" . sprintf(
"%s: %s\n%s: %s",
\__( 'View logs', 'porkpress-ssl' ),
$logs_url,
\__( 'Reconcile now', 'porkpress-ssl' ),
$domains_url
);
\wp_mail( $email, $subject, $body );
}
}

/** Register admin notice hooks. */
public static function register(): void {
\add_action( 'admin_notices', array( __CLASS__, 'display' ) );
\add_action( 'network_admin_notices', array( __CLASS__, 'display' ) );
}

/**
 * Display stored admin notices.
 */
public static function display(): void {
if ( ! \current_user_can( 'manage_network' ) ) {
return;
}
$notices = \get_site_option( self::OPTION, array() );
if ( empty( $notices ) ) {
return;
}
foreach ( $notices as $notice ) {
printf(
'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
\esc_attr( $notice['type'] ),
\wp_kses_post( $notice['message'] )
);
}
\delete_site_option( self::OPTION );
}
}

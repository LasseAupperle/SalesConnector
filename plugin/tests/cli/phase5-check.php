<?php
/**
 * Phase-5 gate (wp eval-file): settings save/validate flow, AJAX wiring,
 * wp-config override, nl_NL translation, zero frontend footprint.
 *
 * Automates the specs/03 §5 checklist as far as a headless environment
 * allows; the remaining visual checks land in the phase-7 UAT.
 *
 * @package LaunchUp\SalesConnector
 */

use LaunchUp\SalesConnector\PushClient;
use LaunchUp\SalesConnector\SettingsPage;

$lusc_fail = static function ( $msg ) {
	fwrite( STDERR, "PHASE5 FAIL: {$msg}\n" );
	exit( 1 );
};
$lusc_assert = static function ( $cond, $msg ) use ( $lusc_fail ) {
	if ( ! $cond ) {
		$lusc_fail( $msg );
	}
};

// The save handler redirects + exits; intercept the redirect instead.
class LuscRedirectSignal extends Exception {}
add_filter(
	'wp_redirect',
	static function ( $location ) {
		throw new LuscRedirectSignal( (string) $location );
	}
);

wp_set_current_user( 1 ); // Admin has manage_woocommerce via WooCommerce.
$lusc_assert( current_user_can( 'manage_woocommerce' ), 'user 1 must have manage_woocommerce' );

$lusc_page = new SettingsPage();

$lusc_save = static function ( array $post ) use ( $lusc_page ) {
	$_POST                = $post;
	$_POST['lusc_save']   = '1';
	$_REQUEST             = $_POST;
	$_REQUEST['_wpnonce'] = wp_create_nonce( 'lusc_save_settings' );
	$_POST['_wpnonce']    = $_REQUEST['_wpnonce'];
	try {
		$lusc_page->handleSave();
	} catch ( LuscRedirectSignal $redirect ) {
		return $redirect->getMessage();
	}
	return null;
};

// --- 1 · Valid save --------------------------------------------------------

delete_option( 'lusc_settings' );
$lusc_redirect = $lusc_save(
	array(
		'lusc_ingest_url' => 'https://hub.example.test/functions/v1/sales-ingest',
		'lusc_api_key'    => 'lu_sk_PHASE5VALIDKEY00001',
	)
);
$lusc_assert( null !== $lusc_redirect && false !== strpos( $lusc_redirect, 'lusc_saved=1' ), 'valid save must redirect with lusc_saved=1' );
$lusc_settings = (array) get_option( 'lusc_settings' );
$lusc_assert( 'https://hub.example.test/functions/v1/sales-ingest' === ( $lusc_settings['ingest_url'] ?? null ), 'ingest_url not saved' );
$lusc_assert( 'lu_sk_PHASE5VALIDKEY00001' === ( $lusc_settings['api_key'] ?? null ), 'api_key not saved' );
$lusc_assert( array() === ( get_transient( 'lusc_save_errors' ) ?: array() ), 'valid save must produce no errors' );

// --- 2 · Invalid save keeps old values + collects both errors --------------

$lusc_redirect = $lusc_save(
	array(
		'lusc_ingest_url' => 'http://insecure.example.test/ingest',
		'lusc_api_key'    => 'lu_sk_short',
	)
);
$lusc_assert( null !== $lusc_redirect && false !== strpos( $lusc_redirect, 'lusc_saved=0' ), 'invalid save must redirect with lusc_saved=0' );
$lusc_settings = (array) get_option( 'lusc_settings' );
$lusc_assert( 'https://hub.example.test/functions/v1/sales-ingest' === $lusc_settings['ingest_url'], 'invalid URL must not overwrite the stored one' );
$lusc_assert( 'lu_sk_PHASE5VALIDKEY00001' === $lusc_settings['api_key'], 'invalid key must not overwrite the stored one' );
$lusc_errors = get_transient( 'lusc_save_errors' );
$lusc_assert( is_array( $lusc_errors ) && 2 === count( $lusc_errors ), 'both validation errors must be collected' );
delete_transient( 'lusc_save_errors' );

// --- 3 · Empty key field keeps the stored key ------------------------------

$lusc_save(
	array(
		'lusc_ingest_url' => 'https://hub.example.test/functions/v1/sales-ingest',
		'lusc_api_key'    => '',
	)
);
$lusc_settings = (array) get_option( 'lusc_settings' );
$lusc_assert( 'lu_sk_PHASE5VALIDKEY00001' === $lusc_settings['api_key'], 'empty key field must keep the stored key' );

// --- 4 · AJAX + admin wiring registered ------------------------------------

$lusc_page->register();
foreach ( array( 'lusc_test', 'lusc_push', 'lusc_backfill', 'lusc_dismiss_backfill_notice', 'lusc_dismiss_failure_notice' ) as $lusc_ajax ) {
	$lusc_assert( false !== has_action( 'wp_ajax_' . $lusc_ajax ), "wp_ajax_{$lusc_ajax} not registered" );
}
$lusc_assert( false !== has_action( 'admin_menu', array( $lusc_page, 'addMenu' ) ), 'admin_menu hook missing' );
$lusc_assert( false !== has_action( 'admin_notices', array( $lusc_page, 'renderFailureNotice' ) ), 'admin_notices hook missing' );

// --- 5 · Zero frontend footprint ------------------------------------------

$lusc_page->enqueueAssets( 'index.php' );
$lusc_assert( ! wp_script_is( 'lusc-admin', 'enqueued' ), 'admin.js must not enqueue outside our page' );
$lusc_page->enqueueAssets( 'woocommerce_page_' . SettingsPage::PAGE_SLUG );
$lusc_assert( wp_script_is( 'lusc-admin', 'enqueued' ), 'admin.js must enqueue on our page' );

// --- 6 · nl_NL translation covers the shipped strings ----------------------

$lusc_mo = WP_PLUGIN_DIR . '/launchup-sales-connector/languages/launchup-sales-connector-nl_NL.mo';
$lusc_assert( file_exists( $lusc_mo ), 'nl_NL .mo file missing' );
unload_textdomain( 'launchup-sales-connector' );
$lusc_assert( load_textdomain( 'launchup-sales-connector', $lusc_mo ), 'nl_NL .mo failed to load' );
$lusc_assert( 'Push nu' === __( 'Push now', 'launchup-sales-connector' ), 'nl translation for "Push now" wrong: ' . __( 'Push now', 'launchup-sales-connector' ) );
$lusc_assert(
	'API-key ongeldig of ingetrokken — maak een nieuwe aan in Launch Hub' === __( 'API key invalid or revoked — create a new one in Launch Hub', 'launchup-sales-connector' ),
	'nl translation for the LU-SAL-002 message wrong'
);
$lusc_assert( 'Verbonden ✓' === __( 'Connected ✓', 'launchup-sales-connector' ), 'nl translation for "Connected ✓" wrong' );
unload_textdomain( 'launchup-sales-connector' );

// --- 7 · wp-config constant override --------------------------------------

define( 'LUSC_API_KEY', 'lu_sk_CONSTANTOVERRIDE001' );
$lusc_assert( 'lu_sk_CONSTANTOVERRIDE001' === PushClient::resolveApiKey(), 'LUSC_API_KEY constant must override the stored key' );

echo "PHASE5 OK: save/validate flows, AJAX wiring, footprint, nl_NL strings, constant override.\n";

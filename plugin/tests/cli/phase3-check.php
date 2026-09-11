<?php
/**
 * Phase-3 gate (wp eval-file): PushClient + StatusStore against a mock
 * ingest endpoint inside wp-env.
 *
 * The mock lives at the WP HTTP API boundary (pre_http_request): it
 * records every outgoing request and returns each response shape the
 * real endpoint can produce — success, LU-SAL-002/003/004, transport
 * failure, warnings. wp_remote_post is really called; only the socket
 * is skipped, which keeps this deterministic in CI.
 *
 * @package LaunchUp\SalesConnector
 */

$lusc_fail = static function ( $msg ) {
	fwrite( STDERR, "PHASE3 FAIL: {$msg}\n" );
	exit( 1 );
};

$lusc_assert = static function ( $cond, $msg ) use ( $lusc_fail ) {
	if ( ! $cond ) {
		$lusc_fail( $msg );
	}
};

// --- Mock endpoint at the WP HTTP boundary --------------------------------

$GLOBALS['lusc_mock_requests'] = array();
$GLOBALS['lusc_mock_reply']    = null;

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		$GLOBALS['lusc_mock_requests'][] = array(
			'url'  => $url,
			'body' => json_decode( (string) $args['body'], true ),
			'args' => $args,
		);
		return $GLOBALS['lusc_mock_reply'];
	},
	10,
	3
);

$lusc_reply = static function ( $code, array $body ) {
	$GLOBALS['lusc_mock_reply'] = array(
		'headers'  => array(),
		'response' => array(
			'code'    => $code,
			'message' => '',
		),
		'body'     => wp_json_encode( $body ),
		'cookies'  => array(),
	);
};

// --- Config ----------------------------------------------------------------

$lusc_key = 'lu_sk_PHASE3TESTKEY0000042';
update_option(
	'lusc_settings',
	array(
		'ingest_url' => 'https://hub.mock.test/functions/v1/sales-ingest',
		'api_key'    => $lusc_key,
	)
);
delete_option( 'lusc_status' );

$lusc_client = new \LaunchUp\SalesConnector\PushClient();
$lusc_store  = new \LaunchUp\SalesConnector\StatusStore();

// --- 1 · test() sends periods: [] and parses the test flag -----------------

$lusc_reply(
	200,
	array(
		'imported' => 0,
		'test'     => true,
	)
);
$lusc_result = $lusc_client->test();
$lusc_assert( $lusc_result->ok && $lusc_result->test && 0 === $lusc_result->imported, 'test() did not parse the test response' );

$lusc_sent = end( $GLOBALS['lusc_mock_requests'] );
$lusc_assert( 'https://hub.mock.test/functions/v1/sales-ingest' === $lusc_sent['url'], 'test() hit wrong URL: ' . $lusc_sent['url'] );
$lusc_assert( array() === $lusc_sent['body']['periods'], 'test() must send periods: []' );
$lusc_assert( '1.2' === $lusc_sent['body']['contract'], 'contract must be 1.2' );
$lusc_assert( site_url() === $lusc_sent['body']['shop_identifier'], 'shop_identifier must be site_url()' );
$lusc_assert( get_bloginfo( 'name' ) === $lusc_sent['body']['shop_name'], 'shop_name must be blog name' );
$lusc_assert( $lusc_key === $lusc_sent['body']['api_key'], 'api_key missing from payload' );
$lusc_store->recordAttempt( 'test', '—', 0, $lusc_result );

// --- 2 · success path ------------------------------------------------------

$lusc_reply( 200, array( 'imported' => 3 ) );
$lusc_result = $lusc_client->push( array( 'contract' => '1.1' ) );
$lusc_assert( $lusc_result->ok && 3 === $lusc_result->imported, 'success path failed' );
$lusc_store->recordAttempt( 'daily', '2026-04…2026-06', 3, $lusc_result );
$lusc_assert( 'ok' === $lusc_store->read()['last_result'], 'StatusStore did not record success' );
$lusc_assert( 0 === $lusc_store->read()['consecutive_failures'], 'failures not reset on success' );

// --- 3 · each LU code ------------------------------------------------------

$lusc_lu_cases = array(
	'LU-SAL-002' => 'API key invalid or revoked',
	'LU-SAL-003' => 'different shop URL',
	'LU-SAL-004' => 'plugin update needed',
);
foreach ( $lusc_lu_cases as $lusc_code => $lusc_fragment ) {
	$lusc_reply(
		403,
		array(
			'code'    => $lusc_code,
			'message' => 'raw',
		)
	);
	$lusc_result = $lusc_client->push( array( 'contract' => '1.1' ) );
	$lusc_assert( ! $lusc_result->ok && $lusc_code === $lusc_result->luCode, "{$lusc_code} not mapped" );
	$lusc_assert( false !== strpos( $lusc_result->message, $lusc_fragment ), "{$lusc_code} human message wrong: {$lusc_result->message}" );
	$lusc_store->recordAttempt( 'daily', 'w', 1, $lusc_result );
}
$lusc_assert( 3 === $lusc_store->read()['consecutive_failures'], 'consecutive_failures should be 3' );

// --- 3b · a 200 that is not OUR endpoint ----------------------------------
//
// The first live install green-lit a truncated ingest URL: something answered 200, the plugin
// logged OK with a fresh success timestamp, and Launch Hub had never heard of the shop. A 200
// without an `imported` count is not agreement.
$lusc_reply( 200, array( 'status' => 'ok' ) );
$lusc_result = $lusc_client->push( array( 'contract' => '1.1' ) );
$lusc_assert( ! $lusc_result->ok, 'a 200 without imported must NOT count as success' );
$lusc_assert( 200 === $lusc_result->httpCode, 'the status code is still reported' );
$lusc_assert( false !== strpos( $lusc_result->message, 'ingest URL' ), 'the message must point at the URL: ' . $lusc_result->message );
$lusc_store->recordAttempt( 'manual', 'w', 1, $lusc_result );
$lusc_assert( 'failed' === $lusc_store->read()['last_result'], 'a bogus 200 must leave the status red' );

// --- 4 · transport failure -------------------------------------------------

$GLOBALS['lusc_mock_reply'] = new WP_Error( 'http_request_failed', 'cURL error 7: connection refused (key ' . $lusc_key . ')' );
$lusc_result                = $lusc_client->push( array( 'contract' => '1.1' ) );
$lusc_assert( ! $lusc_result->ok && null === $lusc_result->httpCode, 'transport failure not mapped' );
$lusc_store->recordAttempt( 'daily', 'w', 1, $lusc_result );

// --- 5 · warnings path -----------------------------------------------------

$lusc_reply(
	200,
	array(
		'imported' => 2,
		'warnings' => array( 'items_total mismatch in 2026-06-01' ),
	)
);
$lusc_result = $lusc_client->push( array( 'contract' => '1.1' ) );
$lusc_assert( $lusc_result->ok && array() !== $lusc_result->warnings, 'warnings not parsed' );
$lusc_store->recordAttempt( 'daily', 'w', 2, $lusc_result );

// --- 6 · key masked in every log entry ------------------------------------

$lusc_status     = $lusc_store->read();
$lusc_serialized = wp_json_encode( $lusc_status );
$lusc_assert( false === strpos( $lusc_serialized, $lusc_key ), 'FULL API KEY LEAKED INTO THE LOG' );
$lusc_assert( count( $lusc_status['log'] ) >= 7, 'expected >= 7 log entries' );
$lusc_assert( false !== strpos( wp_json_encode( $lusc_status['log'][1] ), '0042' ), 'masked key tail missing from transport-failure entry' );

// --- 7 · ring buffer caps at 10 -------------------------------------------

for ( $lusc_i = 0; $lusc_i < 8; $lusc_i++ ) {
	$lusc_store->recordAttempt( 'backfill', "extra-{$lusc_i}", 1, $lusc_result );
}
$lusc_assert( 10 === count( $lusc_store->read()['log'] ), 'ring buffer must cap at 10' );

echo "PHASE3 OK: test/success/LU-codes/transport/warnings paths, key masking, ring buffer.\n";

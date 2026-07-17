<?php
/**
 * PushClient unit tests (specs/02 §3, phase-3 gate).
 *
 * @package LaunchUp\SalesConnector\Tests
 */

declare( strict_types=1 );

namespace LaunchUp\SalesConnector\Tests\Unit\Push;

use LaunchUp\SalesConnector\PushClient;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class PushClientTest extends TestCase {

	protected function setUp(): void {
		lusc_test_reset_options();
		unset( $GLOBALS['lusc_http_response'], $GLOBALS['lusc_http_request'] );
		update_option(
			'lusc_settings',
			[
				'ingest_url' => 'https://hub.example.test/functions/v1/sales-ingest',
				'api_key'    => 'lu_sk_ABCDEFGHIJKLMNOPQRST',
			]
		);
	}

	public function test_success_parses_imported_and_warnings(): void {
		$GLOBALS['lusc_http_response'] = [
			'response' => [ 'code' => 200 ],
			'body'     => '{"imported":3,"warnings":["items_total mismatch in 2026-06-01"]}',
		];

		$result = ( new PushClient() )->push( [ 'contract' => '1.1' ] );

		$this->assertTrue( $result->ok );
		$this->assertSame( 200, $result->httpCode );
		$this->assertSame( 3, $result->imported );
		$this->assertFalse( $result->test );
		$this->assertNull( $result->luCode );
		$this->assertSame( [ 'items_total mismatch in 2026-06-01' ], $result->warnings );
	}

	public function test_request_shape_posts_json_to_ingest_url(): void {
		( new PushClient() )->push( [ 'contract' => '1.1', 'periods' => [] ] );

		$request = $GLOBALS['lusc_http_request'];
		$this->assertSame( 'https://hub.example.test/functions/v1/sales-ingest', $request['url'] );
		$this->assertSame( 15, $request['args']['timeout'] );
		$this->assertSame( 'application/json', $request['args']['headers']['Content-Type'] );
		$this->assertSame( [ 'contract' => '1.1', 'periods' => [] ], json_decode( $request['args']['body'], true ) );
	}

	public function test_test_sends_empty_periods_with_shop_identity(): void {
		$GLOBALS['lusc_http_response'] = [
			'response' => [ 'code' => 200 ],
			'body'     => '{"imported":0,"test":true}',
		];

		$result = ( new PushClient() )->test();

		$this->assertTrue( $result->ok );
		$this->assertTrue( $result->test );
		$this->assertSame( 0, $result->imported );

		$body = json_decode( $GLOBALS['lusc_http_request']['args']['body'], true );
		$this->assertSame( '1.1', $body['contract'] );
		$this->assertSame( 'https://shop.example.test', $body['shop_identifier'] );
		$this->assertSame( 'Test Shop', $body['shop_name'] );
		$this->assertSame( 'lu_sk_ABCDEFGHIJKLMNOPQRST', $body['api_key'] );
		$this->assertSame( [], $body['periods'] );
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function luCodeProvider(): iterable {
		yield 'LU-SAL-002' => [ 'LU-SAL-002', 'API key invalid or revoked — create a new one in Launch Hub' ];
		yield 'LU-SAL-003' => [ 'LU-SAL-003', 'This key belongs to a different shop URL' ];
		yield 'LU-SAL-004' => [ 'LU-SAL-004', 'Payload rejected — plugin update needed?' ];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'luCodeProvider' )]
	public function test_lu_codes_map_to_human_messages( string $code, string $expected ): void {
		$GLOBALS['lusc_http_response'] = [
			'response' => [ 'code' => 403 ],
			'body'     => json_encode( [ 'code' => $code, 'message' => 'raw endpoint text' ] ),
		];

		$result = ( new PushClient() )->push( [ 'contract' => '1.1' ] );

		$this->assertFalse( $result->ok );
		$this->assertSame( 403, $result->httpCode );
		$this->assertSame( $code, $result->luCode );
		$this->assertSame( $expected, $result->message );
	}

	public function test_unknown_error_keeps_endpoint_message(): void {
		$GLOBALS['lusc_http_response'] = [
			'response' => [ 'code' => 500 ],
			'body'     => '{"code":"LU-SAL-999","message":"something else"}',
		];

		$result = ( new PushClient() )->push( [ 'contract' => '1.1' ] );

		$this->assertSame( 'LU-SAL-999', $result->luCode );
		$this->assertSame( 'something else', $result->message );
	}

	public function test_transport_failure_maps_wp_error(): void {
		$GLOBALS['lusc_http_response'] = new \LuscTestError( 'cURL error 28: timeout' );

		$result = ( new PushClient() )->push( [ 'contract' => '1.1' ] );

		$this->assertFalse( $result->ok );
		$this->assertNull( $result->httpCode );
		$this->assertNull( $result->luCode );
		$this->assertSame( 'cURL error 28: timeout', $result->message );
	}

	public function test_mask_key_keeps_last_four(): void {
		$this->assertSame( '…QRST', PushClient::maskKey( 'lu_sk_ABCDEFGHIJKLMNOPQRST' ) );
		$this->assertSame( '', PushClient::maskKey( '' ) );
	}

	#[RunInSeparateProcess]
	public function test_constant_overrides_stored_key(): void {
		update_option(
			'lusc_settings',
			[
				'ingest_url' => 'https://hub.example.test/ingest',
				'api_key'    => 'lu_sk_STOREDKEY123456789012',
			]
		);
		define( 'LUSC_API_KEY', 'lu_sk_CONSTANTKEY1234567890' );

		$this->assertSame( 'lu_sk_CONSTANTKEY1234567890', PushClient::resolveApiKey() );
	}
}

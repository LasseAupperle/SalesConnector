<?php
/**
 * Phase-0 smoke test: the unit harness itself works.
 *
 * @package LaunchUp\SalesConnector\Tests
 */

declare( strict_types=1 );

namespace LaunchUp\SalesConnector\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Proves the bootstrap stubs load and behave before any real classes exist.
 */
final class BootstrapSmokeTest extends TestCase {

	protected function setUp(): void {
		lusc_test_reset_options();
	}

	public function test_option_stub_round_trips(): void {
		$this->assertFalse( get_option( 'lusc_settings' ) );
		update_option( 'lusc_settings', array( 'ingest_url' => 'https://example.test' ) );
		$this->assertSame( array( 'ingest_url' => 'https://example.test' ), get_option( 'lusc_settings' ) );
		delete_option( 'lusc_settings' );
		$this->assertFalse( get_option( 'lusc_settings' ) );
	}

	public function test_wp_json_encode_stub_encodes(): void {
		$this->assertSame( '{"contract":"1.1"}', wp_json_encode( array( 'contract' => '1.1' ) ) );
	}
}

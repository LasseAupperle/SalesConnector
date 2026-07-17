<?php
/**
 * readme.txt header lint (specs/04 §5): required headers present and the
 * Stable tag matches the plugin header Version.
 *
 * Usage: php scripts/check-readme.php  (run from the plugin/ directory)
 *
 * @package LaunchUp\SalesConnector
 */

declare( strict_types=1 );

$root   = dirname( __DIR__ );
$readme = (string) file_get_contents( $root . '/readme.txt' );
$plugin = (string) file_get_contents( $root . '/launchup-sales-connector.php' );

$fail = static function ( string $msg ): void {
	fwrite( STDERR, "README LINT FAIL: {$msg}\n" );
	exit( 1 );
};

$required = array(
	'/^=== Launch Up Sales Connector ===$/m'      => 'plugin name banner',
	'/^Requires at least: 6\.9$/m'                => 'Requires at least 6.9',
	'/^Tested up to: [\d.]+$/m'                   => 'Tested up to',
	'/^Requires PHP: 8\.1$/m'                     => 'Requires PHP 8.1',
	'/^WC requires at least: 10\.0$/m'            => 'WC requires at least 10.0',
	'/^WC tested up to: [\d.]+$/m'                => 'WC tested up to',
	'/^Stable tag: [\d.]+$/m'                     => 'Stable tag',
	'/^License: GPL-2\.0-or-later$/m'             => 'License GPL-2.0-or-later',
	'/^License URI: https:\/\/www\.gnu\.org\/licenses\/gpl-2\.0\.html$/m' => 'License URI',
	'/^== Changelog ==$/m'                        => 'Changelog section',
);
foreach ( $required as $pattern => $label ) {
	if ( 1 !== preg_match( $pattern, $readme ) ) {
		$fail( "missing or malformed header: {$label}" );
	}
}

preg_match( '/^Stable tag: ([\d.]+)$/m', $readme, $stable );
preg_match( '/^ \* Version:\s+([\d.]+)$/m', $plugin, $version );
if ( empty( $stable[1] ) || empty( $version[1] ) ) {
	$fail( 'could not extract Stable tag or plugin Version' );
}
if ( $stable[1] !== $version[1] ) {
	$fail( "Stable tag {$stable[1]} != plugin Version {$version[1]}" );
}

if ( false === strpos( $readme, '= ' . $version[1] . ' =' ) ) {
	$fail( "changelog has no entry for {$version[1]}" );
}

echo "README LINT OK: headers complete, Stable tag {$stable[1]} == Version {$version[1]}, changelog entry present.\n";

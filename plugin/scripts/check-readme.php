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
	'/^Requires at least: [\d.]+$/m'              => 'Requires at least',
	'/^Tested up to: [\d.]+$/m'                   => 'Tested up to',
	'/^Requires PHP: [\d.]+$/m'                   => 'Requires PHP',
	'/^WC requires at least: [\d.]+$/m'           => 'WC requires at least',
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

/*
 * The baseline itself is NOT hard-coded here.
 *
 * It used to be — '/^Requires at least: 6\.9$/' and friends — which made this lint fail the moment
 * the baseline moved, and pointed at the linter instead of at anything real. Worse, it never
 * checked the thing that actually breaks a shop: readme.txt and the plugin header disagreeing, so
 * WordPress refuses to install on a site the listing says is supported.
 *
 * So the rule is agreement, not a literal. Change the baseline in the plugin header and this keeps
 * guarding it, in both directions.
 */
$baseline = array(
	'Requires at least'    => '/^ \* Requires at least: ([\d.]+)$/m',
	'Requires PHP'         => '/^ \* Requires PHP:\s+([\d.]+)$/m',
	'WC requires at least' => '/^ \* WC requires at least: ([\d.]+)$/m',
	'WC tested up to'      => '/^ \* WC tested up to:\s+([\d.]+)$/m',
);
foreach ( $baseline as $header => $pattern ) {
	if ( 1 !== preg_match( $pattern, $plugin, $from_plugin ) ) {
		$fail( "plugin header has no {$header}" );
	}
	preg_match( '/^' . preg_quote( $header, '/' ) . ': ([\d.]+)$/m', $readme, $from_readme );
	if ( $from_plugin[1] !== ( $from_readme[1] ?? '' ) ) {
		$fail( "{$header}: readme.txt says '" . ( $from_readme[1] ?? '' ) . "', plugin header says '{$from_plugin[1]}'" );
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

echo "README LINT OK: headers complete, baseline agrees with the plugin header, Stable tag {$stable[1]} == Version {$version[1]}, changelog entry present.\n";

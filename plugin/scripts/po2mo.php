<?php
/**
 * po2mo.php — dependency-free .po → .mo compiler.
 *
 * Usage: php scripts/po2mo.php <file.po> <file.mo>
 *
 * Parses a GNU gettext .po file (multi-line quoted strings and the escape
 * sequences \n \t \r \" \\ are handled) and writes a valid little-endian .mo
 * binary. The header entry (empty msgid) is kept and emitted as the .mo
 * header entry; fuzzy and obsolete (#~) entries are skipped. Plurals are not
 * used by this plugin, but msgstr[0] passes through as the singular form.
 *
 * @package LaunchUp\SalesConnector
 */

declare( strict_types=1 );

if ( $argc < 3 ) {
	fwrite( STDERR, "Usage: php scripts/po2mo.php <file.po> <file.mo>\n" );
	exit( 1 );
}

$po_file = $argv[1];
$mo_file = $argv[2];

$content = @file_get_contents( $po_file );
if ( false === $content ) {
	fwrite( STDERR, "po2mo: cannot read {$po_file}\n" );
	exit( 1 );
}

/**
 * Turn a raw .po string body (the text between the surrounding quotes) into
 * its real byte value by resolving backslash escapes.
 */
function po2mo_unescape( string $s ): string {
	$out = '';
	$len = strlen( $s );
	for ( $i = 0; $i < $len; $i++ ) {
		$c = $s[ $i ];
		if ( '\\' === $c && $i + 1 < $len ) {
			$next = $s[ $i + 1 ];
			switch ( $next ) {
				case 'n':
					$out .= "\n";
					break;
				case 't':
					$out .= "\t";
					break;
				case 'r':
					$out .= "\r";
					break;
				case '"':
					$out .= '"';
					break;
				case '\\':
					$out .= '\\';
					break;
				default:
					$out .= $next;
					break;
			}
			$i++;
		} else {
			$out .= $c;
		}
	}
	return $out;
}

/**
 * Extract the raw body of the first "..." region on a line (still escaped).
 */
function po2mo_quoted( string $line ): string {
	$first = strpos( $line, '"' );
	$last  = strrpos( $line, '"' );
	if ( false === $first || false === $last || $last <= $first ) {
		return '';
	}
	return substr( $line, $first + 1, $last - $first - 1 );
}

$lines = preg_split( '/\r\n|\r|\n/', $content );

$entries    = array(); // key => translation string.
$ctxt       = null;
$id         = null;
$str        = null;
$fuzzy      = false;
$started    = false;
$fuzzy_next = false;   // fuzzy flag read from comments, belongs to the NEXT entry.
$last       = null;    // continuation target: 'ctxt' | 'id' | 'plural' | 'str' | 'skip'.

$reset = static function () use ( &$ctxt, &$id, &$str, &$fuzzy, &$started, &$last ): void {
	$ctxt    = null;
	$id      = null;
	$str     = null;
	$fuzzy   = false;
	$started = false;
	$last    = null;
};

$flush = static function () use ( &$entries, &$ctxt, &$id, &$str, &$fuzzy, &$started, $reset ): void {
	if ( $started && null !== $id && ! $fuzzy ) {
		$key             = null !== $ctxt ? $ctxt . "\x04" . $id : $id;
		$entries[ $key ] = $str ?? '';
	}
	$reset();
};

foreach ( $lines as $line ) {
	$t = trim( $line );

	// Obsolete entries: skip every #~ line.
	if ( 0 === strncmp( $t, '#~', 2 ) ) {
		continue;
	}

	// Blank line ends the current entry.
	if ( '' === $t ) {
		$flush();
		continue;
	}

	// Comments: only the fuzzy flag matters.
	if ( '#' === $t[0] ) {
		if ( 0 === strncmp( $t, '#,', 2 ) && false !== strpos( $t, 'fuzzy' ) ) {
			$fuzzy_next = true;
		}
		continue;
	}

	if ( 0 === strncmp( $t, 'msgctxt', 7 ) ) {
		if ( $started ) {
			$flush();
		}
		$fuzzy      = $fuzzy_next;
		$fuzzy_next = false;
		$started    = true;
		$ctxt       = po2mo_unescape( po2mo_quoted( $t ) );
		$last       = 'ctxt';
		continue;
	}

	if ( 0 === strncmp( $t, 'msgid_plural', 12 ) ) {
		$last = 'plural';
		continue;
	}

	if ( 0 === strncmp( $t, 'msgid', 5 ) ) {
		if ( $started && null !== $id ) {
			$flush();
		}
		if ( ! $started ) {
			$fuzzy      = $fuzzy_next;
			$fuzzy_next = false;
			$started    = true;
		}
		$id   = po2mo_unescape( po2mo_quoted( $t ) );
		$last = 'id';
		continue;
	}

	if ( 0 === strncmp( $t, 'msgstr', 6 ) ) {
		if ( 0 === strncmp( $t, 'msgstr[', 7 ) ) {
			// Only the first plural form is used as the translation.
			if ( 0 === strncmp( $t, 'msgstr[0]', 9 ) ) {
				$str  = po2mo_unescape( po2mo_quoted( $t ) );
				$last = 'str';
			} else {
				$last = 'skip';
			}
		} else {
			$str  = po2mo_unescape( po2mo_quoted( $t ) );
			$last = 'str';
		}
		continue;
	}

	// Continuation line: "....".
	if ( '"' === $t[0] ) {
		$val = po2mo_unescape( po2mo_quoted( $t ) );
		switch ( $last ) {
			case 'ctxt':
				$ctxt .= $val;
				break;
			case 'id':
				$id .= $val;
				break;
			case 'str':
				$str .= $val;
				break;
			// 'plural' and 'skip' are intentionally dropped.
		}
		continue;
	}
}
$flush();

// Sort by original string, bytewise — required by the .mo binary-search reader.
ksort( $entries, SORT_STRING );

$keys = array_keys( $entries );
$n    = count( $keys );

$orig_table_offset  = 28;
$trans_table_offset = 28 + 8 * $n;
$strings_offset     = 28 + 16 * $n;

$orig_table  = '';
$trans_table = '';
$orig_data   = '';
$trans_data  = '';

$offset = $strings_offset;
foreach ( $keys as $k ) {
	$len         = strlen( $k );
	$orig_table .= pack( 'VV', $len, $offset );
	$orig_data  .= $k . "\0";
	$offset     += $len + 1;
}
foreach ( $keys as $k ) {
	$translation  = $entries[ $k ];
	$len          = strlen( $translation );
	$trans_table .= pack( 'VV', $len, $offset );
	$trans_data  .= $translation . "\0";
	$offset      += $len + 1;
}

$header = pack( 'V', 0x950412de )        // Magic (little-endian).
	. pack( 'V', 0 )                     // File format revision.
	. pack( 'V', $n )                    // Number of strings.
	. pack( 'V', $orig_table_offset )    // Offset of table with original strings.
	. pack( 'V', $trans_table_offset )   // Offset of table with translation strings.
	. pack( 'V', 0 )                     // Size of hashing table.
	. pack( 'V', $strings_offset );      // Offset of hashing table (unused, size 0).

$mo = $header . $orig_table . $trans_table . $orig_data . $trans_data;

if ( false === file_put_contents( $mo_file, $mo ) ) {
	fwrite( STDERR, "po2mo: cannot write {$mo_file}\n" );
	exit( 1 );
}

fwrite( STDOUT, "po2mo: wrote {$n} entries to {$mo_file}\n" );
exit( 0 );

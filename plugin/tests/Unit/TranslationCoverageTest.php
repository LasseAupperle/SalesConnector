<?php
/**
 * Every user-facing string ships with a Dutch translation (specs/03 §4).
 *
 * Every creator shop runs nl_NL. An English string is not a cosmetic miss there — it is the one
 * sentence that was supposed to explain something, printed in a language the reader did not ask
 * for. The wp-env gate checks three specific translations by hand, which means a NEW string is
 * covered by nobody: the three button descriptions added on 2026-09-11 would have passed every
 * gate untranslated.
 *
 * So this asserts the rule instead of the instances: whatever the shipped PHP passes through a
 * gettext call must exist in the .po with a non-empty translation.
 *
 * @package LaunchUp\SalesConnector
 */

declare( strict_types=1 );

namespace LaunchUp\SalesConnector\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TranslationCoverageTest extends TestCase {

	/** The text domain every shipped string must carry. */
	private const DOMAIN = 'launchup-sales-connector';

	/**
	 * Every msgid the shipped PHP asks gettext for.
	 *
	 * Only single-quoted literals are matched, which is what the codebase uses — a string built by
	 * concatenation cannot be translated anyway, and WPCS already refuses those. No shipped string
	 * contains an escaped quote (double quotes sit inside single-quoted PHP strings unescaped), so
	 * the simple pattern is the honest one; test_the_scanner_finds_the_strings_it_is_meant_to_guard
	 * fails loudly if that ever stops being true.
	 *
	 * @return array<string, string> msgid => file:line where it first appears.
	 */
	private function shippedStrings(): array {
		$root  = dirname( __DIR__, 2 );
		$found = array();

		$files = array_merge(
			glob( $root . '/includes/*.php' ) ?: array(),
			array( $root . '/launchup-sales-connector.php', $root . '/uninstall.php' )
		);

		foreach ( $files as $file ) {
			if ( ! is_file( $file ) ) {
				continue;
			}

			$lines   = file( $file );
			$pattern = "/(?:esc_html__|esc_attr__|esc_html_e|esc_attr_e|__|_e)\(\s*'([^']*)'\s*,\s*'" . self::DOMAIN . "'/";

			foreach ( $lines as $number => $line ) {
				if ( ! preg_match_all( $pattern, $line, $matches ) ) {
					continue;
				}

				foreach ( $matches[1] as $msgid ) {
					if ( ! isset( $found[ $msgid ] ) ) {
						$found[ $msgid ] = basename( $file ) . ':' . ( $number + 1 );
					}
				}
			}
		}

		return $found;
	}

	/**
	 * msgid => msgstr from the Dutch catalogue.
	 *
	 * @return array<string, string>
	 */
	private function catalogue(): array {
		$po      = (string) file_get_contents( dirname( __DIR__, 2 ) . '/languages/' . self::DOMAIN . '-nl_NL.po' );
		$entries = array();
		$msgid   = null;
		$target  = null;

		// Line-driven rather than one big regex: .po values continue over as many quoted lines as
		// they like, and a regex that tries to span them is unreadable and wrong at the edges.
		foreach ( preg_split( '/\R/', $po ) as $line ) {
			$line = trim( $line );

			if ( str_starts_with( $line, 'msgid ' ) ) {
				if ( null !== $msgid ) {
					$entries[ $msgid['text'] ] = $target['text'] ?? '';
				}
				$msgid  = array( 'text' => $this->unquote( substr( $line, 6 ) ) );
				$target = null;
				continue;
			}

			if ( str_starts_with( $line, 'msgstr ' ) ) {
				$target = array( 'text' => $this->unquote( substr( $line, 7 ) ) );
				continue;
			}

			if ( str_starts_with( $line, '"' ) ) {
				// A continuation line belongs to whichever of the two was opened last.
				if ( null !== $target ) {
					$target['text'] .= $this->unquote( $line );
				} elseif ( null !== $msgid ) {
					$msgid['text'] .= $this->unquote( $line );
				}
			}
		}

		if ( null !== $msgid ) {
			$entries[ $msgid['text'] ] = $target['text'] ?? '';
		}

		return $entries;
	}

	/**
	 * Turn one quoted .po value into the string it denotes.
	 *
	 * @param string $quoted A single "..." token.
	 */
	private function unquote( string $quoted ): string {
		$quoted = trim( $quoted );
		$inner  = preg_replace( '/^"|"$/', '', $quoted );

		return str_replace(
			array( '\n', '\t', '\"', '\\\\' ),
			array( "\n", "\t", '"', '\\' ),
			(string) $inner
		);
	}

	/**
	 * No shipped string reaches a Dutch shop in English.
	 */
	public function test_every_shipped_string_has_a_dutch_translation(): void {
		$catalogue = $this->catalogue();
		$this->assertNotEmpty( $catalogue, 'the nl_NL .po parsed as empty — the parser is wrong, not the catalogue' );

		$missing = array();

		foreach ( $this->shippedStrings() as $msgid => $where ) {
			if ( ! isset( $catalogue[ $msgid ] ) ) {
				$missing[] = "{$where}  NOT IN .po: \"{$msgid}\"";
			} elseif ( '' === trim( $catalogue[ $msgid ] ) ) {
				$missing[] = "{$where}  EMPTY msgstr: \"{$msgid}\"";
			}
		}

		$this->assertSame(
			array(),
			$missing,
			"Untranslated strings would ship to a Dutch shop:\n" . implode( "\n", $missing )
		);
	}

	/**
	 * The scanner must actually find strings — a regex that matches nothing passes silently.
	 */
	public function test_the_scanner_finds_the_strings_it_is_meant_to_guard(): void {
		$shipped = $this->shippedStrings();

		$this->assertGreaterThan( 30, count( $shipped ), 'the source scan found suspiciously few strings' );
		$this->assertArrayHasKey( 'Push now', $shipped );
		$this->assertArrayHasKey(
			'Push now sends this month and the two before it — the same thing the nightly job at 04:00 does.',
			$shipped
		);
	}

	/**
	 * And the catalogue parser must actually read the catalogue.
	 */
	public function test_the_catalogue_parser_reads_known_entries(): void {
		$catalogue = $this->catalogue();

		$this->assertSame( 'Push nu', $catalogue['Push now'] ?? null );
		$this->assertSame( 'Historie pushen', $catalogue['Push history'] ?? null );
	}
}

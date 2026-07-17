<?php
/**
 * Updater — auto-updates from public GitHub releases (specs/04 §2).
 *
 * Uses plugin-update-checker v5 with release assets enabled: every creator
 * shop sees a new tagged GitHub release as a normal plugin update. No
 * tokens, no update server.
 *
 * @package LaunchUp\SalesConnector
 */

declare( strict_types=1 );

namespace LaunchUp\SalesConnector;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Bootstraps the update checker against the public repo.
 */
final class Updater {

	public const REPO_URL = 'https://github.com/LasseAupperle/SalesConnector/';

	/**
	 * Load the bundled library and build the checker.
	 */
	public static function register(): void {
		require_once dirname( LUSC_PLUGIN_FILE ) . '/lib/plugin-update-checker/plugin-update-checker.php';

		$checker = PucFactory::buildUpdateChecker(
			self::REPO_URL,
			LUSC_PLUGIN_FILE,
			'launchup-sales-connector'
		);
		$checker->getVcsApi()->enableReleaseAssets();
	}
}

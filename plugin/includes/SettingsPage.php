<?php
/**
 * SettingsPage — WooCommerce → Launch Up Connector (specs/03).
 *
 * One screen, three cards (Verbinding, Acties, Status), plus the global
 * failure notice. All user-facing strings via i18n (EN source, nl_NL
 * shipped). Validation rules are pure static methods so they unit-test
 * without WordPress.
 *
 * @package LaunchUp\SalesConnector
 */

declare( strict_types=1 );

namespace LaunchUp\SalesConnector;

/**
 * Admin screen + AJAX endpoints. Zero frontend footprint: everything is
 * gated on is_admin(), our page hook, and manage_woocommerce.
 */
final class SettingsPage {

	public const PAGE_SLUG   = 'launchup-sales-connector';
	public const KEY_PATTERN = '/^lu_sk_[A-Za-z0-9]{20,}$/';

	/**
	 * Hours after which a green indicator turns stale (26 h, specs/03 §1).
	 */
	public const GREEN_MAX_AGE_HOURS = 26;

	/**
	 * Hook everything. Called from lusc_boot() (WooCommerce active).
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
		add_action( 'admin_init', array( $this, 'handleSave' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
		add_action( 'admin_notices', array( $this, 'renderFailureNotice' ) );
		add_action( 'wp_ajax_lusc_test', array( $this, 'ajaxTest' ) );
		add_action( 'wp_ajax_lusc_push', array( $this, 'ajaxPush' ) );
		add_action( 'wp_ajax_lusc_backfill', array( $this, 'ajaxBackfill' ) );
		add_action( 'wp_ajax_lusc_dismiss_backfill_notice', array( $this, 'ajaxDismissBackfillNotice' ) );
		add_action( 'wp_ajax_lusc_dismiss_failure_notice', array( $this, 'ajaxDismissFailureNotice' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( LUSC_PLUGIN_FILE ),
			array( $this, 'settingsLink' )
		);
	}

	// -----------------------------------------------------------------------
	// Pure validation / display rules (unit-tested, specs/03 §5)
	// -----------------------------------------------------------------------

	/**
	 * Ingest URL must be https:// (specs/03 §1).
	 *
	 * @param string $url Candidate URL.
	 */
	public static function isValidIngestUrl( string $url ): bool {
		return 1 === preg_match( '#^https://[^\s]+$#', $url );
	}

	/**
	 * API key must match /^lu_sk_[A-Za-z0-9]{20,}$/ (specs/03 §1).
	 *
	 * @param string $key Candidate key (trimmed).
	 */
	public static function isValidApiKey( string $key ): bool {
		return 1 === preg_match( self::KEY_PATTERN, $key );
	}

	/**
	 * Mask a stored key for re-rendering: last 4 chars only.
	 *
	 * @param string $key Full key.
	 */
	public static function displayKey( string $key ): string {
		return PushClient::maskKey( $key );
	}

	/**
	 * Status-indicator state: 'green' | 'orange' | 'grey' (specs/03 §1).
	 *
	 * @param array<string, mixed> $status StatusStore::read() shape.
	 * @param int                  $now    Current unix timestamp.
	 */
	public static function indicator( array $status, int $now ): string {
		if ( 'failed' === $status['last_result'] ) {
			return 'orange';
		}
		if ( null !== $status['last_success_at'] ) {
			$age = $now - (int) strtotime( (string) $status['last_success_at'] );
			if ( $age < self::GREEN_MAX_AGE_HOURS * 3600 ) {
				return 'green';
			}
			return 'orange';
		}
		return 'grey';
	}

	/**
	 * Whether the global failure notice must show (specs/03 §2):
	 * consecutive_failures >= 3 and not dismissed for THIS episode. An
	 * episode is identified by last_success_at (constant while failing);
	 * a success resets the counter and thereby clears the notice.
	 *
	 * @param array<string, mixed> $status           StatusStore::read() shape.
	 * @param string|null          $dismissedEpisode Stored dismissal marker.
	 */
	public static function failureNoticeVisible( array $status, ?string $dismissedEpisode ): bool {
		if ( (int) $status['consecutive_failures'] < 3 ) {
			return false;
		}
		$episode = self::episodeKey( $status );
		return $dismissedEpisode !== $episode;
	}

	/**
	 * Identity of the current failure episode.
	 *
	 * @param array<string, mixed> $status StatusStore::read() shape.
	 */
	public static function episodeKey( array $status ): string {
		return 'episode-' . ( $status['last_success_at'] ?? 'never' );
	}

	/**
	 * Whether the backfill button is enabled: a successful test or push
	 * must exist (specs/03 §1).
	 *
	 * @param array<string, mixed> $status StatusStore::read() shape.
	 */
	public static function backfillEnabled( array $status ): bool {
		return null !== $status['last_success_at'];
	}

	// -----------------------------------------------------------------------
	// Menu, assets, save
	// -----------------------------------------------------------------------

	/**
	 * Add the WooCommerce submenu page.
	 */
	public function addMenu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Launch Up Connector', 'launchup-sales-connector' ),
			__( 'Launch Up Connector', 'launchup-sales-connector' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'renderPage' )
		);
	}

	/**
	 * Enqueue admin.js on our page only (no inline JS, specs/03 §3).
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueueAssets( string $hook ): void {
		if ( 'woocommerce_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_script(
			'lusc-admin',
			plugins_url( 'assets/admin.js', LUSC_PLUGIN_FILE ),
			array(),
			LUSC_VERSION,
			true
		);
		wp_localize_script(
			'lusc-admin',
			'luscAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'lusc_ajax' ),
				'i18n'    => array(
					'connected'       => __( 'Connected ✓', 'launchup-sales-connector' ),
					'started'         => __( 'Started — the result will appear in the log.', 'launchup-sales-connector' ),
					'backfillConfirm' => __( 'Push all months since the first order? This runs in the background and is safe to re-run — it also serves as a full re-sync.', 'launchup-sales-connector' ),
					'error'           => __( 'Request failed', 'launchup-sales-connector' ),
				),
			)
		);
	}

	/**
	 * Handle the settings form POST (nonce + capability, specs/03 §1).
	 */
	public function handleSave(): void {
		if ( ! isset( $_POST['lusc_save'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'launchup-sales-connector' ) );
		}
		check_admin_referer( 'lusc_save_settings' );

		$settings = (array) get_option( 'lusc_settings', array() );
		$errors   = array();

		$url = esc_url_raw( wp_unslash( $_POST['lusc_ingest_url'] ?? '' ) );
		$url = trim( $url );
		if ( self::isValidIngestUrl( $url ) ) {
			$settings['ingest_url'] = $url;
		} else {
			$errors[] = __( 'The ingest URL must start with https://', 'launchup-sales-connector' );
		}

		if ( ! defined( 'LUSC_API_KEY' ) ) {
			$key = sanitize_text_field( wp_unslash( $_POST['lusc_api_key'] ?? '' ) );
			$key = trim( $key );
			if ( '' !== $key ) {
				if ( self::isValidApiKey( $key ) ) {
					$settings['api_key'] = $key;
				} else {
					$errors[] = __( 'The API key format is invalid (expected lu_sk_…).', 'launchup-sales-connector' );
				}
			}
		}

		update_option( 'lusc_settings', $settings, false );
		set_transient( 'lusc_save_errors', $errors, 60 );

		$referer = wp_get_referer();
		if ( ! $referer ) {
			$referer = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		}
		wp_safe_redirect( add_query_arg( 'lusc_saved', $errors ? '0' : '1', $referer ) );
		exit;
	}

	/**
	 * Settings link on the Plugins row (specs/03 §3).
	 *
	 * @param array<int, string> $links Existing action links.
	 * @return array<int, string>
	 */
	public function settingsLink( array $links ): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
				esc_html__( 'Settings', 'launchup-sales-connector' )
			)
		);
		return $links;
	}

	// -----------------------------------------------------------------------
	// AJAX (nonce + capability on every action, specs/03 §3)
	// -----------------------------------------------------------------------

	/**
	 * Shared AJAX guard.
	 */
	private function guardAjax(): void {
		check_ajax_referer( 'lusc_ajax' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'launchup-sales-connector' ) ), 403 );
		}
	}

	/**
	 * Test verbinding button.
	 */
	public function ajaxTest(): void {
		$this->guardAjax();
		$result = ( new PushClient() )->test();
		( new StatusStore() )->recordAttempt( 'test', '—', 0, $result );

		if ( $result->ok ) {
			update_option( 'lusc_first_test_ok', 1, false );
			wp_send_json_success( array( 'message' => __( 'Connected ✓', 'launchup-sales-connector' ) ) );
		}
		wp_send_json_error( array( 'message' => $result->message ) );
	}

	/**
	 * Push nu button: enqueue the async manual push.
	 */
	public function ajaxPush(): void {
		$this->guardAjax();
		Scheduler::enqueuePushNow();
		wp_send_json_success( array( 'message' => __( 'Started — the result will appear in the log.', 'launchup-sales-connector' ) ) );
	}

	/**
	 * Historie pushen button: start the backfill chain.
	 */
	public function ajaxBackfill(): void {
		$this->guardAjax();
		if ( ! self::backfillEnabled( ( new StatusStore() )->read() ) ) {
			wp_send_json_error( array( 'message' => __( 'Run a successful connection test first.', 'launchup-sales-connector' ) ), 400 );
		}
		( new Scheduler() )->startBackfill();
		update_option( 'lusc_backfill_started', 1, false );
		wp_send_json_success( array( 'message' => __( 'Started — the result will appear in the log.', 'launchup-sales-connector' ) ) );
	}

	/**
	 * Dismiss the backfill suggestion notice.
	 */
	public function ajaxDismissBackfillNotice(): void {
		$this->guardAjax();
		update_option( 'lusc_backfill_notice_dismissed', 1, false );
		wp_send_json_success();
	}

	/**
	 * Dismiss the global failure notice for the current episode.
	 */
	public function ajaxDismissFailureNotice(): void {
		$this->guardAjax();
		update_option( 'lusc_failure_notice_dismissed', self::episodeKey( ( new StatusStore() )->read() ), false );
		wp_send_json_success();
	}

	// -----------------------------------------------------------------------
	// Rendering
	// -----------------------------------------------------------------------

	/**
	 * Global admin notice when pushing keeps failing (specs/03 §2).
	 */
	public function renderFailureNotice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$status    = ( new StatusStore() )->read();
		$dismissed = get_option( 'lusc_failure_notice_dismissed', null );
		if ( ! self::failureNoticeVisible( $status, is_string( $dismissed ) ? $dismissed : null ) ) {
			return;
		}
		$last = $status['log'][0]['message'] ?? '';
		printf(
			'<div class="notice notice-error is-dismissible" data-lusc-notice="failure"><p>%s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %s: last error message from the log. */
					__( 'Launch Up Connector cannot push to Launch Hub (%s).', 'launchup-sales-connector' ),
					$last
				)
			),
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Open settings', 'launchup-sales-connector' )
		);
	}

	/**
	 * Render the settings screen (three cards).
	 */
	public function renderPage(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$settings    = (array) get_option( 'lusc_settings', array() );
		$status      = ( new StatusStore() )->read();
		$errors      = get_transient( 'lusc_save_errors' );
		$errors      = is_array( $errors ) ? $errors : array();
		$hasKeyConst = defined( 'LUSC_API_KEY' );
		$storedKey   = (string) ( $settings['api_key'] ?? '' );
		$indicator   = self::indicator( $status, time() );

		delete_transient( 'lusc_save_errors' );

		echo '<div class="wrap"><h1>' . esc_html__( 'Launch Up Connector', 'launchup-sales-connector' ) . '</h1>';

		foreach ( $errors as $error ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error ) );
		}
		if ( isset( $_GET['lusc_saved'] ) && '1' === $_GET['lusc_saved'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
			printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html__( 'Settings saved.', 'launchup-sales-connector' ) );
		}

		$this->renderBackfillSuggestion( $status );

		// --- Card 1: Verbinding ------------------------------------------
		echo '<div class="card" style="max-width:680px">';
		echo '<h2>' . esc_html__( 'Connection', 'launchup-sales-connector' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'lusc_save_settings' );
		echo '<table class="form-table" role="presentation">';

		printf(
			'<tr><th scope="row"><label for="lusc_ingest_url">%s</label></th><td><input type="url" class="regular-text" id="lusc_ingest_url" name="lusc_ingest_url" value="%s" placeholder="https://…" required pattern="https://.*"><p class="description">%s</p></td></tr>',
			esc_html__( 'Ingest URL', 'launchup-sales-connector' ),
			esc_attr( (string) ( $settings['ingest_url'] ?? '' ) ),
			esc_html__( 'Copy this from Launch Hub → contact → Deal & contract.', 'launchup-sales-connector' )
		);

		if ( $hasKeyConst ) {
			printf(
				'<tr><th scope="row">%s</th><td><input type="text" class="regular-text" disabled value="%s"><p class="description">%s</p></td></tr>',
				esc_html__( 'API key', 'launchup-sales-connector' ),
				esc_attr( self::displayKey( PushClient::resolveApiKey() ) ),
				esc_html__( 'Defined in wp-config.php (recommended).', 'launchup-sales-connector' )
			);
		} else {
			printf(
				'<tr><th scope="row"><label for="lusc_api_key">%s</label></th><td><input type="password" class="regular-text" id="lusc_api_key" name="lusc_api_key" value="" placeholder="%s" autocomplete="new-password"> <button type="button" class="button" id="lusc_toggle_key">%s</button><p class="description">%s</p></td></tr>',
				esc_html__( 'API key', 'launchup-sales-connector' ),
				esc_attr( '' !== $storedKey ? self::displayKey( $storedKey ) : 'lu_sk_…' ),
				esc_html__( 'Show', 'launchup-sales-connector' ),
				'' !== $storedKey
					? esc_html__( 'A key is stored. Leave empty to keep it.', 'launchup-sales-connector' )
					: esc_html__( 'Create the key in Launch Hub → contact → Deal & contract.', 'launchup-sales-connector' )
			);
		}

		echo '</table>';
		printf(
			'<p><button type="submit" name="lusc_save" value="1" class="button button-primary">%s</button> <button type="button" class="button" id="lusc_test">%s</button> <span id="lusc_test_result"></span></p>',
			esc_html__( 'Save', 'launchup-sales-connector' ),
			esc_html__( 'Test connection', 'launchup-sales-connector' )
		);
		echo '</form></div>';

		// --- Card 2: Acties ----------------------------------------------
		echo '<div class="card" style="max-width:680px">';
		echo '<h2>' . esc_html__( 'Actions', 'launchup-sales-connector' ) . '</h2><p>';
		printf(
			'<button type="button" class="button" id="lusc_push_now">%s</button> ',
			esc_html__( 'Push now', 'launchup-sales-connector' )
		);
		printf(
			'<button type="button" class="button" id="lusc_backfill" %s>%s</button> <span id="lusc_action_result"></span>',
			self::backfillEnabled( $status ) ? '' : 'disabled',
			esc_html__( 'Push history', 'launchup-sales-connector' )
		);
		echo '</p><p class="description">' . esc_html__( 'Push history sends all months since the first order and doubles as a full re-sync (e.g. after a late refund on an old order).', 'launchup-sales-connector' ) . '</p>';
		echo '</div>';

		// --- Card 3: Status ----------------------------------------------
		echo '<div class="card" style="max-width:680px">';
		echo '<h2>' . esc_html__( 'Status', 'launchup-sales-connector' ) . '</h2>';

		$labels = array(
			'green'  => '🟢 ' . __( 'Last push succeeded less than 26 hours ago', 'launchup-sales-connector' ),
			'orange' => '🟠 ' . __( 'Last attempt failed or data is stale', 'launchup-sales-connector' ),
			'grey'   => '⚪ ' . __( 'Never pushed yet', 'launchup-sales-connector' ),
		);
		printf( '<p>%s</p>', esc_html( $labels[ $indicator ] ) );
		printf(
			'<p>%s %s<br>%s %s</p>',
			esc_html__( 'Last success:', 'launchup-sales-connector' ),
			esc_html( self::localTime( $status['last_success_at'] ) ),
			esc_html__( 'Last attempt:', 'launchup-sales-connector' ),
			esc_html( self::localTime( $status['last_attempt_at'] ) )
		);

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array(
			__( 'Time', 'launchup-sales-connector' ),
			__( 'Kind', 'launchup-sales-connector' ),
			__( 'Window', 'launchup-sales-connector' ),
			__( 'Periods', 'launchup-sales-connector' ),
			__( 'HTTP', 'launchup-sales-connector' ),
			__( 'LU code', 'launchup-sales-connector' ),
			__( 'Message', 'launchup-sales-connector' ),
		) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( array() === $status['log'] ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No pushes yet.', 'launchup-sales-connector' ) . '</td></tr>';
		}
		foreach ( $status['log'] as $entry ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( self::localTime( $entry['time'] ?? null ) ),
				esc_html( (string) ( $entry['kind'] ?? '' ) ),
				esc_html( (string) ( $entry['window'] ?? '' ) ),
				esc_html( (string) ( $entry['periods'] ?? '' ) ),
				esc_html( (string) ( $entry['http'] ?? '—' ) ),
				esc_html( (string) ( $entry['lu_code'] ?? '—' ) ),
				esc_html( (string) ( $entry['message'] ?? '' ) )
			);
		}
		echo '</tbody></table></div></div>';
	}

	/**
	 * Suggest the backfill once after the first successful test (specs/03 §1).
	 *
	 * @param array<string, mixed> $status StatusStore::read() shape.
	 */
	private function renderBackfillSuggestion( array $status ): void {
		if ( ! get_option( 'lusc_first_test_ok' )
			|| get_option( 'lusc_backfill_started' )
			|| get_option( 'lusc_backfill_notice_dismissed' )
			|| ! self::backfillEnabled( $status ) ) {
			return;
		}
		printf(
			'<div class="notice notice-info is-dismissible" data-lusc-notice="backfill"><p>%s</p></div>',
			esc_html__( 'Connection works! Click "Push history" once to send all existing months to Launch Hub.', 'launchup-sales-connector' )
		);
	}

	/**
	 * Format a stored ISO timestamp in the site timezone.
	 *
	 * @param string|null $iso ISO-8601 timestamp or null.
	 */
	private static function localTime( ?string $iso ): string {
		if ( null === $iso ) {
			return '—';
		}
		$formatted = wp_date( 'Y-m-d H:i', (int) strtotime( $iso ) );
		return false === $formatted ? '—' : $formatted;
	}
}

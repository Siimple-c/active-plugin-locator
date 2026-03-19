<?php
/**
 * Admin toast wiring for Active Plugin Locator.
 *
 * @package ActivePluginLocator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles capturing activation events and rendering a one-time admin toast.
 *
 * @package ActivePluginLocator
 */
final class APL_Admin_Toast {

	/**
	 * Pending plugin basenames for this request.
	 *
	 * @var array<int, string>
	 */
	private static $pending_plugins = array();

	/**
	 * High-confidence menu discovery helper.
	 *
	 * @var APL_Menu_Discovery|null
	 */
	private static $menu_discovery = null;

	/**
	 * Medium-confidence row action discovery helper.
	 *
	 * @var APL_Row_Action_Discovery|null
	 */
	private static $row_action_discovery = null;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'activated_plugin', array( __CLASS__, 'on_single_activation' ), 10, 2 );
		add_action( 'activated_plugins', array( __CLASS__, 'on_bulk_activation' ), 10, 1 );
		// Temporarily disable Slice 3 until the runtime fatal is isolated.
		add_action( 'admin_menu', array( __CLASS__, 'capture_menu_candidates' ), PHP_INT_MAX );
		add_action( 'admin_init', array( __CLASS__, 'prepare_pending_plugins' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_footer', array( __CLASS__, 'render_mount' ) );
	}

	/**
	 * Handle single plugin activation.
	 *
	 * @param string $plugin       Plugin basename.
	 * @param bool   $network_wide Whether activated network-wide.
	 * @return void
	 */
	public static function on_single_activation( string $plugin, bool $network_wide ): void {
		unset( $network_wide );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		APL_Activation_Queue::push_for_current_user( array( $plugin ) );
	}

	/**
	 * Handle bulk plugin activation.
	 *
	 * @param array<int, string> $plugins Plugin basenames.
	 * @return void
	 */
	public static function on_bulk_activation( array $plugins ): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		APL_Activation_Queue::push_for_current_user( $plugins );
	}

	/**
	 * Load pending plugins for this request if needed.
	 *
	 * @return void
	 */
	private static function ensure_pending_plugins_loaded(): void {
		if ( ! empty( self::$pending_plugins ) ) {
			return;
		}

		if ( ! is_admin() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		self::$pending_plugins = APL_Activation_Queue::peek_for_current_user();
	}

	/**
	 * Capture high-confidence menu candidates from registered admin menus.
	 *
	 * @return void
	 */
	public static function capture_menu_candidates(): void {
		self::ensure_pending_plugins_loaded();

		if ( empty( self::$pending_plugins ) ) {
			return;
		}

		if ( ! class_exists( 'APL_Menu_Discovery' ) ) {
			return;
		}

		self::$menu_discovery = new APL_Menu_Discovery( self::$pending_plugins );
		self::$menu_discovery->capture_from_registered_menus();
	}

	/**
	 * Prepare medium-confidence row action discovery for this request.
	 *
	 * @return void
	 */
	public static function prepare_pending_plugins(): void {
		self::ensure_pending_plugins_loaded();

		if ( empty( self::$pending_plugins ) ) {
			return;
		}

		self::$row_action_discovery = new APL_Row_Action_Discovery( self::$pending_plugins );
		self::$row_action_discovery->register();
	}

	/**
	 * Enqueue toast assets only when a queue exists.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		self::ensure_pending_plugins_loaded();

		if ( empty( self::$pending_plugins ) ) {
			return;
		}

		wp_enqueue_style(
			'apl-admin-toast',
			APL_PLUGIN_URL . 'assets/admin-toast.css',
			array(),
			APL_VERSION
		);

		wp_enqueue_script(
			'apl-admin-toast',
			APL_PLUGIN_URL . 'assets/admin-toast.js',
			array(),
			APL_VERSION,
			true
		);
	}

	/**
	 * Build the final toast payload.
	 *
	 * @return array<string, mixed>
	 */
	private static function build_payload(): array {
		$items = array();

		foreach ( self::$pending_plugins as $basename ) {
			$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $basename, false, false );
			$name = '';

			if ( is_array( $data ) && ! empty( $data['Name'] ) ) {
				$name = (string) $data['Name'];
			}

			if ( '' === $name ) {
				$name = $basename;
			}

			$high_candidates = array();
			if ( self::$menu_discovery instanceof APL_Menu_Discovery ) {
				$high_candidates = self::$menu_discovery->get_candidates( $basename );
			}

			if ( ! empty( $high_candidates ) ) {
				$items[] = array(
					'plugin'  => $basename,
					'name'    => $name,
					'message' => ( 1 === count( $high_candidates ) )
						? __( 'Manage in:', 'active-plugin-locator' )
						: __( 'We found multiple management locations:', 'active-plugin-locator' ),
					'links'   => $high_candidates,
				);

				continue;
			}

			$medium_candidates = array();
			if ( self::$row_action_discovery instanceof APL_Row_Action_Discovery ) {
				$medium_candidates = self::$row_action_discovery->get_candidates( $basename );
			}

			if ( ! empty( $medium_candidates ) ) {
				$items[] = array(
					'plugin'  => $basename,
					'name'    => $name,
					'message' => ( 1 === count( $medium_candidates ) )
						? __( 'Likely manage here:', 'active-plugin-locator' )
						: __( 'We found multiple likely locations:', 'active-plugin-locator' ),
					'links'   => $medium_candidates,
				);

				continue;
			}

			$items[] = array(
				'plugin'  => $basename,
				'name'    => $name,
				'message' => __( 'No admin settings page detected. It may run automatically or appear elsewhere.', 'active-plugin-locator' ),
			);
		}

		return array(
			'title' => ( 1 === count( $items ) )
				? __( 'Plugin activated', 'active-plugin-locator' )
				: sprintf(
					/* translators: %d: number of activated plugins. */
					__( '%d plugins activated', 'active-plugin-locator' ),
					count( $items )
				),
			'items' => $items,
		);
	}

	/**
	 * Render the toast mount point and inject payload.
	 *
	 * @return void
	 */
	public static function render_mount(): void {
		self::ensure_pending_plugins_loaded();

		if ( empty( self::$pending_plugins ) ) {
			return;
		}

		$payload = self::build_payload();

		wp_add_inline_script(
			'apl-admin-toast',
			'window.APL_TOAST_DATA = ' . wp_json_encode(
				array(
					'payload' => $payload,
					'i18n'    => array(
						'close' => __( 'Dismiss', 'active-plugin-locator' ),
					),
				)
			) . ';',
			'before'
		);

		echo '<div id="apl-toast-root" aria-live="polite" aria-atomic="true"></div>';

		APL_Activation_Queue::consume_for_current_user();
	}
}

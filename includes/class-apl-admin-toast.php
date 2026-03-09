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
	 * Cached payload for this request after consuming queue.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $payload = null;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'activated_plugin', array( __CLASS__, 'on_single_activation' ), 10, 2 );
		add_action( 'activated_plugins', array( __CLASS__, 'on_bulk_activation' ), 10, 1 );

		add_action( 'admin_init', array( __CLASS__, 'prepare_payload_once' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_footer', array( __CLASS__, 'render_mount' ) );
	}

	/**
	 * Handle single plugin activation.
	 *
	 * @param string $plugin       Plugin basename (e.g., hello-dolly/hello.php).
	 * @param bool   $network_wide Whether activated network-wide (unused in V1).
	 * @return void
	 */
	public static function on_single_activation( string $plugin, bool $network_wide ): void {
		// Kept for hook signature compatibility.
		unset( $network_wide );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		APL_Activation_Queue::push_for_current_user( array( $plugin ) );
	}

	/**
	 * Handle bulk activation of plugins.
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
	 * Prepare toast payload once per activation queue consumption.
	 *
	 * @return void
	 */
	public static function prepare_payload_once(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( ! is_admin() ) {
			return;
		}

		$queue = APL_Activation_Queue::consume_for_current_user();
		if ( empty( $queue ) ) {
			return;
		}

		$items = array();
		foreach ( $queue as $basename ) {
			$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $basename, false, false );

			$name = '';
			if ( is_array( $data ) && ! empty( $data['Name'] ) ) {
				$name = (string) $data['Name'];
			}

			if ( '' === $name ) {
				$name = $basename;
			}

			$items[] = array(
				'plugin'  => $basename,
				'name'    => $name,
				// Slice 1: conservative default (no discovery yet).
				'message' => __( 'No admin settings page detected. It may run automatically or appear elsewhere.', 'active-plugin-locator' ),
			);
		}

		self::$payload = array(
			'title' => ( 1 === count( $items ) )
				? __( 'Plugin activated', 'active-plugin-locator' )
				: sprintf(
					/* translators: %d = number of plugins activated */
					__( '%d plugins activated', 'active-plugin-locator' ),
					count( $items )
				),
			'items' => $items,
		);
	}

	/**
	 * Enqueue toast assets when a payload exists.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		if ( empty( self::$payload ) ) {
			return;
		}

		$handle = 'apl-admin-toast';

		wp_enqueue_style(
			$handle,
			APL_PLUGIN_URL . 'assets/admin-toast.css',
			array(),
			APL_VERSION
		);

		wp_enqueue_script(
			$handle,
			APL_PLUGIN_URL . 'assets/admin-toast.js',
			array(),
			APL_VERSION,
			true
		);

		wp_localize_script(
			$handle,
			'APL_TOAST_DATA',
			array(
				'payload' => self::$payload,
				'i18n'    => array(
					'close' => __( 'Dismiss', 'active-plugin-locator' ),
				),
			)
		);
	}

	/**
	 * Render the toast mount point.
	 *
	 * @return void
	 */
	public static function render_mount(): void {
		if ( empty( self::$payload ) ) {
			return;
		}

		echo '<div id="apl-toast-root" aria-live="polite" aria-atomic="true"></div>';
	}
}

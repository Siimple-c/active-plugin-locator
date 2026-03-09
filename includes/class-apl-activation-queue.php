<?php
/**
 * Activation queue storage for Active Plugin Locator.
 *
 * @package ActivePluginLocator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores recently activated plugin basenames in a short-lived per-user transient.
 *
 * @package ActivePluginLocator
 */
final class APL_Activation_Queue {

	/**
	 * TTL for the activation queue transient (seconds).
	 */
	private const TTL_SECONDS = 600; // 10 minutes.

	/**
	 * Build the transient key for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function key_for_user( int $user_id ): string {
		return 'apl_activation_queue_' . $user_id;
	}

	/**
	 * Append one or more plugin basenames to the per-user queue.
	 *
	 * @param array<int, string> $plugin_basenames Plugin basenames.
	 * @return void
	 */
	public static function push_for_current_user( array $plugin_basenames ): void {
		$user_id = get_current_user_id();
		if ( 0 >= $user_id ) {
			// Slice 1: no WP-CLI/global fallback yet. Safe no-op.
			return;
		}

		$key   = self::key_for_user( $user_id );
		$queue = get_transient( $key );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		foreach ( $plugin_basenames as $basename ) {
			$basename = is_string( $basename ) ? $basename : '';
			$basename = trim( $basename );
			if ( '' === $basename ) {
				continue;
			}
			$queue[] = $basename;
		}

		$queue = array_values( array_unique( $queue ) );

		set_transient( $key, $queue, self::TTL_SECONDS );
	}

	/**
	 * Consume and clear the current user's queue (one-time behavior).
	 *
	 * @return array<int, string>
	 */
	public static function consume_for_current_user(): array {
		$user_id = get_current_user_id();
		if ( 0 >= $user_id ) {
			return array();
		}

		$key   = self::key_for_user( $user_id );
		$queue = get_transient( $key );

		delete_transient( $key );

		return is_array( $queue ) ? array_values( array_filter( $queue, 'is_string' ) ) : array();
	}
}

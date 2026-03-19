<?php
/**
 * Row action based discovery for Active Plugin Locator.
 *
 * @package ActivePluginLocator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures conservative medium-confidence candidates from plugin row action links.
 *
 * @package ActivePluginLocator
 */
final class APL_Row_Action_Discovery {

	/**
	 * Pending plugin basenames.
	 *
	 * @var array<int, string>
	 */
	private $pending_plugins = array();

	/**
	 * Captured candidates keyed by plugin basename.
	 *
	 * @var array<string, array<int, array<string, string>>>
	 */
	private $results = array();

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $pending_plugins Pending plugin basenames.
	 */
	public function __construct( array $pending_plugins ) {
		$this->pending_plugins = array_values( array_unique( array_filter( $pending_plugins, 'is_string' ) ) );
	}

	/**
	 * Register capture hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( empty( $this->pending_plugins ) ) {
			return;
		}

		add_filter( 'plugin_action_links', array( $this, 'capture_plugin_action_links' ), 100, 4 );
	}

	/**
	 * Capture plausible internal manage links from plugin row actions.
	 *
	 * @param array<string, string> $actions     Plugin action links.
	 * @param string                $plugin_file Plugin basename.
	 * @param array<string, mixed>  $plugin_data Plugin metadata.
	 * @param string                $context     Display context.
	 * @return array<string, string>
	 */
	public function capture_plugin_action_links( array $actions, string $plugin_file, array $plugin_data, string $context ): array {
		unset( $context );

		if ( ! in_array( $plugin_file, $this->pending_plugins, true ) ) {
			return $actions;
		}

		$candidates = array();

		foreach ( $actions as $action_key => $action_html ) {
			$candidate = $this->build_candidate_from_action( (string) $action_key, (string) $action_html, $plugin_file, $plugin_data );

			if ( empty( $candidate ) ) {
				continue;
			}

			$candidates[] = $candidate;
		}

		if ( empty( $candidates ) ) {
			return $actions;
		}

		usort(
			$candidates,
			static function ( array $left, array $right ): int {
				return (int) $right['score'] <=> (int) $left['score'];
			}
		);

		$deduped = array();
		$seen    = array();

		foreach ( $candidates as $candidate ) {
			$dedupe_key = $candidate['url'];

			if ( isset( $seen[ $dedupe_key ] ) ) {
				continue;
			}

			$seen[ $dedupe_key ] = true;
			$deduped[]           = array(
				'label' => $candidate['label'],
				'url'   => $candidate['url'],
			);

			if ( 2 <= count( $deduped ) ) {
				break;
			}
		}

		$this->results[ $plugin_file ] = $deduped;

		return $actions;
	}

	/**
	 * Get candidates for a plugin basename.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return array<int, array<string, string>>
	 */
	public function get_candidates( string $plugin_file ): array {
		return isset( $this->results[ $plugin_file ] ) ? $this->results[ $plugin_file ] : array();
	}

	/**
	 * Build a candidate from a plugin row action.
	 *
	 * @param string               $action_key  Action key.
	 * @param string               $action_html Action HTML.
	 * @param string               $plugin_file Plugin basename.
	 * @param array<string, mixed> $plugin_data Plugin metadata.
	 * @return array<string, string|int>
	 */
	private function build_candidate_from_action( string $action_key, string $action_html, string $plugin_file, array $plugin_data ): array {
		$href  = $this->extract_href( $action_html );
		$label = trim( wp_strip_all_tags( $action_html ) );

		if ( '' === $href || '' === $label ) {
			$this->debug_log(
				$plugin_file,
				'rejected',
				'missing_href_or_label',
				array(
					'action_key' => $action_key,
					'label'      => $label,
					'href'       => $href,
				)
			);

			return array();
		}

		$normalized_url = $this->normalize_internal_admin_url( $href );
		if ( '' === $normalized_url ) {
			$this->debug_log(
				$plugin_file,
				'rejected',
				'not_internal_wp_admin_url',
				array(
					'action_key' => $action_key,
					'label'      => $label,
					'href'       => $href,
				)
			);

			return array();
		}

		if ( $this->is_generic_action( $action_key, $label, $normalized_url ) ) {
			$this->debug_log(
				$plugin_file,
				'rejected',
				'generic_action',
				array(
					'action_key' => $action_key,
					'label'      => $label,
					'url'        => $normalized_url,
				)
			);

			return array();
		}

		if ( ! $this->looks_plugin_specific( $normalized_url, $plugin_file, $plugin_data, $action_key, $label ) ) {
			$this->debug_log(
				$plugin_file,
				'rejected',
				'not_plugin_specific',
				array(
					'action_key' => $action_key,
					'label'      => $label,
					'url'        => $normalized_url,
				)
			);

			return array();
		}

		$candidate = array(
			'label' => $label,
			'url'   => esc_url_raw( $normalized_url ),
			'score' => $this->score_candidate( $action_key, $label, $normalized_url ),
		);

		$this->debug_log(
			$plugin_file,
			'accepted',
			'medium_confidence_candidate',
			$candidate
		);

		return $candidate;
	}

	/**
	 * Extract href from an anchor HTML string.
	 *
	 * @param string $action_html Action HTML.
	 * @return string
	 */
	private function extract_href( string $action_html ): string {
		if ( ! preg_match( '/href=(["\'])(.*?)\1/i', $action_html, $matches ) ) {
			return '';
		}

		return html_entity_decode( $matches[2], ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Normalize and validate an internal wp-admin URL.
	 *
	 * @param string $raw_url Raw URL.
	 * @return string
	 */
	private function normalize_internal_admin_url( string $raw_url ): string {
		$raw_url = trim( $raw_url );
		if ( '' === $raw_url ) {
			return '';
		}

		$parts       = wp_parse_url( $raw_url );
		$admin_parts = wp_parse_url( admin_url() );

		if ( false === $parts || false === $admin_parts ) {
			return '';
		}

		if ( isset( $parts['host'] ) ) {
			$admin_host = isset( $admin_parts['host'] ) ? strtolower( (string) $admin_parts['host'] ) : '';
			$url_host   = strtolower( (string) $parts['host'] );

			if ( '' === $admin_host || $url_host !== $admin_host ) {
				return '';
			}

			$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
			if ( false === strpos( $path, '/wp-admin/' ) ) {
				return '';
			}

			return $raw_url;
		}

		if ( preg_match( '#^[a-z0-9\-_]+\.php(\?.*)?$#i', $raw_url ) ) {
			return admin_url( $raw_url );
		}

		if ( 0 === strpos( ltrim( $raw_url, '/' ), 'wp-admin/' ) ) {
			return home_url( '/' . ltrim( $raw_url, '/' ) );
		}

		return '';
	}

	/**
	 * Reject clearly generic plugin row actions.
	 *
	 * @param string $action_key Action key.
	 * @param string $label      Action label.
	 * @param string $url        Normalized URL.
	 * @return bool
	 */
	private function is_generic_action( string $action_key, string $label, string $url ): bool {
		$generic_keys = array(
			'activate',
			'deactivate',
			'delete',
			'edit',
			'resume',
			'details',
			'network_activate',
			'network_deactivate',
		);

		if ( in_array( strtolower( $action_key ), $generic_keys, true ) ) {
			return true;
		}

		$lower_label    = strtolower( $label );
		$generic_labels = array(
			'activate',
			'deactivate',
			'delete',
			'edit',
			'details',
			'visit plugin site',
		);

		if ( in_array( $lower_label, $generic_labels, true ) ) {
			return true;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path = is_string( $path ) ? basename( $path ) : '';

		$generic_pages = array(
			'plugins.php',
			'plugin-install.php',
			'plugin-editor.php',
			'update.php',
		);

		return in_array( $path, $generic_pages, true );
	}

	/**
	 * Determine whether a destination looks plugin-specific enough for medium confidence.
	 *
	 * @param string               $url         Candidate URL.
	 * @param string               $plugin_file Plugin basename.
	 * @param array<string, mixed> $plugin_data Plugin metadata.
	 * @param string               $action_key  Action key.
	 * @param string               $label       Action label.
	 * @return bool
	 */
	private function looks_plugin_specific( string $url, string $plugin_file, array $plugin_data, string $action_key, string $label ): bool {
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		$vars  = array();

		if ( is_string( $query ) ) {
			wp_parse_str( $query, $vars );
		}

		$page      = isset( $vars['page'] ) ? sanitize_key( (string) $vars['page'] ) : '';
		$post_type = isset( $vars['post_type'] ) ? sanitize_key( (string) $vars['post_type'] ) : '';

		$tokens = $this->build_plugin_tokens( $plugin_file, $plugin_data );

		if ( '' !== $page && $this->contains_any_token( $page, $tokens ) ) {
			return true;
		}

		if ( '' !== $post_type && $this->contains_any_token( $post_type, $tokens ) ) {
			return true;
		}

		if ( '' !== $page && $this->is_manage_like_action( $action_key, $label ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Assign a score so the most likely candidates appear first.
	 *
	 * @param string $action_key Action key.
	 * @param string $label      Action label.
	 * @param string $url        Candidate URL.
	 * @return int
	 */
	private function score_candidate( string $action_key, string $label, string $url ): int {
		$score = 10;

		if ( $this->is_manage_like_action( $action_key, $label ) ) {
			$score += 30;
		}

		$query = wp_parse_url( $url, PHP_URL_QUERY );
		$vars  = array();

		if ( is_string( $query ) ) {
			wp_parse_str( $query, $vars );
		}

		if ( ! empty( $vars['page'] ) ) {
			$score += 20;
		}

		if ( ! empty( $vars['post_type'] ) ) {
			$score += 10;
		}

		return $score;
	}

	/**
	 * Check whether an action looks like a manage/settings entry point.
	 *
	 * @param string $action_key Action key.
	 * @param string $label      Action label.
	 * @return bool
	 */
	private function is_manage_like_action( string $action_key, string $label ): bool {
		$value = strtolower( $action_key . ' ' . $label );

		$needles = array(
			'settings',
			'configure',
			'config',
			'setup',
			'wizard',
			'dashboard',
			'options',
			'manage',
		);

		foreach ( $needles as $needle ) {
			if ( false !== strpos( $value, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build plugin-specific tokens for URL matching.
	 *
	 * @param string               $plugin_file Plugin basename.
	 * @param array<string, mixed> $plugin_data Plugin metadata.
	 * @return array<int, string>
	 */
	private function build_plugin_tokens( string $plugin_file, array $plugin_data ): array {
		$tokens = array();

		$dir_name  = dirname( $plugin_file );
		$file_name = basename( $plugin_file, '.php' );
		$name      = isset( $plugin_data['Name'] ) ? (string) $plugin_data['Name'] : '';

		foreach ( array( $dir_name, $file_name, $name ) as $source ) {
			$pieces = preg_split( '/[^a-z0-9]+/i', strtolower( $source ) );
			if ( ! is_array( $pieces ) ) {
				continue;
			}

			foreach ( $pieces as $piece ) {
				if ( 2 >= strlen( $piece ) ) {
					continue;
				}
				$tokens[] = $piece;
			}
		}

		return array_values( array_unique( $tokens ) );
	}

	/**
	 * Check whether a haystack contains any plugin token.
	 *
	 * @param string             $haystack Haystack string.
	 * @param array<int, string> $tokens   Tokens.
	 * @return bool
	 */
	private function contains_any_token( string $haystack, array $tokens ): bool {
		$haystack = strtolower( $haystack );

		foreach ( $tokens as $token ) {
			if ( false !== strpos( $haystack, $token ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Write a debug line when local debugging is enabled.
	 *
	 * @param string               $plugin_file Plugin basename.
	 * @param string               $status      accepted|rejected.
	 * @param string               $reason      Reason code.
	 * @param array<string, mixed> $context     Extra context.
	 * @return void
	 */
	private function debug_log( string $plugin_file, string $status, string $reason, array $context = array() ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$payload = array(
			'plugin' => $plugin_file,
			'status' => $status,
			'reason' => $reason,
			'data'   => $context,
		);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only local diagnostics for Slice 2 candidate rejection analysis.
		error_log( 'APL_ROW_ACTION ' . wp_json_encode( $payload ) );
	}
}

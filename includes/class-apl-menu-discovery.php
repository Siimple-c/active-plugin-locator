<?php
/**
 * High-confidence menu discovery for Active Plugin Locator.
 *
 * @package ActivePluginLocator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures high-confidence admin menu candidates by resolving page callbacks
 * to files inside the activated plugin directory.
 *
 * @package ActivePluginLocator
 */
final class APL_Menu_Discovery {

	/**
	 * Pending plugin basenames.
	 *
	 * @var array<int, string>
	 */
	private $pending_plugins = array();

	/**
	 * Raw candidate results keyed by plugin basename.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private $results = array();

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $pending_plugins Pending plugin basenames.
	 */
	public function __construct( array $pending_plugins ) {
		$this->pending_plugins = array_values(
			array_unique(
				array_filter( $pending_plugins, 'is_string' )
			)
		);
	}

	/**
	 * Capture candidates from the currently registered admin menus.
	 *
	 * @return void
	 */
	public function capture_from_registered_menus(): void {
		global $menu, $submenu;

		if ( empty( $this->pending_plugins ) ) {
			return;
		}

		$parent_labels = $this->build_parent_labels( is_array( $menu ) ? $menu : array() );

		if ( is_array( $menu ) ) {
			foreach ( $menu as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				$candidate = $this->build_candidate_from_menu_entry( '', $entry, $parent_labels );
				if ( empty( $candidate ) ) {
					continue;
				}

				$this->store_candidate( $candidate );
			}
		}

		if ( is_array( $submenu ) ) {
			foreach ( $submenu as $parent_slug => $entries ) {
				if ( ! is_array( $entries ) ) {
					continue;
				}

				foreach ( $entries as $entry ) {
					if ( ! is_array( $entry ) ) {
						continue;
					}

					$candidate = $this->build_candidate_from_menu_entry( (string) $parent_slug, $entry, $parent_labels );
					if ( empty( $candidate ) ) {
						continue;
					}

					$this->store_candidate( $candidate );
				}
			}
		}
	}

	/**
	 * Return sorted, deduplicated candidates for a plugin.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return array<int, array<string, string>>
	 */
	public function get_candidates( string $plugin_file ): array {
		$candidates = isset( $this->results[ $plugin_file ] ) ? $this->results[ $plugin_file ] : array();

		if ( empty( $candidates ) ) {
			return array();
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

			if ( 3 <= count( $deduped ) ) {
				break;
			}
		}

		return $deduped;
	}

	/**
	 * Store a raw candidate.
	 *
	 * @param array<string, mixed> $candidate Candidate data.
	 * @return void
	 */
	private function store_candidate( array $candidate ): void {
		$plugin_file = (string) $candidate['plugin'];

		if ( ! isset( $this->results[ $plugin_file ] ) ) {
			$this->results[ $plugin_file ] = array();
		}

		$this->results[ $plugin_file ][] = $candidate;
	}

	/**
	 * Build a label map for top-level parents.
	 *
	 * @param array<int|string, mixed> $menu Registered top-level menu array.
	 * @return array<string, string>
	 */
	private function build_parent_labels( array $menu ): array {
		$labels = array();

		foreach ( $menu as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry[2] ) ) {
				continue;
			}

			$slug  = (string) $entry[2];
			$label = $this->normalize_menu_label( isset( $entry[0] ) ? (string) $entry[0] : '' );

			if ( '' === $slug || '' === $label ) {
				continue;
			}

			$labels[ $slug ] = $label;
		}

		return $labels;
	}

	/**
	 * Build a high-confidence candidate from a menu or submenu entry.
	 *
	 * @param string                   $parent_slug   Parent menu slug.
	 * @param array<int|string, mixed> $entry         Menu entry.
	 * @param array<string, string>    $parent_labels Parent labels keyed by slug.
	 * @return array<string, mixed>
	 */
	private function build_candidate_from_menu_entry( string $parent_slug, array $entry, array $parent_labels ): array {
		$menu_slug  = isset( $entry[2] ) ? (string) $entry[2] : '';
		$menu_label = $this->normalize_menu_label( isset( $entry[0] ) ? (string) $entry[0] : '' );

		if ( '' === $menu_slug || '' === $menu_label ) {
			return array();
		}

		$page_hook = $this->resolve_page_hook( $menu_slug, $parent_slug, $entry );
		if ( '' === $page_hook ) {
			$this->debug_log(
				'',
				'rejected',
				'no_page_hook',
				array(
					'parent_slug' => $parent_slug,
					'menu_slug'   => $menu_slug,
					'menu_label'  => $menu_label,
				)
			);

			return array();
		}

		$callback_files = $this->get_callback_files_for_page_hook( $page_hook );
		if ( empty( $callback_files ) ) {
			$this->debug_log(
				'',
				'rejected',
				'no_callback_files',
				array(
					'page_hook'  => $page_hook,
					'menu_slug'  => $menu_slug,
					'menu_label' => $menu_label,
				)
			);

			return array();
		}

		$url = menu_page_url( $menu_slug, false );
		if ( '' === $url ) {
			return array();
		}

		$url = esc_url_raw( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) );

		foreach ( $this->pending_plugins as $plugin_file ) {
			if ( ! $this->can_use_high_confidence_for_plugin( $plugin_file ) ) {
				continue;
			}

			foreach ( $callback_files as $callback_file ) {
				if ( ! $this->callback_belongs_to_plugin( $plugin_file, $callback_file ) ) {
					continue;
				}

				$candidate = array(
					'plugin' => $plugin_file,
					'label'  => $this->build_label_path( $parent_slug, $menu_label, $parent_labels ),
					'url'    => $url,
					'score'  => $this->score_candidate( $parent_slug, $menu_label ),
				);

				$this->debug_log(
					$plugin_file,
					'accepted',
					'high_confidence_candidate',
					array(
						'label'         => $candidate['label'],
						'url'           => $candidate['url'],
						'page_hook'     => $page_hook,
						'callback_file' => $callback_file,
					)
				);

				return $candidate;
			}
		}

		$this->debug_log(
			'',
			'rejected',
			'callback_not_owned_by_pending_plugin',
			array(
				'parent_slug'    => $parent_slug,
				'menu_slug'      => $menu_slug,
				'menu_label'     => $menu_label,
				'page_hook'      => $page_hook,
				'callback_files' => $callback_files,
			)
		);

		return array();
	}

	/**
	 * Resolve the page hook for a menu entry.
	 *
	 * @param string                   $menu_slug   Menu slug.
	 * @param string                   $parent_slug Parent slug.
	 * @param array<int|string, mixed> $entry       Menu entry.
	 * @return string
	 */
	private function resolve_page_hook( string $menu_slug, string $parent_slug, array $entry ): string {
		if ( '' === $parent_slug ) {
			$hook = isset( $entry[5] ) ? (string) $entry[5] : '';
			if ( '' !== $hook ) {
				return $hook;
			}

			$page_hook = get_plugin_page_hook( $menu_slug, '' );

			return is_string( $page_hook ) ? $page_hook : '';
		}

		$page_hook = get_plugin_page_hook( $menu_slug, $parent_slug );

		return is_string( $page_hook ) ? $page_hook : '';
	}

	/**
	 * Get callback file paths attached to a page hook.
	 *
	 * @param string $page_hook Page hook.
	 * @return array<int, string>
	 */
	private function get_callback_files_for_page_hook( string $page_hook ): array {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $page_hook ] ) || ! ( $wp_filter[ $page_hook ] instanceof WP_Hook ) ) {
			return array();
		}

		$files = array();

		foreach ( $wp_filter[ $page_hook ]->callbacks as $callbacks ) {
			if ( ! is_array( $callbacks ) ) {
				continue;
			}

			foreach ( $callbacks as $callback_data ) {
				if ( empty( $callback_data['function'] ) ) {
					continue;
				}

				$file = $this->resolve_callback_file( $callback_data['function'] );
				if ( '' === $file ) {
					continue;
				}

				$files[] = $file;
			}
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Resolve a callback to the file where it is defined.
	 *
	 * @param callable|string|array $callback Callback definition.
	 * @return string
	 */
	private function resolve_callback_file( $callback ): string {
		try {
			if ( is_array( $callback ) && 2 === count( $callback ) ) {
				$reflection = new ReflectionMethod( $callback[0], (string) $callback[1] );
			} elseif ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				$parts = explode( '::', $callback, 2 );

				if ( 2 !== count( $parts ) ) {
					return '';
				}

				$reflection = new ReflectionMethod( $parts[0], $parts[1] );
			} elseif ( $callback instanceof Closure || is_string( $callback ) ) {
				$reflection = new ReflectionFunction( $callback );
			} else {
				return '';
			}

			$file = $reflection->getFileName();

			return is_string( $file ) ? wp_normalize_path( $file ) : '';
		} catch ( ReflectionException $exception ) {
			return '';
		}
	}

	/**
	 * Whether a plugin basename can safely participate in high-confidence matching.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return bool
	 */
	private function can_use_high_confidence_for_plugin( string $plugin_file ): bool {
		return '' !== $this->get_plugin_root_path( $plugin_file );
	}

	/**
	 * Get the normalized plugin root directory for a plugin basename.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return string
	 */
	private function get_plugin_root_path( string $plugin_file ): string {
		$plugin_dir = dirname( $plugin_file );

		if ( '.' === $plugin_dir || '' === $plugin_dir ) {
			return '';
		}

		return trailingslashit(
			wp_normalize_path(
				WP_PLUGIN_DIR . '/' . trim( $plugin_dir, '/' )
			)
		);
	}

	/**
	 * Check whether a resolved callback file belongs to the plugin directory.
	 *
	 * @param string $plugin_file   Plugin basename.
	 * @param string $callback_file Callback file.
	 * @return bool
	 */
	private function callback_belongs_to_plugin( string $plugin_file, string $callback_file ): bool {
		$plugin_root = $this->get_plugin_root_path( $plugin_file );

		if ( '' === $plugin_root ) {
			return false;
		}

		return 0 === strpos( wp_normalize_path( $callback_file ), $plugin_root );
	}

	/**
	 * Normalize a menu label.
	 *
	 * @param string $label Raw menu label.
	 * @return string
	 */
	private function normalize_menu_label( string $label ): string {
		$label = preg_replace( '/\s+/', ' ', $label );
		$label = is_string( $label ) ? $label : '';

		return trim( wp_strip_all_tags( $label ) );
	}

	/**
	 * Build a display path such as "Settings → Plugin".
	 *
	 * @param string                $parent_slug   Parent slug.
	 * @param string                $menu_label    Menu label.
	 * @param array<string, string> $parent_labels Parent labels.
	 * @return string
	 */
	private function build_label_path( string $parent_slug, string $menu_label, array $parent_labels ): string {
		if ( '' === $parent_slug || ! isset( $parent_labels[ $parent_slug ] ) ) {
			return $menu_label;
		}

		$parent_label = $parent_labels[ $parent_slug ];

		if ( $parent_label === $menu_label ) {
			return $parent_label;
		}

		return $parent_label . ' → ' . $menu_label;
	}

	/**
	 * Score a candidate for ordering only.
	 *
	 * @param string $parent_slug Parent slug.
	 * @param string $menu_label  Menu label.
	 * @return int
	 */
	private function score_candidate( string $parent_slug, string $menu_label ): int {
		$score = 100;

		if ( '' === $parent_slug ) {
			$score += 10;
		}

		if ( $this->is_manage_like_label( $menu_label ) ) {
			$score += 5;
		}

		return $score;
	}

	/**
	 * Check whether a label looks like a settings/manage destination.
	 *
	 * @param string $label Menu label.
	 * @return bool
	 */
	private function is_manage_like_label( string $label ): bool {
		$label   = strtolower( $label );
		$needles = array(
			'settings',
			'dashboard',
			'options',
			'manage',
			'setup',
			'welcome',
			'tools',
		);

		foreach ( $needles as $needle ) {
			if ( false !== strpos( $label, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Write a debug line when local debugging is enabled.
	 *
	 * @param string               $plugin_file Plugin basename.
	 * @param string               $status      accepted|rejected|info.
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

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Temporary local diagnostics for Slice 3 menu ownership analysis.
		error_log( 'APL_MENU ' . wp_json_encode( $payload ) );
	}
}

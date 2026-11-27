<?php
/**
 * Plugin Name:       ForgeHub IndexNow
 * Plugin URI:        https://wpforgehub.com/product/indexnow/
 * Description:       Instant search engine indexing via the IndexNow protocol with automatic API key hosting, publish pings, and submission logs.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            WPForgeHub
 * Author URI:        https://wpforgehub.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       forgehub-indexnow
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPFH_IN_VERSION', '1.0.0' );
define( 'WPFH_IN_FILE', __FILE__ );
define( 'WPFH_IN_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPFH_IN_URL', plugin_dir_url( __FILE__ ) );

// <wpfh-pro-only>
/**
 * Freemius SDK helper wrapper (included only in the Pro build).
 *
 * @return object
 */
if ( ! function_exists( 'wpfh_in_fs' ) ) {
	function wpfh_in_fs() {
		global $wpfh_in_fs;

		if ( ! isset( $wpfh_in_fs ) ) {
			$fs_config = apply_filters(
				'wpfh_in_fs_config',
				array(
					'id'         => get_option( 'wpfh_in_fs_id', '' ),
					'public_key' => get_option( 'wpfh_in_fs_public_key', '' ),
				)
			);
			$force_pro = defined( 'WPFH_IN_FORCE_PRO' ) && WPFH_IN_FORCE_PRO;
			$fs_path   = WPFH_IN_PATH . 'freemius/start.php';

			if ( ! $force_pro && ! empty( $fs_config['id'] ) && ! empty( $fs_config['public_key'] ) && file_exists( $fs_path ) ) {
				require_once $fs_path;
				$wpfh_in_fs = fs_dynamic_init(
					array(
						'id'             => (string) $fs_config['id'],
						'slug'           => 'forgehub-indexnow',
						'type'           => 'plugin',
						'public_key'     => (string) $fs_config['public_key'],
						'is_premium'     => false,
						'has_addons'     => false,
						'has_paid_plans' => true,
						'menu'           => array(
							'slug'       => 'forgehub-indexnow',
							'first-path' => 'options-general.php?page=forgehub-indexnow',
							'parent'     => array(
								'slug' => 'options-general.php',
							),
						),
					)
				);
				$wpfh_in_fs->add_action( 'after_uninstall', 'wpfh_in_uninstall_cleanup' );
			} else {
				$wpfh_in_fs = new class( $force_pro ) {
					private $force_pro;

					public function __construct( $force_pro = false ) {
						$this->force_pro = (bool) $force_pro;
					}

					public function can_use_premium_code() {
						if ( $this->force_pro ) {
							return true;
						}
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
						return (bool) apply_filters( 'fs_can_use_premium_code__forgehub-indexnow', false );
					}

					public function is__premium_only() {
						return $this->can_use_premium_code();
					}

					public function is_plan( $plan ) {
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
						return (bool) apply_filters( 'fs_is_plan__forgehub-indexnow', false, $plan );
					}

					public function get_upgrade_url() {
						return 'https://wpforgehub.com/product/indexnow/#pricing';
					}
				};
			}
		}

		return $wpfh_in_fs;
	}

	wpfh_in_fs();
}

/**
 * Cleanup plugin data on uninstall (Pro build via after_uninstall hook).
 *
 * @return void
 */
function wpfh_in_uninstall_cleanup() {
	delete_option( 'wpfh_in_settings' );
	delete_option( 'wpfh_in_pro_settings' );
	delete_option( 'wpfh_in_logs' );
	delete_option( 'wpfh_in_retry_queue' );
	delete_option( 'wpfh_in_review_nudge' );
	wp_clear_scheduled_hook( 'wpfh_in_retry_event' );
}
// </wpfh-pro-only>

/**
 * Return the canonical upgrade URL.
 *
 * @return string
 */
function wpfh_in_get_upgrade_url() {
	$url = 'https://wpforgehub.com/product/indexnow/#pricing';
	// <wpfh-pro-only>
	if ( function_exists( 'wpfh_in_fs' ) ) {
		$url = wpfh_in_fs()->get_upgrade_url();
	}
	// </wpfh-pro-only>
	return $url;
}

/**
 * Check whether Pro features are available and licensed.
 *
 * @return bool
 */
function wpfh_in_is_pro() {
	$is_pro = false;
	// <wpfh-pro-only>
	$is_pro = file_exists( WPFH_IN_PATH . 'includes/pro/class-wpfh-in-pro.php' )
		&& function_exists( 'wpfh_in_fs' )
		&& wpfh_in_fs()->can_use_premium_code();
	// </wpfh-pro-only>
	return $is_pro;
}

/**
 * Main Plugin Bootstrap Class.
 */
final class WPFH_IndexNow {

	/**
	 * Singleton instance.
	 *
	 * @var WPFH_IndexNow|null
	 */
	private static $instance = null;

	/**
	 * Review nudge instance.
	 *
	 * @var WPFH_Review_Nudge|null
	 */
	private $review_nudge = null;

	/**
	 * Return singleton instance.
	 *
	 * @return WPFH_IndexNow
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ), 10 );
		add_filter( 'plugin_action_links_' . plugin_basename( WPFH_IN_FILE ), array( $this, 'action_links' ) );
		register_activation_hook( WPFH_IN_FILE, array( $this, 'on_activate' ) );
		register_deactivation_hook( WPFH_IN_FILE, array( $this, 'on_deactivate' ) );
	}

	/**
	 * Initialize default options and generate an IndexNow API key on activation.
	 *
	 * @return void
	 */
	public function on_activate() {
		require_once WPFH_IN_PATH . 'includes/class-wpfh-in-settings.php';
		$existing = get_option( WPFH_IN_Settings::OPTION_NAME, false );
		if ( false === $existing || ! is_array( $existing ) ) {
			$defaults            = WPFH_IN_Settings::get_defaults();
			$defaults['api_key'] = WPFH_IN_Settings::generate_api_key();
			add_option( WPFH_IN_Settings::OPTION_NAME, $defaults );
		} elseif ( empty( $existing['api_key'] ) ) {
			$existing['api_key'] = WPFH_IN_Settings::generate_api_key();
			update_option( WPFH_IN_Settings::OPTION_NAME, $existing );
		}
	}

	/**
	 * Clear scheduled retry cron on deactivation.
	 *
	 * @return void
	 */
	public function on_deactivate() {
		wp_clear_scheduled_hook( 'wpfh_in_retry_event' );
	}

	/**
	 * Initialize plugin modules.
	 *
	 * @return void
	 */
	public function init() {
		require_once WPFH_IN_PATH . 'includes/class-wpfh-review-nudge.php';
		require_once WPFH_IN_PATH . 'includes/class-wpfh-in-settings.php';
		require_once WPFH_IN_PATH . 'includes/class-wpfh-in-engine.php';
		require_once WPFH_IN_PATH . 'includes/class-wpfh-in-admin.php';

		$this->review_nudge = new WPFH_Review_Nudge(
			array(
				'plugin_slug'   => 'forgehub-indexnow',
				'plugin_name'   => 'ForgeHub IndexNow',
				'option_prefix' => 'wpfh_in',
				'github_url'    => 'https://github.com/WpForgeHub/forgehub-indexnow',
				'min_actions'   => 5,
				'min_days'      => 7,
				'trigger_mode'  => 'or',
				'screens'       => array( 'settings_page_forgehub-indexnow' ),
			)
		);
		$this->review_nudge->register();

		WPFH_IN_Engine::get_instance();
		WPFH_IN_Admin::get_instance();

		// <wpfh-pro-only>
		if ( wpfh_in_is_pro() ) {
			$pro_file = WPFH_IN_PATH . 'includes/pro/class-wpfh-in-pro.php';
			if ( file_exists( $pro_file ) ) {
				require_once $pro_file;
				WPFH_IN_Pro::get_instance();
			}
		}
		// </wpfh-pro-only>
	}

	/**
	 * Return review nudge instance.
	 *
	 * @return WPFH_Review_Nudge|null
	 */
	public function get_review_nudge() {
		return $this->review_nudge;
	}

	/**
	 * Add Settings and calm Priority Support & Pro links to the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=forgehub-indexnow' ) ),
			esc_html__( 'Settings', 'forgehub-indexnow' )
		);
		array_unshift( $links, $settings_link );

		if ( ! wpfh_in_is_pro() ) {
			$links[] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( wpfh_in_get_upgrade_url() ),
				esc_html__( 'Priority Support & Pro', 'forgehub-indexnow' )
			);
		}

		return $links;
	}
}

WPFH_IndexNow::get_instance();

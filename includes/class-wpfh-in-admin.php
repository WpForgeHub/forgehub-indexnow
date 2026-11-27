<?php
/**
 * Admin settings page and AJAX handlers for ForgeHub IndexNow.
 *
 * @package ForgeHub_IndexNow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPFH_IN_Admin
 */
class WPFH_IN_Admin {

	/**
	 * Singleton instance.
	 *
	 * @var WPFH_IN_Admin|null
	 */
	private static $instance = null;

	/**
	 * Return singleton instance.
	 *
	 * @return WPFH_IN_Admin
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
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wpfh_in_manual_ping', array( $this, 'ajax_manual_ping' ) );
		add_action( 'wp_ajax_wpfh_in_verify_key', array( $this, 'ajax_verify_key' ) );
		add_action( 'wp_ajax_wpfh_in_regenerate_key', array( $this, 'ajax_regenerate_key' ) );
		add_action( 'wp_ajax_wpfh_in_clear_logs', array( $this, 'ajax_clear_logs' ) );
	}

	/**
	 * Register Settings > IndexNow submenu page.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_options_page(
			__( 'ForgeHub IndexNow', 'forgehub-indexnow' ),
			__( 'IndexNow', 'forgehub-indexnow' ),
			'manage_options',
			'forgehub-indexnow',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'wpfh_in_settings_group',
			WPFH_IN_Settings::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'WPFH_IN_Settings', 'sanitize' ),
				'default'           => WPFH_IN_Settings::get_defaults(),
			)
		);
	}

	/**
	 * Enqueue admin CSS and JS with filemtime() cache busting.
	 *
	 * @param string $hook Current admin hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'settings_page_forgehub-indexnow' !== $hook ) {
			return;
		}

		$css_file = WPFH_IN_PATH . 'assets/css/admin.css';
		$js_file  = WPFH_IN_PATH . 'assets/js/admin.js';

		wp_enqueue_style(
			'wpfh-in-admin',
			WPFH_IN_URL . 'assets/css/admin.css',
			array(),
			file_exists( $css_file ) ? (string) filemtime( $css_file ) : WPFH_IN_VERSION
		);

		wp_enqueue_script(
			'wpfh-in-admin',
			WPFH_IN_URL . 'assets/js/admin.js',
			array(),
			file_exists( $js_file ) ? (string) filemtime( $js_file ) : WPFH_IN_VERSION,
			true
		);

		wp_localize_script(
			'wpfh-in-admin',
			'wpfhInAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wpfh_in_admin_nonce' ),
				'i18n'    => array(
					'sending'      => __( 'Submitting...', 'forgehub-indexnow' ),
					'verifying'    => __( 'Verifying endpoint...', 'forgehub-indexnow' ),
					'regenerating' => __( 'Regenerating...', 'forgehub-indexnow' ),
					'clearing'     => __( 'Clearing...', 'forgehub-indexnow' ),
					'networkError' => __( 'Network error. Please try again.', 'forgehub-indexnow' ),
					'emptyUrl'     => __( 'Please enter a valid URL on this site.', 'forgehub-indexnow' ),
					'confirmRegen' => __( 'Regenerate your IndexNow API key? Your virtual .txt file URL will update immediately.', 'forgehub-indexnow' ),
					'confirmClear' => __( 'Clear all saved submission logs?', 'forgehub-indexnow' ),
				),
			)
		);
	}

	/**
	 * AJAX: Submit a single URL manually.
	 *
	 * @return void
	 */
	public function ajax_manual_ping() {
		check_ajax_referer( 'wpfh_in_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'forgehub-indexnow' ) ), 403 );
		}

		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		if ( '' === $url ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid URL.', 'forgehub-indexnow' ) ), 400 );
		}

		$result = WPFH_IN_Engine::get_instance()->submit_single_url( $url, 'manual' );
		if ( $result['success'] ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result );
	}

	/**
	 * AJAX: Verify the virtual .txt API key endpoint.
	 *
	 * @return void
	 */
	public function ajax_verify_key() {
		check_ajax_referer( 'wpfh_in_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'forgehub-indexnow' ) ), 403 );
		}

		$settings = WPFH_IN_Settings::get();
		$key_url  = WPFH_IN_Settings::get_key_location( $settings['api_key'] );
		$response = wp_remote_get(
			$key_url,
			array(
				'timeout'   => 10,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				array(
					'message' => $response->get_error_message(),
					'url'     => $key_url,
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = trim( (string) wp_remote_retrieve_body( $response ) );

		if ( 200 === $code && $body === $settings['api_key'] ) {
			wp_send_json_success(
				array(
					'status'  => $code,
					'url'     => $key_url,
					'message' => __( 'Verified! Virtual .txt endpoint returned HTTP 200 with your exact IndexNow API key.', 'forgehub-indexnow' ),
				)
			);
		}

		wp_send_json_error(
			array(
				'status'  => $code,
				'url'     => $key_url,
				'message' => sprintf(
					/* translators: %d: HTTP status code returned by key URL */
					__( 'Key endpoint returned HTTP %d. Ensure pretty permalinks are enabled or server rules allow .txt routes.', 'forgehub-indexnow' ),
					$code
				),
			)
		);
	}

	/**
	 * AJAX: Regenerate the IndexNow API key.
	 *
	 * @return void
	 */
	public function ajax_regenerate_key() {
		check_ajax_referer( 'wpfh_in_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'forgehub-indexnow' ) ), 403 );
		}

		$settings            = WPFH_IN_Settings::get();
		$settings['api_key'] = WPFH_IN_Settings::generate_api_key();
		update_option( WPFH_IN_Settings::OPTION_NAME, $settings );

		wp_send_json_success(
			array(
				'api_key'      => $settings['api_key'],
				'key_location' => WPFH_IN_Settings::get_key_location( $settings['api_key'] ),
				'message'      => __( 'New IndexNow API key generated and active immediately.', 'forgehub-indexnow' ),
			)
		);
	}

	/**
	 * AJAX: Clear stored submission logs.
	 *
	 * @return void
	 */
	public function ajax_clear_logs() {
		check_ajax_referer( 'wpfh_in_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'forgehub-indexnow' ) ), 403 );
		}

		update_option( WPFH_IN_Settings::LOGS_OPTION, array(), false );
		wp_send_json_success(
			array(
				'message' => __( 'Submission log cleared.', 'forgehub-indexnow' ),
			)
		);
	}

	/**
	 * Render the Settings > IndexNow page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings    = WPFH_IN_Settings::get();
		$key_url     = WPFH_IN_Settings::get_key_location( $settings['api_key'] );
		$endpoints   = WPFH_IN_Settings::get_endpoints();
		$logs        = WPFH_IN_Settings::get_logs();
		$is_pro      = wpfh_in_is_pro();
		$core_types  = array(
			'post'    => __( 'Posts', 'forgehub-indexnow' ),
			'page'    => __( 'Pages', 'forgehub-indexnow' ),
			'product' => __( 'WooCommerce Products', 'forgehub-indexnow' ),
		);
		?>
		<div class="wrap wpfh-in-wrap">
			<div class="wpfh-in-header">
				<div>
					<h1><?php esc_html_e( 'ForgeHub IndexNow', 'forgehub-indexnow' ); ?></h1>
					<p class="wpfh-in-subtitle">
						<?php esc_html_e( 'Instant search engine indexing with zero external SaaS proxies. Automatically notifies Bing, Yandex, and IndexNow partners on publish and update.', 'forgehub-indexnow' ); ?>
					</p>
				</div>
				<span class="wpfh-in-badge <?php echo $is_pro ? 'wpfh-in-badge-pro' : 'wpfh-in-badge-free'; ?>">
					<?php echo $is_pro ? esc_html__( 'Pro Active', 'forgehub-indexnow' ) : esc_html__( 'Free Edition', 'forgehub-indexnow' ); ?>
				</span>
			</div>

			<div class="wpfh-in-grid">
				<div class="wpfh-in-card">
					<h2><?php esc_html_e( 'IndexNow Configuration', 'forgehub-indexnow' ); ?></h2>
					<form method="post" action="options.php">
						<?php settings_fields( 'wpfh_in_settings_group' ); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Auto-Submission', 'forgehub-indexnow' ); ?></th>
								<td>
									<label for="wpfh-in-enabled">
										<input type="checkbox" id="wpfh-in-enabled" name="<?php echo esc_attr( WPFH_IN_Settings::OPTION_NAME ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
										<?php esc_html_e( 'Enable automatic IndexNow pings when content changes', 'forgehub-indexnow' ); ?>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wpfh-in-api-key"><?php esc_html_e( 'IndexNow API Key', 'forgehub-indexnow' ); ?></label></th>
								<td>
									<div class="wpfh-in-inline-row">
										<input type="text" id="wpfh-in-api-key" name="<?php echo esc_attr( WPFH_IN_Settings::OPTION_NAME ); ?>[api_key]" value="<?php echo esc_attr( $settings['api_key'] ); ?>" class="regular-text code" />
										<button type="button" id="wpfh-in-regen-key" class="button button-secondary"><?php esc_html_e( 'Regenerate Key', 'forgehub-indexnow' ); ?></button>
									</div>
									<p class="description">
										<?php esc_html_e( 'Virtual verification file:', 'forgehub-indexnow' ); ?>
										<a id="wpfh-in-key-link" href="<?php echo esc_url( $key_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $key_url ); ?></a>
									</p>
									<p class="wpfh-in-actions-row">
										<button type="button" id="wpfh-in-verify-key" class="button button-secondary"><?php esc_html_e( 'Verify .txt Endpoint', 'forgehub-indexnow' ); ?></button>
										<span id="wpfh-in-verify-status" class="wpfh-in-status" aria-live="polite"></span>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wpfh-in-endpoint"><?php esc_html_e( 'Search Engine Endpoint', 'forgehub-indexnow' ); ?></label></th>
								<td>
									<select id="wpfh-in-endpoint" name="<?php echo esc_attr( WPFH_IN_Settings::OPTION_NAME ); ?>[endpoint]" class="regular-text">
										<?php foreach ( $endpoints as $ep_url => $ep_label ) : ?>
											<option value="<?php echo esc_url( $ep_url ); ?>" <?php selected( $settings['endpoint'], $ep_url ); ?>>
												<?php echo esc_html( $ep_label ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Auto-Ping Post Types', 'forgehub-indexnow' ); ?></th>
								<td>
									<fieldset>
										<?php foreach ( $core_types as $pt_slug => $pt_label ) : ?>
											<label class="wpfh-in-check">
												<input type="checkbox" name="<?php echo esc_attr( WPFH_IN_Settings::OPTION_NAME ); ?>[post_types][]" value="<?php echo esc_attr( $pt_slug ); ?>" <?php checked( in_array( $pt_slug, (array) $settings['post_types'], true ) ); ?> />
												<?php echo esc_html( $pt_label ); ?>
											</label>
										<?php endforeach; ?>
									</fieldset>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Trigger Events', 'forgehub-indexnow' ); ?></th>
								<td>
									<fieldset>
										<label class="wpfh-in-check">
											<input type="checkbox" name="<?php echo esc_attr( WPFH_IN_Settings::OPTION_NAME ); ?>[ping_publish]" value="1" <?php checked( ! empty( $settings['ping_publish'] ) ); ?> />
											<?php esc_html_e( 'On first publish', 'forgehub-indexnow' ); ?>
										</label>
										<label class="wpfh-in-check">
											<input type="checkbox" name="<?php echo esc_attr( WPFH_IN_Settings::OPTION_NAME ); ?>[ping_update]" value="1" <?php checked( ! empty( $settings['ping_update'] ) ); ?> />
											<?php esc_html_e( 'On published content update', 'forgehub-indexnow' ); ?>
										</label>
										<label class="wpfh-in-check">
											<input type="checkbox" name="<?php echo esc_attr( WPFH_IN_Settings::OPTION_NAME ); ?>[ping_trash]" value="1" <?php checked( ! empty( $settings['ping_trash'] ) ); ?> />
											<?php esc_html_e( 'When published content is trashed', 'forgehub-indexnow' ); ?>
										</label>
									</fieldset>
								</td>
							</tr>
						</table>
						<?php submit_button( __( 'Save Settings', 'forgehub-indexnow' ) ); ?>
					</form>
				</div>

				<div class="wpfh-in-card">
					<h2><?php esc_html_e( 'Instant Manual URL Ping', 'forgehub-indexnow' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Submit any single URL from this site immediately to IndexNow and inspect the live HTTP status.', 'forgehub-indexnow' ); ?>
					</p>
					<div class="wpfh-in-manual-box">
						<label for="wpfh-in-manual-url" class="screen-reader-text"><?php esc_html_e( 'URL to submit', 'forgehub-indexnow' ); ?></label>
						<input type="url" id="wpfh-in-manual-url" class="large-text" value="<?php echo esc_url( home_url( '/' ) ); ?>" placeholder="<?php echo esc_attr( home_url( '/sample-post/' ) ); ?>" />
						<button type="button" id="wpfh-in-manual-submit" class="button button-primary"><?php esc_html_e( 'Submit URL Now', 'forgehub-indexnow' ); ?></button>
					</div>
					<div id="wpfh-in-manual-result" class="wpfh-in-status-block" aria-live="polite"></div>
				</div>
			</div>

			<?php do_action( 'wpfh_in_admin_pro_sections', $settings ); ?>

			<div class="wpfh-in-card wpfh-in-log-card">
				<div class="wpfh-in-card-head">
					<h2><?php esc_html_e( 'Recent Submissions (Last 50)', 'forgehub-indexnow' ); ?></h2>
					<div class="wpfh-in-card-actions">
						<?php do_action( 'wpfh_in_admin_log_actions' ); ?>
						<button type="button" id="wpfh-in-clear-logs" class="button button-secondary"><?php esc_html_e( 'Clear Log', 'forgehub-indexnow' ); ?></button>
					</div>
				</div>
				<div class="wpfh-in-table-wrap">
					<table class="widefat striped wpfh-in-log-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Time', 'forgehub-indexnow' ); ?></th>
								<th><?php esc_html_e( 'URL', 'forgehub-indexnow' ); ?></th>
								<th><?php esc_html_e( 'Trigger', 'forgehub-indexnow' ); ?></th>
								<th><?php esc_html_e( 'HTTP Status', 'forgehub-indexnow' ); ?></th>
								<th><?php esc_html_e( 'Response', 'forgehub-indexnow' ); ?></th>
							</tr>
						</thead>
						<tbody id="wpfh-in-log-body">
							<?php if ( empty( $logs ) ) : ?>
								<tr>
									<td colspan="5"><?php esc_html_e( 'No submissions recorded yet. Publish a post or submit a URL above to see live logs.', 'forgehub-indexnow' ); ?></td>
								</tr>
							<?php else : ?>
								<?php foreach ( $logs as $entry ) : ?>
									<?php
									$time_str = ! empty( $entry['time'] ) ? wp_date( 'Y-m-d H:i:s', (int) $entry['time'] ) : '—';
									$status   = isset( $entry['status'] ) ? (int) $entry['status'] : 0;
									$ok       = ! empty( $entry['success'] );
									?>
									<tr>
										<td><?php echo esc_html( $time_str ); ?></td>
										<td><a href="<?php echo esc_url( $entry['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $entry['url'] ); ?></a></td>
										<td><code><?php echo esc_html( $entry['trigger'] ); ?></code></td>
										<td>
											<span class="wpfh-in-pill <?php echo $ok ? 'wpfh-in-pill-ok' : 'wpfh-in-pill-err'; ?>">
												<?php echo $status > 0 ? esc_html( (string) $status ) : esc_html__( 'ERR', 'forgehub-indexnow' ); ?>
											</span>
										</td>
										<td><?php echo esc_html( $entry['message'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<?php if ( ! $is_pro ) : ?>
				<p class="wpfh-in-footer-note">
					<?php esc_html_e( 'Need bulk URL submission, WP-Cron retry queues, custom post types, or CSV log exports?', 'forgehub-indexnow' ); ?>
					<a href="<?php echo esc_url( wpfh_in_get_upgrade_url() ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Priority Support & Pro', 'forgehub-indexnow' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}

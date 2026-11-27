<?php
/**
 * Pro module for ForgeHub IndexNow: Bulk Submit, WP-Cron Retry Queue, CPT/Taxonomy Support, CSV & Stats.
 *
 * @package ForgeHub_IndexNow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPFH_IN_Pro
 */
class WPFH_IN_Pro {

	/**
	 * Pro settings option name.
	 *
	 * @var string
	 */
	const PRO_OPTION = 'wpfh_in_pro_settings';

	/**
	 * Retry queue option name.
	 *
	 * @var string
	 */
	const RETRY_OPTION = 'wpfh_in_retry_queue';

	/**
	 * Cron hook name.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'wpfh_in_retry_event';

	/**
	 * Singleton instance.
	 *
	 * @var WPFH_IN_Pro|null
	 */
	private static $instance = null;

	/**
	 * Return singleton instance.
	 *
	 * @return WPFH_IN_Pro
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
		add_action( 'admin_init', array( $this, 'register_pro_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_csv_export' ) );
		add_filter( 'wpfh_in_allowed_post_types', array( $this, 'filter_allowed_post_types' ), 10, 2 );
		add_filter( 'wpfh_in_transition_urls', array( $this, 'append_taxonomy_urls' ), 10, 3 );
		add_filter( 'wpfh_in_max_logs', array( $this, 'filter_max_logs' ) );
		add_action( 'wpfh_in_after_submit', array( $this, 'maybe_enqueue_retry' ) );
		add_action( 'wpfh_in_admin_pro_sections', array( $this, 'render_pro_sections' ) );
		add_action( 'wpfh_in_admin_log_actions', array( $this, 'render_csv_button' ) );

		add_action( self::CRON_HOOK, array( $this, 'process_retry_queue' ) );
		add_action( 'wp_ajax_wpfh_in_pro_bulk_submit', array( $this, 'ajax_bulk_submit' ) );
		add_action( 'wp_ajax_wpfh_in_pro_run_retries', array( $this, 'ajax_run_retries' ) );

		$this->ensure_cron_scheduled();
	}

	/**
	 * Default Pro settings.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'extra_post_types' => array(),
			'taxonomies'       => array( 'category', 'post_tag' ),
			'retry_enabled'    => true,
			'max_retries'      => 3,
		);
	}

	/**
	 * Retrieve merged Pro settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$saved = get_option( self::PRO_OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::get_defaults() );
	}

	/**
	 * Register Pro settings group.
	 *
	 * @return void
	 */
	public function register_pro_settings() {
		register_setting(
			'wpfh_in_pro_settings_group',
			self::PRO_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_pro_settings' ),
				'default'           => self::get_defaults(),
			)
		);
	}

	/**
	 * Sanitize Pro settings.
	 *
	 * @param array $raw Raw input.
	 * @return array
	 */
	public function sanitize_pro_settings( $raw ) {
		$raw              = is_array( $raw ) ? $raw : array();
		$public_cpts      = array_keys( get_post_types( array( 'public' => true ), 'names' ) );
		$public_taxes     = array_keys( get_taxonomies( array( 'public' => true ), 'names' ) );
		$extra_post_types = array();
		$taxonomies       = array();

		if ( isset( $raw['extra_post_types'] ) && is_array( $raw['extra_post_types'] ) ) {
			foreach ( $raw['extra_post_types'] as $pt ) {
				$pt = sanitize_key( wp_unslash( $pt ) );
				if ( in_array( $pt, $public_cpts, true ) ) {
					$extra_post_types[] = $pt;
				}
			}
		}

		if ( isset( $raw['taxonomies'] ) && is_array( $raw['taxonomies'] ) ) {
			foreach ( $raw['taxonomies'] as $tax ) {
				$tax = sanitize_key( wp_unslash( $tax ) );
				if ( in_array( $tax, $public_taxes, true ) ) {
					$taxonomies[] = $tax;
				}
			}
		}

		$max_retries = isset( $raw['max_retries'] ) ? absint( $raw['max_retries'] ) : 3;
		if ( $max_retries < 1 || $max_retries > 10 ) {
			$max_retries = 3;
		}

		return array(
			'extra_post_types' => array_values( array_unique( $extra_post_types ) ),
			'taxonomies'       => array_values( array_unique( $taxonomies ) ),
			'retry_enabled'    => ! empty( $raw['retry_enabled'] ),
			'max_retries'      => $max_retries,
		);
	}

	/**
	 * Ensure hourly WP-Cron retry event is scheduled when enabled.
	 *
	 * @return void
	 */
	private function ensure_cron_scheduled() {
		$pro = self::get_settings();
		if ( ! empty( $pro['retry_enabled'] ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Extend allowed post types with configured CPTs.
	 *
	 * @param array $types Core post types.
	 * @return array
	 */
	public function filter_allowed_post_types( $types ) {
		$pro = self::get_settings();
		return array_values( array_unique( array_merge( (array) $types, (array) $pro['extra_post_types'] ) ) );
	}

	/**
	 * Append taxonomy term archive URLs for the transitioned post.
	 *
	 * @param array   $urls    URLs to submit.
	 * @param WP_Post $post    Post object.
	 * @param string  $trigger Trigger type.
	 * @return array
	 */
	public function append_taxonomy_urls( $urls, $post, $trigger ) {
		unset( $trigger );
		$pro = self::get_settings();
		if ( empty( $pro['taxonomies'] ) || ! $post instanceof WP_Post ) {
			return $urls;
		}

		foreach ( (array) $pro['taxonomies'] as $tax ) {
			if ( ! is_object_in_taxonomy( $post->post_type, $tax ) ) {
				continue;
			}
			$terms = get_the_terms( $post->ID, $tax );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$link = get_term_link( $term );
					if ( ! is_wp_error( $link ) && is_string( $link ) ) {
						$urls[] = $link;
					}
				}
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Expand log retention in Pro to 250 entries.
	 *
	 * @return int
	 */
	public function filter_max_logs() {
		return 250;
	}

	/**
	 * Queue failed URLs for WP-Cron retry.
	 *
	 * @param array $result Submission result.
	 * @return void
	 */
	public function maybe_enqueue_retry( $result ) {
		if ( ! empty( $result['success'] ) || empty( $result['urls'] ) ) {
			return;
		}

		if ( isset( $result['trigger'] ) && 'retry' === $result['trigger'] ) {
			return;
		}

		$pro = self::get_settings();
		if ( empty( $pro['retry_enabled'] ) ) {
			return;
		}

		$queue = get_option( self::RETRY_OPTION, array() );
		$queue = is_array( $queue ) ? $queue : array();

		foreach ( (array) $result['urls'] as $url ) {
			$key = md5( $url );
			if ( ! isset( $queue[ $key ] ) ) {
				$queue[ $key ] = array(
					'url'        => esc_url_raw( $url ),
					'attempts'   => 0,
					'last_error' => isset( $result['message'] ) ? sanitize_text_field( $result['message'] ) : '',
					'queued_at'  => current_time( 'timestamp' ),
				);
			}
		}

		update_option( self::RETRY_OPTION, $queue, false );
	}

	/**
	 * Process queued failed URLs via WP-Cron or manual trigger.
	 *
	 * @return array Summary counts.
	 */
	public function process_retry_queue() {
		$pro   = self::get_settings();
		$queue = get_option( self::RETRY_OPTION, array() );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return array(
				'processed' => 0,
				'succeeded' => 0,
				'remaining' => 0,
			);
		}

		$max_retries = max( 1, (int) $pro['max_retries'] );
		$batch_urls  = array();
		$batch_keys  = array();

		foreach ( $queue as $key => $item ) {
			if ( count( $batch_urls ) >= 100 ) {
				break;
			}
			$batch_urls[] = $item['url'];
			$batch_keys[] = $key;
		}

		$result    = WPFH_IN_Engine::get_instance()->submit_batch_urls( $batch_urls, 'retry' );
		$succeeded = 0;

		foreach ( $batch_keys as $key ) {
			if ( ! empty( $result['success'] ) ) {
				unset( $queue[ $key ] );
				++$succeeded;
			} else {
				$queue[ $key ]['attempts']   = isset( $queue[ $key ]['attempts'] ) ? (int) $queue[ $key ]['attempts'] + 1 : 1;
				$queue[ $key ]['last_error'] = isset( $result['message'] ) ? sanitize_text_field( $result['message'] ) : '';
				if ( $queue[ $key ]['attempts'] >= $max_retries ) {
					unset( $queue[ $key ] );
				}
			}
		}

		update_option( self::RETRY_OPTION, $queue, false );

		return array(
			'processed' => count( $batch_urls ),
			'succeeded' => $succeeded,
			'remaining' => count( $queue ),
		);
	}

	/**
	 * AJAX: Bulk submit pasted URLs or recent published URLs.
	 *
	 * @return void
	 */
	public function ajax_bulk_submit() {
		check_ajax_referer( 'wpfh_in_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'forgehub-indexnow' ) ), 403 );
		}

		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'textarea';
		$urls = array();

		if ( 'recent' === $mode ) {
			$settings = WPFH_IN_Settings::get();
			$types    = apply_filters( 'wpfh_in_allowed_post_types', $settings['post_types'], $settings );
			$posts    = get_posts(
				array(
					'post_type'      => $types,
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			foreach ( $posts as $post_id ) {
				$link = get_permalink( $post_id );
				if ( $link ) {
					$urls[] = $link;
				}
			}
		} else {
			$raw_list = isset( $_POST['urls'] ) ? sanitize_textarea_field( wp_unslash( $_POST['urls'] ) ) : '';
			$lines    = preg_split( '/\r\n|\r|\n/', $raw_list );
			foreach ( (array) $lines as $line ) {
				$clean = esc_url_raw( trim( (string) $line ) );
				if ( '' !== $clean ) {
					$urls[] = $clean;
				}
			}
		}

		if ( empty( $urls ) ) {
			wp_send_json_error( array( 'message' => __( 'No valid URLs found to submit.', 'forgehub-indexnow' ) ), 400 );
		}

		$result = WPFH_IN_Engine::get_instance()->submit_batch_urls( $urls, 'bulk' );
		if ( $result['success'] ) {
			wp_send_json_success( $result );
		}
		wp_send_json_error( $result );
	}

	/**
	 * AJAX: Manually execute the retry queue.
	 *
	 * @return void
	 */
	public function ajax_run_retries() {
		check_ajax_referer( 'wpfh_in_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'forgehub-indexnow' ) ), 403 );
		}

		$summary = $this->process_retry_queue();
		wp_send_json_success(
			array(
				'summary' => $summary,
				'message' => sprintf(
					/* translators: 1: processed URL count, 2: succeeded count, 3: remaining queue count */
					__( 'Processed %1$d queued URL(s): %2$d succeeded, %3$d remaining.', 'forgehub-indexnow' ),
					$summary['processed'],
					$summary['succeeded'],
					$summary['remaining']
				),
			)
		);
	}

	/**
	 * Handle CSV export download of submission logs.
	 *
	 * @return void
	 */
	public function handle_csv_export() {
		if ( ! isset( $_GET['wpfh_in_export_csv'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'forgehub-indexnow' ) );
		}
		check_admin_referer( 'wpfh_in_export_csv' );

		$logs  = WPFH_IN_Settings::get_logs();
		$lines = array( '"Time","URL","Trigger","Endpoint","HTTP Status","Success","Message"' );
		foreach ( $logs as $entry ) {
			$row = array(
				! empty( $entry['time'] ) ? wp_date( 'Y-m-d H:i:s', (int) $entry['time'] ) : '',
				isset( $entry['url'] ) ? $entry['url'] : '',
				isset( $entry['trigger'] ) ? $entry['trigger'] : '',
				isset( $entry['endpoint'] ) ? $entry['endpoint'] : '',
				isset( $entry['status'] ) ? (string) (int) $entry['status'] : '0',
				! empty( $entry['success'] ) ? 'yes' : 'no',
				isset( $entry['message'] ) ? $entry['message'] : '',
			);
			$escaped = array();
			foreach ( $row as $cell ) {
				$escaped[] = '"' . str_replace( '"', '""', (string) $cell ) . '"';
			}
			$lines[] = implode( ',', $escaped );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="forgehub-indexnow-logs-' . wp_date( 'Ymd-His' ) . '.csv"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo implode( "\r\n", $lines );
		exit;
	}

	/**
	 * Render CSV Export button in the log card header.
	 *
	 * @return void
	 */
	public function render_csv_button() {
		$export_url = wp_nonce_url(
			admin_url( 'options-general.php?page=forgehub-indexnow&wpfh_in_export_csv=1' ),
			'wpfh_in_export_csv'
		);
		?>
		<a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary">
			<?php esc_html_e( 'Export CSV', 'forgehub-indexnow' ); ?>
		</a>
		<?php
	}

	/**
	 * Render Pro dashboard panels (Stats, Bulk Submit, Retry Queue, CPT & Taxonomy settings).
	 *
	 * @return void
	 */
	public function render_pro_sections() {
		$pro          = self::get_settings();
		$logs         = WPFH_IN_Settings::get_logs();
		$queue        = get_option( self::RETRY_OPTION, array() );
		$queue_count  = is_array( $queue ) ? count( $queue ) : 0;
		$total_logs   = count( $logs );
		$success_logs = 0;
		$error_logs   = 0;

		foreach ( $logs as $entry ) {
			if ( ! empty( $entry['success'] ) ) {
				++$success_logs;
			} else {
				++$error_logs;
			}
		}
		$rate = $total_logs > 0 ? round( ( $success_logs / $total_logs ) * 100, 1 ) : 100;

		$all_cpts  = get_post_types( array( 'public' => true ), 'objects' );
		$all_taxes = get_taxonomies( array( 'public' => true ), 'objects' );
		unset( $all_cpts['post'], $all_cpts['page'], $all_cpts['product'], $all_cpts['attachment'] );
		?>
		<div class="wpfh-in-stats-row">
			<div class="wpfh-in-stat-card">
				<span class="wpfh-in-stat-label"><?php esc_html_e( 'Logged Submissions', 'forgehub-indexnow' ); ?></span>
				<strong class="wpfh-in-stat-val"><?php echo esc_html( (string) $total_logs ); ?></strong>
			</div>
			<div class="wpfh-in-stat-card">
				<span class="wpfh-in-stat-label"><?php esc_html_e( 'Success Rate', 'forgehub-indexnow' ); ?></span>
				<strong class="wpfh-in-stat-val"><?php echo esc_html( $rate . '%' ); ?></strong>
			</div>
			<div class="wpfh-in-stat-card">
				<span class="wpfh-in-stat-label"><?php esc_html_e( 'Failed Requests', 'forgehub-indexnow' ); ?></span>
				<strong class="wpfh-in-stat-val"><?php echo esc_html( (string) $error_logs ); ?></strong>
			</div>
			<div class="wpfh-in-stat-card">
				<span class="wpfh-in-stat-label"><?php esc_html_e( 'Pending Retry Queue', 'forgehub-indexnow' ); ?></span>
				<strong class="wpfh-in-stat-val"><?php echo esc_html( (string) $queue_count ); ?></strong>
			</div>
		</div>

		<div class="wpfh-in-grid">
			<div class="wpfh-in-card">
				<h2><?php esc_html_e( 'Pro: Bulk URL Submission', 'forgehub-indexnow' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Paste up to 10,000 URLs (one per line) or submit your latest 100 published URLs in a single IndexNow batch.', 'forgehub-indexnow' ); ?></p>
				<textarea id="wpfh-in-bulk-urls" rows="5" class="large-text code" placeholder="<?php echo esc_attr( home_url( '/page-one/' ) . "\n" . home_url( '/page-two/' ) ); ?>"></textarea>
				<p class="wpfh-in-actions-row">
					<button type="button" id="wpfh-in-bulk-submit" class="button button-primary"><?php esc_html_e( 'Submit Pasted URLs', 'forgehub-indexnow' ); ?></button>
					<button type="button" id="wpfh-in-bulk-recent" class="button button-secondary"><?php esc_html_e( 'Submit Latest 100 Published', 'forgehub-indexnow' ); ?></button>
					<button type="button" id="wpfh-in-run-retries" class="button button-secondary"><?php esc_html_e( 'Run Retry Queue Now', 'forgehub-indexnow' ); ?></button>
				</p>
				<div id="wpfh-in-bulk-result" class="wpfh-in-status-block" aria-live="polite"></div>
			</div>

			<div class="wpfh-in-card">
				<h2><?php esc_html_e( 'Pro: Custom Post Types, Taxonomies & Retry Rules', 'forgehub-indexnow' ); ?></h2>
				<form method="post" action="options.php">
					<?php settings_fields( 'wpfh_in_pro_settings_group' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'WP-Cron Auto-Retry', 'forgehub-indexnow' ); ?></th>
							<td>
								<label class="wpfh-in-check">
									<input type="checkbox" name="<?php echo esc_attr( self::PRO_OPTION ); ?>[retry_enabled]" value="1" <?php checked( ! empty( $pro['retry_enabled'] ) ); ?> />
									<?php esc_html_e( 'Automatically retry failed submissions hourly via WP-Cron', 'forgehub-indexnow' ); ?>
								</label>
								<label for="wpfh-in-max-retries">
									<?php esc_html_e( 'Max retry attempts:', 'forgehub-indexnow' ); ?>
									<input type="number" id="wpfh-in-max-retries" name="<?php echo esc_attr( self::PRO_OPTION ); ?>[max_retries]" value="<?php echo esc_attr( (string) $pro['max_retries'] ); ?>" min="1" max="10" class="small-text" />
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Custom Post Types', 'forgehub-indexnow' ); ?></th>
							<td>
								<?php if ( empty( $all_cpts ) ) : ?>
									<p class="description"><?php esc_html_e( 'No additional public custom post types detected.', 'forgehub-indexnow' ); ?></p>
								<?php else : ?>
									<?php foreach ( $all_cpts as $cpt_slug => $cpt_obj ) : ?>
										<label class="wpfh-in-check">
											<input type="checkbox" name="<?php echo esc_attr( self::PRO_OPTION ); ?>[extra_post_types][]" value="<?php echo esc_attr( $cpt_slug ); ?>" <?php checked( in_array( $cpt_slug, (array) $pro['extra_post_types'], true ) ); ?> />
											<?php echo esc_html( $cpt_obj->labels->name ); ?>
										</label>
									<?php endforeach; ?>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Taxonomy Archives', 'forgehub-indexnow' ); ?></th>
							<td>
								<?php foreach ( $all_taxes as $tax_slug => $tax_obj ) : ?>
									<label class="wpfh-in-check">
										<input type="checkbox" name="<?php echo esc_attr( self::PRO_OPTION ); ?>[taxonomies][]" value="<?php echo esc_attr( $tax_slug ); ?>" <?php checked( in_array( $tax_slug, (array) $pro['taxonomies'], true ) ); ?> />
										<?php echo esc_html( $tax_obj->labels->name ); ?>
									</label>
								<?php endforeach; ?>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save Pro Settings', 'forgehub-indexnow' ) ); ?>
				</form>
			</div>
		</div>
		<?php
	}
}

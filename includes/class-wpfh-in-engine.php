<?php
/**
 * Core IndexNow protocol engine: virtual .txt key serving and URL submission.
 *
 * @package ForgeHub_IndexNow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPFH_IN_Engine
 */
class WPFH_IN_Engine {

	/**
	 * Singleton instance.
	 *
	 * @var WPFH_IN_Engine|null
	 */
	private static $instance = null;

	/**
	 * Track URLs already submitted during this PHP request to avoid duplicate pings.
	 *
	 * @var array
	 */
	private $submitted_in_request = array();

	/**
	 * Return singleton instance.
	 *
	 * @return WPFH_IN_Engine
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
		add_action( 'parse_request', array( $this, 'maybe_serve_key_file' ), 1 );
		add_action( 'template_redirect', array( $this, 'maybe_serve_key_file' ), 1 );
		add_action( 'transition_post_status', array( $this, 'handle_post_transition' ), 20, 3 );
	}

	/**
	 * Serve the virtual root `<api-key>.txt` verification endpoint cleanly.
	 *
	 * @return void
	 */
	public function maybe_serve_key_file() {
		if ( is_admin() ) {
			return;
		}

		$settings = WPFH_IN_Settings::get();
		$api_key  = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
		if ( '' === $api_key ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $request_uri ) {
			return;
		}

		$req_path  = wp_parse_url( $request_uri, PHP_URL_PATH );
		$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = is_string( $home_path ) ? trailingslashit( $home_path ) : '/';
		$expected  = $home_path . $api_key . '.txt';

		if ( $req_path !== $expected ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Cache-Control: public, max-age=3600' );
		echo esc_html( $api_key );
		exit;
	}

	/**
	 * Handle post publish, update, and trash transitions.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Previous post status.
	 * @param WP_Post $post       Post object.
	 * @return void
	 */
	public function handle_post_transition( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}

		$settings = WPFH_IN_Settings::get();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		$allowed_types = apply_filters( 'wpfh_in_allowed_post_types', $settings['post_types'], $settings );
		if ( ! in_array( $post->post_type, (array) $allowed_types, true ) ) {
			return;
		}

		$trigger = '';
		if ( 'publish' === $new_status && 'publish' !== $old_status && ! empty( $settings['ping_publish'] ) ) {
			$trigger = 'auto_publish';
		} elseif ( 'publish' === $new_status && 'publish' === $old_status && ! empty( $settings['ping_update'] ) ) {
			$trigger = 'auto_update';
		} elseif ( 'trash' === $new_status && 'publish' === $old_status && ! empty( $settings['ping_trash'] ) ) {
			$trigger = 'auto_trash';
		}

		if ( '' === $trigger ) {
			return;
		}

		$url = get_permalink( $post );
		if ( empty( $url ) || ! is_string( $url ) ) {
			return;
		}

		$urls = apply_filters( 'wpfh_in_transition_urls', array( $url ), $post, $trigger );
		$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', (array) $urls ) ) ) );

		$to_submit = array();
		foreach ( $urls as $candidate ) {
			if ( ! isset( $this->submitted_in_request[ $candidate ] ) ) {
				$this->submitted_in_request[ $candidate ] = true;
				$to_submit[]                              = $candidate;
			}
		}

		if ( empty( $to_submit ) ) {
			return;
		}

		if ( 1 === count( $to_submit ) ) {
			$this->submit_single_url( $to_submit[0], $trigger );
		} else {
			$this->submit_batch_urls( $to_submit, $trigger );
		}
	}

	/**
	 * Submit a single URL via the IndexNow protocol.
	 *
	 * @param string $url     Absolute URL belonging to this host.
	 * @param string $trigger Trigger identifier.
	 * @return array Result array with success, status, message, endpoint.
	 */
	public function submit_single_url( $url, $trigger = 'manual' ) {
		return $this->submit_batch_urls( array( $url ), $trigger );
	}

	/**
	 * Submit one or more URLs via the IndexNow JSON POST API.
	 *
	 * @param array  $urls    List of URLs on this site's host.
	 * @param string $trigger Trigger identifier.
	 * @return array Result array.
	 */
	public function submit_batch_urls( $urls, $trigger = 'manual' ) {
		$settings = WPFH_IN_Settings::get();
		$api_key  = $settings['api_key'];
		$endpoint = $settings['endpoint'];
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );

		$clean_urls = array();
		foreach ( (array) $urls as $candidate ) {
			$candidate = esc_url_raw( trim( (string) $candidate ) );
			if ( '' === $candidate ) {
				continue;
			}
			$cand_host = wp_parse_url( $candidate, PHP_URL_HOST );
			if ( $cand_host && strtolower( $cand_host ) === strtolower( (string) $host ) ) {
				$clean_urls[] = $candidate;
			}
		}
		$clean_urls = array_values( array_unique( $clean_urls ) );

		if ( empty( $clean_urls ) ) {
			return array(
				'success'  => false,
				'status'   => 0,
				'endpoint' => $endpoint,
				'message'  => __( 'No valid URLs matching this site host were provided.', 'forgehub-indexnow' ),
			);
		}

		$payload = array(
			'host'        => $host,
			'key'         => $api_key,
			'keyLocation' => WPFH_IN_Settings::get_key_location( $api_key ),
			'urlList'     => array_slice( $clean_urls, 0, 10000 ),
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type' => 'application/json; charset=utf-8',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		$status  = 0;
		$success = false;
		$message = '';

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
		} else {
			$status  = (int) wp_remote_retrieve_response_code( $response );
			$success = in_array( $status, array( 200, 202 ), true );
			$message = $this->describe_http_status( $status, wp_remote_retrieve_response_message( $response ) );
		}

		$display_url = ( 1 === count( $clean_urls ) )
			? $clean_urls[0]
			: sprintf(
				/* translators: 1: first submitted URL, 2: count of additional URLs */
				__( '%1$s (+%2$d more)', 'forgehub-indexnow' ),
				$clean_urls[0],
				count( $clean_urls ) - 1
			);

		$log_entry = array(
			'url'      => $clean_urls[0],
			'trigger'  => $trigger,
			'endpoint' => $endpoint,
			'status'   => $status,
			'success'  => $success,
			'message'  => ( 1 === count( $clean_urls ) ) ? $message : $display_url . ' — ' . $message,
			'count'    => count( $clean_urls ),
		);

		WPFH_IN_Settings::add_log( $log_entry );

		if ( $success ) {
			$plugin = WPFH_IndexNow::get_instance();
			if ( $plugin->get_review_nudge() ) {
				$plugin->get_review_nudge()->record_success();
			}
		}

		$result = array(
			'success'  => $success,
			'status'   => $status,
			'endpoint' => $endpoint,
			'message'  => $message,
			'urls'     => $clean_urls,
			'trigger'  => $trigger,
		);

		do_action( 'wpfh_in_after_submit', $result );

		return $result;
	}

	/**
	 * Translate IndexNow HTTP status codes into clear human messages.
	 *
	 * @param int    $status      HTTP status code.
	 * @param string $raw_message Raw HTTP status message.
	 * @return string
	 */
	private function describe_http_status( $status, $raw_message = '' ) {
		switch ( $status ) {
			case 200:
				return __( 'OK — URL received by IndexNow.', 'forgehub-indexnow' );
			case 202:
				return __( 'Accepted — Key validation pending on search engine.', 'forgehub-indexnow' );
			case 400:
				return __( 'Bad Request — Invalid URL or request format.', 'forgehub-indexnow' );
			case 403:
				return __( 'Forbidden — API key did not match the hosted .txt file.', 'forgehub-indexnow' );
			case 422:
				return __( 'Unprocessable Entity — URL host does not match key host.', 'forgehub-indexnow' );
			case 429:
				return __( 'Too Many Requests — Rate limited by endpoint.', 'forgehub-indexnow' );
			default:
				return $raw_message ? sanitize_text_field( $raw_message ) : __( 'Unexpected response from IndexNow endpoint.', 'forgehub-indexnow' );
		}
	}
}

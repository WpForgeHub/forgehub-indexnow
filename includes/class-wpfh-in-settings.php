<?php
/**
 * Settings management for ForgeHub IndexNow.
 *
 * @package ForgeHub_IndexNow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPFH_IN_Settings
 */
class WPFH_IN_Settings {

	/**
	 * Main option name.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'wpfh_in_settings';

	/**
	 * Submission logs option name.
	 *
	 * @var string
	 */
	const LOGS_OPTION = 'wpfh_in_logs';

	/**
	 * Max log entries stored in Free/Core.
	 *
	 * @var int
	 */
	const MAX_LOGS = 50;

	/**
	 * Allowed IndexNow endpoints.
	 *
	 * @return array
	 */
	public static function get_endpoints() {
		return array(
			'https://api.indexnow.org/indexnow' => __( 'IndexNow Global Hub (api.indexnow.org — Bing, Yandex, Seznam, Naver)', 'forgehub-indexnow' ),
			'https://www.bing.com/indexnow'     => __( 'Microsoft Bing (www.bing.com/indexnow)', 'forgehub-indexnow' ),
			'https://yandex.com/indexnow'       => __( 'Yandex (yandex.com/indexnow)', 'forgehub-indexnow' ),
		);
	}

	/**
	 * Generate a standards-compliant 32-character hexadecimal IndexNow API key.
	 *
	 * @return string
	 */
	public static function generate_api_key() {
		if ( function_exists( 'random_bytes' ) ) {
			try {
				return bin2hex( random_bytes( 16 ) );
			} catch ( Exception $e ) {
				// Fallback below.
				unset( $e );
			}
		}
		return strtolower( wp_generate_password( 32, false, false ) );
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'enabled'      => true,
			'api_key'      => '',
			'endpoint'     => 'https://api.indexnow.org/indexnow',
			'post_types'   => array( 'post', 'page', 'product' ),
			'ping_publish' => true,
			'ping_update'  => true,
			'ping_trash'   => true,
		);
	}

	/**
	 * Retrieve merged settings, auto-generating an API key if missing.
	 *
	 * @return array
	 */
	public static function get() {
		$saved    = get_option( self::OPTION_NAME, array() );
		$defaults = self::get_defaults();
		$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );

		if ( empty( $settings['api_key'] ) || ! self::is_valid_key( $settings['api_key'] ) ) {
			$settings['api_key'] = self::generate_api_key();
			update_option( self::OPTION_NAME, $settings );
		}

		return $settings;
	}

	/**
	 * Validate an IndexNow API key (8-128 chars, alphanumeric or hyphen).
	 *
	 * @param string $key Candidate key.
	 * @return bool
	 */
	public static function is_valid_key( $key ) {
		return (bool) preg_match( '/^[a-zA-Z0-9\-]{8,128}$/', (string) $key );
	}

	/**
	 * Return the virtual key file URL.
	 *
	 * @param string $api_key Optional API key override.
	 * @return string
	 */
	public static function get_key_location( $api_key = '' ) {
		if ( '' === $api_key ) {
			$settings = self::get();
			$api_key  = $settings['api_key'];
		}
		return trailingslashit( home_url() ) . rawurlencode( $api_key ) . '.txt';
	}

	/**
	 * Sanitize settings on save.
	 *
	 * @param array $raw Raw submitted values.
	 * @return array
	 */
	public static function sanitize( $raw ) {
		$raw      = is_array( $raw ) ? $raw : array();
		$current  = self::get();
		$endpoints = array_keys( self::get_endpoints() );

		$api_key = isset( $raw['api_key'] ) ? sanitize_text_field( wp_unslash( $raw['api_key'] ) ) : $current['api_key'];
		$api_key = preg_replace( '/[^a-zA-Z0-9\-]/', '', $api_key );
		if ( ! self::is_valid_key( $api_key ) ) {
			$api_key = ! empty( $current['api_key'] ) ? $current['api_key'] : self::generate_api_key();
		}

		$endpoint = isset( $raw['endpoint'] ) ? esc_url_raw( wp_unslash( $raw['endpoint'] ) ) : $current['endpoint'];
		if ( ! in_array( $endpoint, $endpoints, true ) ) {
			$endpoint = 'https://api.indexnow.org/indexnow';
		}

		$allowed_core = array( 'post', 'page', 'product' );
		$post_types   = array();
		if ( isset( $raw['post_types'] ) && is_array( $raw['post_types'] ) ) {
			foreach ( $raw['post_types'] as $pt ) {
				$pt = sanitize_key( wp_unslash( $pt ) );
				if ( in_array( $pt, $allowed_core, true ) ) {
					$post_types[] = $pt;
				}
			}
		}
		if ( empty( $post_types ) ) {
			$post_types = array( 'post', 'page' );
		}

		return array(
			'enabled'      => ! empty( $raw['enabled'] ),
			'api_key'      => $api_key,
			'endpoint'     => $endpoint,
			'post_types'   => array_values( array_unique( $post_types ) ),
			'ping_publish' => ! empty( $raw['ping_publish'] ),
			'ping_update'  => ! empty( $raw['ping_update'] ),
			'ping_trash'   => ! empty( $raw['ping_trash'] ),
		);
	}

	/**
	 * Retrieve recent submission logs.
	 *
	 * @return array
	 */
	public static function get_logs() {
		$logs = get_option( self::LOGS_OPTION, array() );
		return is_array( $logs ) ? $logs : array();
	}

	/**
	 * Append a log entry and cap at MAX_LOGS (50 in Free, filterable for Pro).
	 *
	 * @param array $entry Log record.
	 * @return void
	 */
	public static function add_log( $entry ) {
		$logs = self::get_logs();
		array_unshift(
			$logs,
			array(
				'time'     => current_time( 'timestamp' ),
				'url'      => isset( $entry['url'] ) ? esc_url_raw( $entry['url'] ) : '',
				'trigger'  => isset( $entry['trigger'] ) ? sanitize_key( $entry['trigger'] ) : 'auto',
				'endpoint' => isset( $entry['endpoint'] ) ? esc_url_raw( $entry['endpoint'] ) : '',
				'status'   => isset( $entry['status'] ) ? absint( $entry['status'] ) : 0,
				'success'  => ! empty( $entry['success'] ),
				'message'  => isset( $entry['message'] ) ? sanitize_text_field( $entry['message'] ) : '',
				'count'    => isset( $entry['count'] ) ? max( 1, absint( $entry['count'] ) ) : 1,
			)
		);

		$max_logs = (int) apply_filters( 'wpfh_in_max_logs', self::MAX_LOGS );
		if ( count( $logs ) > $max_logs ) {
			$logs = array_slice( $logs, 0, $max_logs );
		}

		update_option( self::LOGS_OPTION, $logs, false );
	}
}

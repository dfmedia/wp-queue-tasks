<?php

namespace WPQueueTasks;

/**
 * Class Handler - Sets up the REST api endpoint for handling async requests
 *
 * @package WPQueueTasks
 */
class Handler {

	/**
	 * Stores the namespace that the REST route should live under
	 */
	const API_NAMESPACE = 'wpqt/v1';

	/**
	 * Stores the name of the endpoint
	 */
	const ENDPOINT_RUN = 'queue';

	/**
	 * Sets up the REST handler
	 */
	public function setup() {

		// Registers the rest endpoint to handle async processing
		add_action( 'rest_api_init', [ $this, 'register_rest_endpoint' ] );
	}

	/**
	 * Register the rest endpoint for async processing
	 *
	 * @access public
	 * @return void
	 */
	public function register_rest_endpoint() {

		register_rest_route(
			self::API_NAMESPACE, '/' . self::ENDPOINT_RUN . '/(?P<queue>[\w|-]+)', [
				'methods'             => 'PUT',
				'callback'            => [ $this, 'run_queue_processor' ],
				'permission_callback' => [ $this, 'check_rest_permissions' ],
				'show_in_index'       => false,
			]
		);

	}

	/**
	 * Runs the processor within the REST endpoint when you hit it
	 *
	 * @param \WP_REST_Request $request The incoming request object
	 *
	 * @return \WP_Error|\WP_REST_Response
	 * @access public
	 */
	public function run_queue_processor( $request ) {

		$queue_name = ( isset( $request['queue'] ) ) ? $request['queue'] : '';
		$body = json_decode( $request->get_body(), true );
		$term_id = ( ! empty( $body['term_id'] ) ) ? absint( $body['term_id'] ) : 0;
		$lock = ( ! empty( $body['lock'] ) ) ? sanitize_text_field( $body['lock'] ) : '';

		if ( empty( $queue_name ) || empty( $term_id ) ) {
			return rest_ensure_response(
				new \WP_Error( 'rest-queue-invalid', __( 'No queue name or ID passed', 'wp-queue-tasks' ) )
			);
		}

		do_action( 'wp_queue_tasks_run_processor', $queue_name, $term_id, $lock );

		return rest_ensure_response( sprintf( __( '%s queue processed', 'wp-queue-tasks' ), $queue_name ) );

	}

	/**
	 * Validates the defined secret so script kiddies aren't hitting our endpoint over and over
	 *
	 * @param \WP_REST_Request $request The incoming request object
	 *
	 * @return bool|\WP_Error
	 * @access public
	 */
	public function check_rest_permissions( $request ) {

		$body = json_decode( $request->get_body(), true );

		if (
			! defined( 'WP_QUEUE_TASKS_PROCESSOR_SECRET' ) ||
			! isset( $body['secret'] ) ||
			! hash_equals( WP_QUEUE_TASKS_PROCESSOR_SECRET, $body['secret'] )
		) {
			return new \WP_Error( 'no-secret', __( 'Secret must be defined and passed to the request for the processor to run', 'wp-queue-tasks' ) );
		}

		return true;

	}

}

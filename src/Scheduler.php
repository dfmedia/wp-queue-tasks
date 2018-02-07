<?php

namespace WPQueueTasks;

/**
 * Class Scheduler - Schedules a queue to be processed
 *
 * @package WPQueueTasks
 */
class Scheduler {

	public function setup() {
		// Process the queue
		add_action( 'shutdown', [ $this, 'process_queue' ], 999 );
	}

	/**
	 * Queries all of the queue's and decides if we should process them. If the queue needs to be
	 * process it will post a request to process them asynchronously. We are processing the queue
	 * async, because it will give us a fresh thread to do it, and avoid timeouts.
	 *
	 * @access public
	 * @return void
	 */
	public function process_queue() {

		$queues = get_terms( [ 'taxonomy' => 'task-queue' ] );
		global $wpqt_queues;

		if ( ! empty( $queues ) && is_array( $queues ) ) {
			foreach ( $queues as $queue ) {

				// If the term has no associated queue, bail.
				if ( empty( $wpqt_queues[ $queue->name ] ) ) {
					continue;
				}

				// If the queue is already being processed, bail.
				if ( false !== Utils::is_queue_process_locked( $queue->name ) ) {
					continue;
				}

				// If the queue doesn't have enough items, or is set to process at a certain interval, bail.
				if ( false === $this->should_process( $queue->name, $queue->term_id, $queue->count ) ) {
					continue;
				}

				// Lock the queue process so another process can't pick it up.
				// The queue will be unlocked in Processor::process_queue
				Utils::lock_queue_process( $queue->name );

				if ( ! empty( $wpqt_queues[ $queue->name ] ) && 'async' === $wpqt_queues[ $queue->name ]->processor ) {
					// Post to the async task handler to process this specific queue
					$this->post_to_processor( $queue->name, $queue->term_id );
				} else {
					$this->schedule_cron( $queue->name, $queue->term_id );
				}

			}
		}

	}

	/**
	 * Determines whether or not the queue should be processed
	 *
	 * @param string $queue_name  Name of the queue being processed
	 * @param int    $queue_id    Term ID for the queue being processed
	 * @param int    $queue_count The amount of tasks attached to the queue
	 *
	 * @access private
	 * @return bool
	 */
	private function should_process( $queue_name, $queue_id, $queue_count ) {

		global $wpqt_queues;

		$current_queue_settings = $wpqt_queues[ $queue_name ];

		// If there aren't enough items in this queue, bail.
		if ( $current_queue_settings->minimum_count > $queue_count ) {
			return false;
		}

		// Check to see if the queue has an update interval, and compare it to the current time to see if it's
		// time to run again.
		if ( false !== $current_queue_settings->update_interval ) {
			$last_ran = get_term_meta( $queue_id, 'wpqt_queue_last_run', true );
			if ( '' !== $last_ran && ( $last_ran + $current_queue_settings->update_interval ) > time() ) {
				return false;
			}
		}

		return true;

	}

	/**
	 * Handle the post request to the async handler
	 *
	 * @param string $queue_name Name of the queue to process
	 * @param int    $queue_id   Term ID of the queue to process
	 *
	 * @access private
	 * @return \WP_Error|true
	 */
	private function post_to_processor( $queue_name, $queue_id ) {

		if ( ! defined( 'WP_QUEUE_TASKS_PROCESSOR_SECRET' ) ) {
			return new \WP_Error( 'no-secret', __( 'You need to define the WP_QUEUE_TASKS_PROCESSOR_SECRET constant in order to use this feature', 'wp-queue-tasks' ) );
		}

		$request_args = [
			'timeout'  => 0.01,
			'blocking' => false,
			'method'   => 'PUT',
			'body'     => wp_json_encode( [
				'term_id' => $queue_id,
				'secret'  => WP_QUEUE_TASKS_PROCESSOR_SECRET,
			] ),
		];

		$url = get_rest_url( null, Handler::API_NAMESPACE . '/' . Handler::ENDPOINT_RUN . '/' . $queue_name );
		wp_safe_remote_post( $url, $request_args );
		return true;

	}

	/**
	 * Schedules a cron event to run the processor
	 *
	 * @param string $queue_name Name of the queue to process
	 * @param int    $queue_id   ID of the queue's term. We need this later, and know it at this
	 *                           point, so let's pass it along
	 *
	 * @access private
	 * @return void
	 */
	private function schedule_cron( $queue_name, $queue_id ) {
		wp_schedule_single_event( time(), 'wp_queue_tasks_run_processor', [
			'queue_name' => $queue_name,
			'term_id'    => $queue_id,
		] );
	}

}

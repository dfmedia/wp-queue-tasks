<?php

namespace WPQueueTasks;

/**
 * Class Processor: Accepts requests to kick off the processing of a queue
 */
class Processor {

	/**
	 * Stores the name of the queue being processed
	 *
	 * @var string $queue_name
	 * @access private
	 */
	private $queue_name = '';

	/**
	 * Stores the term ID of the queue being processed
	 *
	 * @var int $queue_id
	 * @access private
	 */
	private $queue_id = 0;

	/**
	 * Stores the slug of the queue to add failed tasks to
	 *
	 * @var string
	 * @access private
	 */
	private $failed_queue = 'failed';

	/**
	 * Sets up all of the actions we need for the class
	 */
	public function setup() {
		add_action( 'init', [ $this, 'setup_processing' ] );
		add_action( 'wpqt_run_processor', [ $this, 'run_processor' ], 10, 2 );
	}

	/**
	 * Sets up the handlers for each of the queues
	 *
	 * @access public
	 * @return void
	 */
	public function setup_processing() {

		global $wpqt_queues;

		if ( ! empty( $wpqt_queues ) && is_array( $wpqt_queues ) ) {
			foreach ( $wpqt_queues as $queue_name => $queue_args ) {
				if ( 'async' === $queue_args->processor ) {
					add_action( 'admin_post_nopriv_wpqt_process_' . $queue_name, [ $this, 'process_queue' ] );
				}
			}
		}
	}

	/**
	 * Handles the incoming async post request, and creates the hook for running the processor
	 *
	 * @access public
	 * @return void
	 */
	public function process_queue() {

		// Accept some data from the request
		$queue_name = empty( $_POST['queue_name'] ) ? '' : $_POST['queue_name'];
		$term_id = empty( $_POST['term_id'] ) ? 0 : $_POST['term_id'];

		do_action( 'wpqt_run_processor', $queue_name, $term_id );

	}

	/**
	 * Method that actually runs the processor that iterates over the queue to process tasks.
	 * This is used for both the async processor and the cron processor.
	 *
	 * @param string $queue_name The name of the queue being processed
	 * @param int    $term_id    The term ID of the queue
	 *
	 * @access public
	 * @return bool
	 */
	public function run_processor( $queue_name, $term_id ) {

		// If the queue name, or term ID wasn't set, bail.
		if ( empty( $queue_name ) || 0 === absint( $term_id ) ) {
			return false;
		}

		// Set vars to class property so we don't have to pass them down to other methods
		$this->queue_name = $queue_name;
		$this->queue_id = $term_id;

		global $wpqt_queues;
		$current_queue_settings = ! empty( $wpqt_queues[ $queue_name ] ) ? $wpqt_queues[ $queue_name ] : false;

		// If we can't find the corresponding queue settings, bail.
		if ( empty( $current_queue_settings ) ) {
			return false;
		}

		/**
		 * The maximum amount of tasks a single processor should process
		 *
		 * @param string $queue_name The name of the queue the processor is iterating over
		 * @param int $term_id The term ID of the queue
		 *
		 * @return int
		 */
		$max_tasks = apply_filters( 'wpqt_max_tasks_to_process', 100, $queue_name, $term_id );

		$task_args = [
			'post_type'      => 'wpqt-task',
			'post_status'    => 'publish',
			'posts_per_page' => $max_tasks,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'tax_query'      => [
				[
					'taxonomy' => 'task-queue',
					'terms'    => $term_id,
					'field'    => 'term_id',
				],
			],
		];

		$task_query = new \WP_Query( $task_args );

		// Create an empty array to add tasks to if bulk processing is supported
		$tasks = [];

		// Create an empty array to add the ID's of tasks that have been successfully processed
		$successful_tasks = [];

		// Empty array to store failed tasks in
		$failed_tasks = [];

		if ( $task_query->have_posts() ) :
			while ( $task_query->have_posts() ) : $task_query->the_post();

				global $post;

				// If the queue supports bulk processing, add the payloads to an array to pass back to the queue
				// callback with the task ID as the key.
				if ( true === $current_queue_settings->bulk_processing_support ) {
					$tasks[ $post->ID ] = $post->post_content;
				} else {

					// Send one off requests to the queue callback, if it doesn't support bulk processing
					$result = call_user_func( $current_queue_settings->callback, $post->post_content );

					// If the callback didn't fail add the task ID to the removal array
					if ( false !== $result && ! is_wp_error( $result ) ) {
						$successful_tasks[] = $post->ID;
					} else {

						$failed_tasks[] = $post->ID;

						/**
						 * Hook that fires if a single task fails to be processed
						 *
						 * @param array           $failed_tasks    ID's of tasks that have failed
						 * @param array           $successful_tasks ID's of tasks that can be removed from the queue at this point
						 * @param Object|\WP_Post $post            The WP_Post object of the failed task
						 * @param false|\WP_Error $result          The value returned by the callback
						 * @param string          $queue_name      The name of the queue that this failure happened in
						 */
						do_action( 'wpqt_single_task_failed', $failed_tasks, $successful_tasks, $post, $result, $queue_name );
					}
				}

			endwhile;
		endif;
		wp_reset_postdata();

		// If the queue supports bulk processing, send all of the payloads to the callback.
		if ( true === $current_queue_settings->bulk_processing_support ) {

			$successful_tasks = call_user_func( $current_queue_settings->callback, $tasks );

			/**
			 * If the callback returns fewer tasks than we passed to it, some of them didn't get
			 * processed, so fire a hook for debugging purposes.
			 */
			if ( count( $successful_tasks ) < count( $tasks ) ) {

				$failed_tasks = array_diff( array_keys( $tasks ), $successful_tasks );

				/**
				 * Hook that fires when one or more bulk processing tasks fail to process
				 *
				 * @param array  $failed_tasks     Array of tasks that have failed to process
				 * @param array  $successful_tasks The array of task ID's returned from the callback to delete
				 * @param array  $tasks            The array of tasks that was passed to the callback for deletion
				 * @param string $queue_name       The name of the queue that this failure happened in
				 */
				do_action( 'wpqt_bulk_processing_failed', $failed_tasks, $successful_tasks, $tasks, $queue_name );
			}

		}

		// Remove all of the tasks from the queue
		$this->remove_tasks_from_queue( $successful_tasks, $failed_tasks );

		if ( $max_tasks === $task_query->post_count || count( $successful_tasks ) < $task_query->post_count && false !== $current_queue_settings->update_interval ) {
			// Delete the last_run meta if this queue needs to be processed again
			delete_term_meta( $term_id, 'wpqt_queue_last_run' );
		} else {
			// Add some metadata about the last run time
			update_term_meta( $term_id, 'wpqt_queue_last_run', time() );
		}

		// Unlock the queue so it can be processed in the future
		Utils::unlock_queue_process( $queue_name );

		return true;

	}

	/**
	 * Removes tasks from the queue by either deleting them entirely, or removing the queue's term
	 * from the task
	 *
	 * @param array $successful_tasks Array of ID's for the tasks we should remove from the queue
	 * @param array $failed_tasks     Array of task ID's that failed, that may need retries
	 *                                scheduled
	 *
	 * @access private
	 * @return void
	 */
	private function remove_tasks_from_queue( $successful_tasks, $failed_tasks ) {

		$tasks_out_of_retries = $this->schedule_retry( $failed_tasks );
		$tasks = array_merge( $successful_tasks, $tasks_out_of_retries );

		if ( ! empty( $tasks ) && is_array( $tasks ) ) {
			foreach ( $tasks as $task ) {

				// Get the queues attached to the task
				$queues_attached = get_the_terms( $task, 'task-queue' );

				// If the task was successful, and only in the one queue, remove it.
				if ( in_array( $task, $successful_tasks, true ) && ( ! empty( $queues_attached ) && 1 === count( $queues_attached ) ) ) {
					wp_delete_post( $task, true );
				} else {
					// Remove the task from the current queue
					wp_remove_object_terms( $task, $this->queue_id, 'task-queue' );
				}

				if ( in_array( $task, $tasks_out_of_retries, true ) ) {
					// If the task failed too many times, put it in the "failed" queue for safe keeping.
					wp_set_object_terms( $task, $this->queue_name . '_' . $this->failed_queue, 'task-queue', true );
				}

			}
		}

	}

	/**
	 * Schedule a task for retry, or determine it is out of retry's and should be removed from the
	 * queue permanently
	 *
	 * @param array $failed_tasks Array of tasks that failed to process
	 * @return array
	 * @access private
	 */
	private function schedule_retry( $failed_tasks ) {

		$tasks = [];

		if ( ! empty( $failed_tasks ) && is_array( $failed_tasks ) ) {

			global $wpqt_queues;
			$retry_limit = ( ! empty( $wpqt_queues[ $this->queue_name ]->retry ) ) ? absint( $wpqt_queues[ $this->queue_name ]->retry ) : 0;

			foreach ( $failed_tasks as $task ) {

				/**
				 * If this queue doesn't allow for any retries, schedule the post for deletion
				 */
				if ( 0 === $retry_limit ) {
					$tasks[] = $task;
				} else {

					$current_retry = get_post_meta( $task, 'wpqt_retry', true );

					if ( empty( $current_retry ) ) {
						$current_count = 0;
						$current_retry = [];
					} else {
						$current_count = ( ! empty( $current_retry[ $this->queue_name ] ) ) ? absint( $current_retry[ $this->queue_name ] ) : 0;
					}

					if ( $current_count >= $retry_limit ) {
						$tasks[] = $task;
					} else {
						update_post_meta( $task, 'wpqt_retry', array_merge( $current_retry, [ $this->queue_name => $current_count + 1 ] ) );
					}

				}

			}

		}

		return $tasks;

	}
}

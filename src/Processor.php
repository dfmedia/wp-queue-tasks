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
		add_action( 'wp_queue_tasks_run_processor', [ $this, 'run_processor' ], 10, 3 );
	}

	/**
	 * Method that actually runs the processor that iterates over the queue to process tasks.
	 * This is used for both the async processor and the cron processor.
	 *
	 * @param string $queue_name The name of the queue being processed
	 * @param int    $term_id    The term ID of the queue
	 * @param string $lock       The lock to check against for the process
	 *
	 * @access public
	 * @return bool
	 */
	public function run_processor( $queue_name, $term_id, $lock ) {

		// If the queue name, or term ID wasn't set, bail.
		if ( empty( $queue_name ) || 0 === absint( $term_id ) ) {
			return false;
		}

		// Check to make sure the current process owns the lock
		if ( false === Utils::owns_lock( $queue_name, $lock ) ) {
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
		$max_tasks = apply_filters( 'wp_queue_tasks_max_tasks_to_process', 100, $queue_name, $term_id );

		$task_args = [
			'post_type'      => 'wpqt-task',
			'post_status'    => 'publish',
			'posts_per_page' => absint( $max_tasks ),
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
				if ( true === $current_queue_settings->bulk ) {
					$tasks[ $post->ID ] = $post->post_content;
				} else {

					// Prevent an error in the callback from holding up the entire queue
					try {
						// Send one off requests to the queue callback, if it doesn't support bulk processing
						$result = call_user_func( $current_queue_settings->callback, $post->post_content, $queue_name );
					} catch ( \Throwable $error ) {

						/**
						 * Hook that fires if an error occurs in the callback
						 *
						 * @param \Throwable $error      The error thrown from the callback
						 * @param \WP_Post   $post       The post object for the task that threw the error
						 * @param string     $queue_name Name of the queue that this failure happened in
						 */
						do_action( 'wp_queue_tasks_single_task_error', $error, $post, $queue_name );
						$result = new \WP_Error(
							'callback-error',
							sprintf( __( 'Could not process task ID: %d because of an error', 'wp-queue-tasks' ), $post->ID )
						);
					}

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
						do_action( 'wp_queue_tasks_single_task_failed', $failed_tasks, $successful_tasks, $post, $result, $queue_name );
					}
				}

			endwhile;
		else :

			/**
			 * If no posts were found then it's reasonable to expect that the term count for the
			 * queue is out of sync with the tasks within the queue.
			 */
			wp_update_term_count( $term_id, 'task-queue' );

		endif;
		wp_reset_postdata();

		// If the queue supports bulk processing, send all of the payloads to the callback.
		if ( true === $current_queue_settings->bulk ) {

			try {
				$successful_tasks = call_user_func( $current_queue_settings->callback, $tasks, $queue_name );
			} catch ( \Throwable $error ) {

				/**
				 * Hook that fires if an error occurs within the callback
				 *
				 * @param \Throwable $error      The error thrown by the callback
				 * @param array      $tasks      The task ID's and tasks passed to the callback
				 * @param string     $queue_name Name of the queue the failure happened in
				 */
				do_action( 'wp_queue_tasks_bulk_processing_error', $error, $tasks, $queue_name );
			}

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
				do_action( 'wp_queue_tasks_bulk_processing_failed', $failed_tasks, $successful_tasks, $tasks, $queue_name );
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

				wp_remove_object_terms( $task, $this->queue_id, 'task-queue' );

				if ( in_array( $task, $tasks_out_of_retries, true ) ) {
					// If the task failed too many times, put it in the "failed" queue for safe keeping.
					wp_set_object_terms( $task, $this->queue_name . '_' . $this->failed_queue, 'task-queue', true );
				}

				// Get the queues attached to the task
				$queues_attached = get_the_terms( $task, 'task-queue' );

				// If no queues are left on the task, delete it.
				if ( false === $queues_attached ) {
					wp_delete_post( $task, true );
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
						$new_retry = $current_retry;
						unset( $new_retry[ $this->queue_name ] );

						/**
						 * Hook that fires when we are out of retries for this particular queue
						 *
						 * @param int $task ID of the task that failed
						 * @param string $queue_name Name of the queue this failure happened
						 * @param int $queue_id Term ID of the queue this failure happened in
						 */
						do_action( 'wpqt_remove_task_from_queue_too_many_failures', $task, $this->queue_name, $this->queue_id );

					} else {
						$new_retry = array_merge( $current_retry, [ $this->queue_name => $current_count + 1 ] );
					}

					update_post_meta( $task, 'wpqt_retry', $new_retry );

				}

			}

		}

		return $tasks;

	}
}

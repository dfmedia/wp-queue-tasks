<?php

namespace WPQueueTasks;

/**
 * Class Processor: Accepts requests to kick off the processing of a queue
 */
class Processor {

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

		global $wpqt_queues;
		$current_queue_settings = ! empty( $wpqt_queues[ $queue_name ] ) ? $wpqt_queues[ $queue_name ] : false;

		// If we can't find the corresponding queue settings, bail.
		if ( empty( $current_queue_settings ) ) {
			return false;
		}

		$task_args = [
			'post_type'      => 'wpqt-task',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
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

		// Create an empty array to add the ID's of tasks that have been successfully deleted
		$tasks_to_delete = [];

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
						$tasks_to_delete[] = $post->ID;
					} else {

						/**
						 * Hook that fires if a single task fails to be processed
						 *
						 * @param array           $tasks_to_delete ID's of tasks that can be removed from the queue at this point
						 * @param Object|\WP_Post $post            The WP_Post object of the failed task
						 * @param false|\WP_Error $result          The value returned by the callback
						 * @param string          $queue_name      The name of the queue that this failure happened in
						 */
						do_action( 'wpqt_single_task_failed', $tasks_to_delete, $post, $result, $queue_name );
					}
				}

			endwhile;
		endif;
		wp_reset_postdata();

		// If the queue supports bulk processing, send all of the payloads to the callback.
		if ( true === $current_queue_settings->bulk_processing_support ) {
			$tasks_to_delete = call_user_func( $current_queue_settings->callback, $tasks );

			/**
			 * If the callback returns fewer tasks than we passed to it, some of them didn't get
			 * processed, so fire a hook for debugging purposes.
			 */
			if ( count( $tasks_to_delete ) < count( $tasks ) ) {

				/**
				 * Hook that fires when one or more bulk processing tasks fail to process
				 *
				 * @param array  $tasks_to_delete The array of task ID's returned from the callback to delete
				 * @param array  $tasks           The array of tasks that was passed to the callback for deletion
				 * @param string $queue_name      The name of the queue that this failure happened in
				 */
				do_action( 'wpqt_bulk_processing_failed', $tasks_to_delete, $tasks, $queue_name );
			}

		}

		// Remove all of the tasks from the queue
		$this->remove_tasks_from_queue( $tasks_to_delete, $term_id );

		// Add some metadata about the last run time
		update_term_meta( $term_id, 'wpqt_queue_last_run', time() );

		// Unlock the queue so it can be processed in the future
		Utils::unlock_queue_process( $queue_name );

		return true;

	}

	/**
	 * Removes tasks from the queue by either deleting them entirely, or removing the queue's term
	 * from the task
	 *
	 * @param array $tasks    Array of ID's for the tasks we should remove from the queue
	 * @param int   $queue_id Term ID of the queue we want to remove the task from
	 *
	 * @access private
	 * @return void
	 */
	private function remove_tasks_from_queue( $tasks, $queue_id ) {

		if ( ! empty( $tasks ) && is_array( $tasks ) ) {
			foreach ( $tasks as $task ) {

				// Get the queues attached to the task
				$queues_attached = get_the_terms( $task, 'task-queue' );

				// If there is more than one queue attached to the task, remove it from the current queue
				// otherwise delete the post.
				if ( ! empty( $queues_attached ) && 1 < count( $queues_attached ) ) {
					wp_remove_object_terms( $task, $queue_id, 'task-queue' );
				} else {
					wp_delete_post( $task, true );
				}

			}
		}

	}
}

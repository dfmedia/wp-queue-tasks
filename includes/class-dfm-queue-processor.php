<?php

if ( ! class_exists( 'DFM_Queue_Processor' ) ) {

	/**
	 * Class DFM_Queue_Processor: Accepts requests to kick off the processing of a queue
	 */
	class DFM_Queue_Processor {

		/**
		 * DFM_Queue_Processor constructor.
		 */
		public function __construct() {
			add_action( 'after_setup_theme', array( $this, 'setup_processing' ) );
		}

		/**
		 * Sets up the handlers for each of the queues
		 *
		 * @access public
		 * @return void
		 */
		public function setup_processing() {

			global $dfm_queues;

			if ( ! empty( $dfm_queues ) && is_array( $dfm_queues ) ) {
				foreach ( $dfm_queues as $queue_name => $queue_args ) {
					add_action( 'admin_post_nopriv_dfm_process_' . $queue_name, array( $this, 'process_queue' ) );
				}
			}
		}

		/**
		 * Loops through the queue and passes all of the tasks to the registered callback for processing.
		 *
		 * @access public
		 * @return void
		 */
		public function process_queue() {

			// Accept some data from the request
			$queue_name = empty( $_POST['queue_name'] ) ? '' : $_POST['queue_name'];
			$term_id = empty( $_POST['term_id'] ) ? 0 : $_POST['term_id'];

			// If the queue name, or term ID wasn't set, bail.
			if ( empty( $queue_name ) || 0 === absint( $term_id ) ) {
				die();
			}

			global $dfm_queues;
			$current_queue_settings = ! empty( $dfm_queues[ $queue_name ] ) ? $dfm_queues[ $queue_name ] : false;

			// If we can't find the corresponding queue settings, bail.
			if ( empty( $current_queue_settings ) ) {
				die();
			}

			$task_args = array(
				'post_type'      => 'dfm-task',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'tax_query'      => array(
					array(
						'taxonomy' => 'task-queue',
						'terms'    => $term_id,
						'field'    => 'term_id',
					),
				),
			);

			$task_query = new WP_Query( $task_args );

			// Create an empty array to add tasks to if bulk processing is supported
			$tasks = array();

			// Create an empty array to add the ID's of tasks that have been successfully deleted
			$tasks_to_delete = array();

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
						}
					}

				endwhile;
			endif;
			wp_reset_postdata();

			// If the queue supports bulk processing, send all of the payloads to the callback.
			if ( true === $current_queue_settings->bulk_processing_support ) {
				$tasks_to_delete = call_user_func( $current_queue_settings->callback, $tasks );
			}

			// Remove all of the tasks from the queue
			$this->remove_tasks_from_queue( $tasks_to_delete, $term_id );

			// Add some metadata about the last run time
			update_term_meta( $term_id, 'dfm_queue_last_run', time() );

			// Unlock the queue so it can be processed in the future
			DFM_Queue_Tasks::unlock_queue_process( $queue_name );

			// We're done here
			die();

		}

		/**
		 * Removes tasks from the queue by either deleting them entirely, or removing the queue's term from the task
		 *
		 * @param array $tasks Array of ID's for the tasks we should remove from the queue
		 * @param int $queue_id Term ID of the queue we want to remove the task from
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
}

new DFM_Queue_Processor();

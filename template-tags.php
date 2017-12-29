<?php

/**
 * Function to use for registering a new queue
 *
 * @param string $queue_name Name of the queue you want to register. Should be slug friendly (lower cases, no spaces)
 * @param array $args {
 * 		@arg callable $callback			   The callback function to handle the payload from the task
 * 		@arg bool|int $update_interval	   The interval in which the queue should be limited to process. Leave false if you
 * 										   want it to process on every shutdown.
 * 		@arg int $minimum_count			   The minimum amount of tasks to be in the queue before processing
 * 		@arg bool $bulk_processing_support Whether or not the queue can send an array of payloads at once, or needs to
 * 										   send them one at a time for each task.
 * 		@arg string $processor             What type of processor you would like to use to process the task queue.
 *      								   Options are "async" or "cron".
 * }
 *
 * @return void
 * @access public
 * @throws Exception
 */
function wpqt_register_queue( $queue_name, $args ) {

	global $wpqt_queues;

	// If there aren't any queues registered yet go ahead and create a new array to attach the first one to
	if ( ! is_array( $wpqt_queues ) ) {
		$wpqt_queues = [];
	}

	if ( empty( $args['callback'] ) ) {
		throw new Exception( __( 'You must add a callback when registering a queue', 'wp-queue-tasks' ) );
	}

	$default_args = [
		'callback'                => '',
		'update_interval'         => false,
		'minimum_count'           => 0,
		'bulk_processing_support' => true,
		'processor'               => 'async',
	];

	$args = wp_parse_args(

		/**
		 * Filters the args for registering a queue
		 *
		 * @param array $args The arguments we are trying to register
		 * @param string $queue_name Name of the queue we are registering
		 * @return array $args The args array should be returned
		 */
		apply_filters( 'wpqt_queue_registration_args', $args, $queue_name ),
		$default_args
	);

	if ( ! in_array( $args['processor'], [ 'async', 'cron' ], true ) ) {
		throw new Exception( __( 'An unsupported processor was specified. Please select either "async" or "cron" for the processor argument of your queue registration', 'wp-queue-tasks' ) );
	}

	// Type set to an object to stay consistent with other WP globally registered objects such as post types
	$wpqt_queues[ $queue_name ] = (object) $args;

}

/**
 * Creates the task post to be added to a queue.
 *
 * @param string|array $queues Either a single queue to add the task to, or an array of queue names to add the task to
 * @param string $data The data that should be processed by the queue's callback function
 *
 * @return int|WP_Error
 * @access public
 */
function wpqt_create_task( $queues, $data ) {

	/**
	 * Filter to add or remove queues to add to a task.
	 *
	 * @param string|array $queues The queues the task is going to be added to
	 * @param string $data The data to be saved in the task
	 */
	$queues = apply_filters( 'wpqt_task_create_queues', $queues, $data );

	/**
	 * Hook that fires before a new task is created
	 *
	 * @param string|array $queues The queues the task is going to be added to
	 * @param string $data The data to be stored in the_content of the task, and processed by the queue's callback
	 */
	do_action( 'before_wpqt_create_task', $queues, $data );

	$post_data = [
		'post_type' => 'wpqt-task',
		'post_content' => $data,
		'post_status' => 'publish',
	];

	$result = wp_insert_post( $post_data );

	if ( is_wp_error( $result ) ) {

		/**
		 * Hook to fire if we failed to create the actual task.
		 *
		 * @param string|array $queues The queues the task is going to be added to
		 * @param string $data The data to be stored in the_content of the task, and processed by the queue's callback
		 * @param WP_Error $result The error object if the post failed to be created
		 */
		do_action( 'wpqt_create_task_failed', $queues, $data, $result );
	} else {

		/**
		 * Set the object terms
		 */
		wp_set_object_terms( $result, $queues, 'task-queue' );

		/**
		 * Hook that fires after a task has been created
		 *
		 * @param string|array $queues The queues the task is going to be added to
		 * @param string $data The data to be stored in the_content of the task, and processed by the queue's callback
		 * @param int $result The ID of the task post
		 */
		do_action( 'after_wpqt_create_task', $queues, $data, $result );
	}

	return $result;

}

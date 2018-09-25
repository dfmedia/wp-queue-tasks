<?php

namespace WPQueueTasks;

/**
 * Class CLI - CLI utilities for the WP Queue Tasks plugin
 *
 * @package WPQueueTasks
 */
class CLI {

	/**
	 * Contains the name of the task post type
	 */
	const POST_TYPE = 'wpqt-task';

	/**
	 * Contains the name of the queue taxonomy
	 */
	const TAXONOMY = 'task-queue';

	/**
	 * Stores the supported properties for specific commands
	 *
	 * @var array $supported_props
	 * @access private
	 */
	private $supported_props = [];

	/**
	 * Lists all of the registered queues through WP-Queue-Tasks
	 *
	 * ## OPTIONS
	 * [<queue_slugs>...]
	 * : Optionally pass the names of the queues you want to get information about
	 *
	 * [--fields]
	 * : Fields to return
	 * ---
	 * default: all
	 * options:
	 *   - queue_slug
	 *   - update_interval
	 *   - minimum_count
	 *   - bulk
	 *   - processor
	 *   - retry
	 * ---
	 *
	 * [--format]
	 * : Render the output in a particular format
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - ids
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * [--<field>=<value>]
	 * : One or more fields to filter the list with
	 *
	 * @subcommand list
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function _list( $args, $assoc_args ) {

		global $wpqt_queues;
		$queues = $wpqt_queues;

		$this->supported_props = [ 'queue_slug', 'update_interval', 'minimum_count', 'bulk', 'processor', 'retry' ];

		if ( empty( $wpqt_queues ) ) {
			\WP_CLI::error( __( 'No queues are registered', 'wp-queue-tasks' ) );
		}

		$queue_names = ( isset( $args ) ) ? $args : [];

		if ( ! empty( $queue_names ) ) {
			$queues = array_intersect_key( $queues, array_flip( $queue_names ) );
		}

		if ( ! empty( $assoc_args ) ) {
			$filter_args = array_intersect_key( $assoc_args, array_flip( $this->supported_props ) );
			$queues = wp_list_filter( $queues, $filter_args );
		}

		if ( empty( $queues ) ) {
			\WP_CLI::error( __( 'No queues found with this criteria', 'wp-queue-tasks' ) );
		}

		foreach ( $queues as $queue_slug => $data ) {
			$data = (array) $data;
			if ( is_array( $data ) && ! empty( $data ) ) {
				foreach ( $data as $key => $value ) {

					if ( ! is_bool( $value ) ) {
						continue;
					}

					if ( true === $value ) {
						$new_value = 'true';
					} elseif ( false === $value ) {
						$new_value = 'false';
					}

					if ( isset( $new_value ) ) {
						$queues[ $queue_slug ]->$key = $new_value;
					}

				}
			}
			$queues[ $queue_slug ]->queue_slug = $queue_slug;
		}

		$this->format_output( $queues, $assoc_args );

	}

	/**
	 * Kicks the processor to process a queue
	 *
	 * <queue_slug>
	 * : Slug of the queue term
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function process( $args, $assoc_args ) {

		$queue_name = ( ! empty( $args[0] ) ) ? $args[0] : '';

		if ( empty( $queue_name ) ) {
			\WP_CLI::error( __( 'You need to pass a queue slug', 'wp-queue-tasks' ) );
		}

		$queue_term = get_term_by( 'slug', $queue_name, self::TAXONOMY );

		if ( ! is_a( $queue_term, 'WP_Term' ) ) {
			\WP_CLI::error( __( 'Could not find queue: %s by slug', 'wp-queue-tasks' ), $queue_name );
		}

		\WP_CLI::log( __( 'Starting Processor', 'wp-queue-tasks' ) );
		$lock = uniqid();
		Utils::lock_queue_process( $queue_name, $lock );
		$processor_obj = new Processor();
		$processor_obj->run_processor( $queue_name, $queue_term->term_id, $lock );
		Utils::unlock_queue_process( $queue_name );
		\WP_CLI::log( __( 'Processor ran', 'wp-queue-tasks' ) );

	}

	/**
	 * Tells you whether or not a queue is locked
	 *
	 * ## OPTIONS
	 * <queue_slug>
	 * : Slug of the queue term
	 *
	 * @subcommand is-locked
	 *
	 * @param array $args
	 */
	public function is_locked( $args ) {

		$queue_name = ( ! empty( $args[0] ) ) ? $args[0] : '';

		if ( empty( $queue_name ) ) {
			\WP_CLI::error( __( 'You need to pass a queue slug', 'wp-queue-tasks' ) );
		}

		$lock = Utils::is_queue_process_locked( $queue_name );
		$value = ( true === $lock ) ? 'yes' : 'no';

		\WP_CLI::success( $value );

	}

	/**
	 * Unlock a queue for processing
	 *
	 * ## OPTIONS
	 * <queue_slug>
	 * : Slug of the queue term
	 *
	 * @param array $args
	 */
	public function unlock( $args ) {

		$queue_name = ( ! empty( $args[0] ) ) ? $args[0] : '';

		if ( empty( $queue_name ) ) {
			\WP_CLI::error( __( 'You need to pass a queue slug', 'wp-queue-tasks' ) );
		}

		Utils::unlock_queue_process( $queue_name );

		\WP_CLI::success( sprintf( __( 'Queue unlocked for: %s', 'wp-queue-tasks' ), $queue_name ) );

	}

	/**
	 * Get the number of tasks in a particular queue
	 *
	 * ## OPTIONS
	 * <queue_slug>
	 * : Slug of the queue term to get the count for
	 *
	 * [--porcelain]
	 * : Set this flag to just return the number of tasks in the queue
	 *
	 * @subcommand count
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function queue_count( $args, $assoc_args ) {

		$queue_name = ( ! empty( $args[0] ) ) ? $args[0] : '';

		if ( empty( $queue_name ) ) {
			\WP_CLI::error( __( 'You need to pass a queue slug', 'wp-queue-tasks' ) );
		}

		$args = [
			'post_type' => self::POST_TYPE,
			'tax_query' => [
				[
					'taxonomy' => self::TAXONOMY,
					'field' => 'slug',
					'terms' => $queue_name,
				],
			],
		];

		$tasks = new \WP_Query( $args );
		$post_num = $tasks->found_posts;

		if ( isset( $assoc_args['porcelain'] ) ) {
			\WP_CLI::log( $post_num );
		} else {
			\WP_CLI::success( sprintf( __( '%d tasks found', 'wp-queue-tasks' ), $post_num ) );
		}
	}

	/**
	 * Move tasks from the failed queue back into their normal queue for processing.
	 *
	 * ## OPTIONS
	 * <queue_slug>
	 * : Slug of the queue you want to move it's failed tasks back to
	 *
	 * [--quiet]
	 * : Whether or not to output updates during processing
	 *
	 * [--verbose]
	 * : Whether or not to have more verbose feedback during processing
	 *
	 * @subcommand retry-failed
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function retry_failed( $args, $assoc_args ) {

		$queue_name = ( ! empty( $args[0] ) ) ? $args[0] : '';

		if ( empty( $queue_name ) ) {
			\WP_CLI::error( __( 'You need to pass a queue slug', 'wp-queue-tasks' ) );
		}

		$failed_slug = $queue_name . '_failed';
		$quiet = ( isset( $assoc_args['quiet'] ) ) ? true : false;
		$verbose = ( isset( $assoc_args['verbose'] ) ) ? true : false;

		if ( false === $quiet ) {
			\WP_CLI::log( __( 'Retrieving failed tasks...', 'wp-queue-tasks' ) );
		}

		$failed_tasks = $this->get_failed_tasks( $failed_slug );

		if ( $failed_tasks->have_posts() ) {

			$task_ids = $failed_tasks->posts;

			if ( false === $quiet ) {
				\WP_CLI::log( sprintf( __( '%d tasks found to move', 'wp-queue-tasks' ), count( $task_ids ) ) );
			}

			if ( false === $verbose && false === $quiet ) {
				$progress_bar = \WP_CLI\Utils\make_progress_bar( __( 'Adding tasks back to queue', 'wp-queue-tasks' ) . ': ', count( $task_ids ) );
			}

			foreach ( $task_ids as $task_id ) {
				wp_remove_object_terms( $task_id, $failed_slug, self::TAXONOMY );
				wp_add_object_terms( $task_id, $queue_name, self::TAXONOMY );
				if ( true === $verbose ) {
					\WP_CLI::success( sprintf( __( 'Moved task ID %d back into the queue', 'wp-queue-tasks' ), $task_id ) );
				} elseif ( isset( $progress_bar ) ) {
					$progress_bar->tick();
				}
			}

			if ( isset( $progress_bar ) ) {
				$progress_bar->finish();
			}

		} else {
			\WP_CLI::error( sprintf( __( 'No posts found in the failed queue for: %s', 'wp-queue-tasks' ), $queue_name ) );
		}

		\WP_CLI::success( __( 'Done moving tasks', 'wp-queue-tasks' ) );

	}

	/**
	 * Deletes failed tasks in a particular queue
	 *
	 * <queue_slug>
	 * : Slug of the queue you want to delete the failures for
	 *
	 *
	 * [--quiet]
	 * : Whether or not to output updates during processing
	 *
	 * [--verbose]
	 * : Whether or not to have more verbose feedback during processing
	 *
	 * [--force]
	 * : Whether or not to force the failures to be deleted regardless of what queues they are also in
	 *
	 * @subcommand delete-failed
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function delete_failed( $args, $assoc_args ) {

		$queue_name = ( ! empty( $args[0] ) ) ? $args[0] : '';

		if ( empty( $queue_name ) ) {
			\WP_CLI::error( __( 'You need to pass a queue slug', 'wp-queue-tasks' ) );
		}

		$failed_slug = $queue_name . '_failed';
		$quiet = ( isset( $assoc_args['quiet'] ) ) ? true : false;
		$verbose = ( isset( $assoc_args['verbose'] ) ) ? true : false;

		if ( false === $quiet ) {
			\WP_CLI::log( __( 'Retrieving failed tasks...', 'wp-queue-tasks' ) );
		}

		$failed_tasks = $this->get_failed_tasks( $failed_slug );

		if ( $failed_tasks->have_posts() ) {

			$task_ids = $failed_tasks->posts;

			if ( false === $quiet ) {
				\WP_CLI::log( sprintf( __( '%d tasks found to delete', 'wp-queue-tasks' ), count( $task_ids ) ) );
			}

			if ( false === $verbose && false === $quiet ) {
				$progress_bar = \WP_CLI\Utils\make_progress_bar( __( 'Deleting tasks', 'wp-queue-tasks' ) . ': ', count( $task_ids ) );
			}

			foreach ( $task_ids as $task_id ) {

				// Get the queues attached to the task
				$queues_attached = get_the_terms( $task_id, self::TAXONOMY );

				if ( ( ! empty( $queues_attached ) && 1 === count( $queues_attached ) ) || isset( $assoc_args['force'] ) ) {
					wp_delete_post( $task_id, true );
				} else {
					wp_remove_object_terms( $task_id, $failed_slug, self::TAXONOMY );
				}

				if ( true === $verbose ) {
					\WP_CLI::success( sprintf( __( 'Removed task ID %1$d from the %2$s queue', 'wp-queue-tasks' ), $task_id, $queue_name ) );
				} elseif ( isset( $progress_bar ) ) {
					$progress_bar->tick();
				}

			}

			if ( isset( $progress_bar ) ) {
				$progress_bar->finish();
			}

		} else {
			\WP_CLI::error( sprintf( __( 'No posts found in the failed queue for: %s', 'wp-queue-tasks' ), $queue_name ) );
		}

		\WP_CLI::success( __( 'Done deleting tasks', 'wp-queue-tasks' ) );

	}

	/**
	 * Retrieve the failed tasks within a given queue
	 *
	 * @param string $queue Name of the queue to retrieve failed tasks for
	 *
	 * @return \WP_Query
	 * @access private
	 */
	private function get_failed_tasks( $queue ) {

		$args = [
			'post_type'      => self::POST_TYPE,
			'posts_per_page' => 999,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'order'          => 'ASC',
			'orderby'        => 'date',
			'tax_query'      => [
				[
					'taxonomy' => self::TAXONOMY,
					'field'    => 'slug',
					'terms'    => $queue,
				],
			],
		];

		return new \WP_Query( $args );

	}

	/**
	 * Handles the formatting of output
	 *
	 * @param array $queues The data to display
	 * @param array $assoc_args Args so we know how to display it
	 */
	private function format_output( $queues, $assoc_args ) {
		if ( ! empty( $assoc_args['fields'] ) ) {
			if ( is_string( $assoc_args['fields'] ) ) {
				$fields = explode( ',', $assoc_args['fields'] );
			} else {
				$fields = $assoc_args['fields'];
			}
			$fields = array_intersect( $fields, $this->supported_props );
		} else {
			$fields = $this->supported_props;
		}
		$formatter = new \WP_CLI\Formatter( $assoc_args, $fields );
		$formatter->display_items( $queues );
	}

}

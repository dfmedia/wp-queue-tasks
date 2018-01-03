<?php

use \WPQueueTasks\Processor;
use \WPQueueTasks\Utils;

class TestProcessor extends WP_UnitTestCase {

	private $taxonomy = 'task-queue';

	/**
	 * Test to make sure the processor is setup and callbacks are hooked correctly
	 */
	public function testProcessorSetup() {

		$processor_obj = new Processor();
		$processor_obj->setup();
		$this->assertEquals( 10, has_action( 'init', [ $processor_obj, 'setup_processing' ] ) );
		$this->assertEquals( 10, has_action( 'wpqt_run_processor', [ $processor_obj, 'run_processor' ] ) );

	}

	/**
	 * Test that the hook fires correctly for the async handler
	 */
	public function testProcessQueueFromAsyncHandler() {

		$expected = [
			'queue_name' => 'testProcessQueueFromAsyncHandler',
			'term_id' => 1,
		];

		$_POST['queue_name'] = $expected['queue_name'];
		$_POST['term_id'] = $expected['term_id'];

		add_action( 'wpqt_run_processor', function( $queue_name, $term_id ) {
			global $_test_wpqt_run_processor_data;
			$_test_wpqt_run_processor_data = [
				'queue_name' => $queue_name,
				'term_id' => $term_id,
			];
		}, 10, 2 );

		$processor_obj = new Processor();
		$processor_obj->process_queue();

		global $_test_wpqt_run_processor_data;

		$this->assertEquals( $expected, $_test_wpqt_run_processor_data );

	}

	/**
	 * Make sure that the processor doesn't run if we pass it a bum term id
	 */
	public function testHaultProcessorNoTermId() {

		$processor_obj = new Processor();
		$actual = $processor_obj->run_processor( 'test', 0 );
		$this->assertFalse( $actual );

	}

	/**
	 * Make sure the processor doesn't run if we pass it an unregistered queue somehow
	 */
	public function testHaultProcessorNoQueueName() {

		$processor_obj = new Processor();
		$actual = $processor_obj->run_processor( 'sometest', 2 );
		$this->assertFalse( $actual );

	}

	/**
	 * Test to make sure the proper hooks are added for handling async requests only when the queue
	 * is using that as the processor
	 */
	public function testProcessingSetup() {

		$queue = 'testProcessingSetup';
		wpqt_register_queue( $queue, [ 'callback' => '__return_true' ] );
		wpqt_register_queue( 'someOtherQueue', [ 'callback' => '__return_true', 'processor' => 'cron' ] );

		$processor_obj = new Processor();
		$processor_obj->setup_processing();

		$this->assertTrue( has_action( 'admin_post_nopriv_wpqt_process_' . $queue ) );
		$this->assertFalse( has_action( 'admin_post_nopriv_wpgt_process_someOtherQueue' ) );

	}

	/**
	 * Baseline test to make sure processing works
	 */
	public function testSuccessfulTaskProcessing() {

		$queue = 'testSuccessfulTaskProcessing';
		wpqt_register_queue( $queue,
			[
				'callback' => function( $data ) use( $queue ) {
					update_option( '_test_' . $queue, $data );
				},
				'bulk' => false,
			]
		);

		$expected = 'testvalue';
		$post_id = wpqt_create_task( $queue, $expected );
		$term_obj = get_term_by( 'name', $queue, $this->taxonomy );

		Utils::lock_queue_process( $queue );
		$processor_obj = new Processor();
		$result = $processor_obj->run_processor( $queue, $term_obj->term_id );

		$this->assertTrue( $result );
		$this->assertEquals( $expected, get_option( '_test_' . $queue ) );
		$this->assertFalse( Utils::is_queue_process_locked( $queue ) );
		$this->assertNull( get_post( $post_id ) );
		$this->assertNotEmpty( get_term_meta( $term_obj->term_id, 'wpqt_queue_last_run', true ) );

	}

	/**
	 * Test to make sure a task in multiple queues only gets removed from the one queue it's been
	 * processed in rather than deleted.
	 */
	public function testSuccessfulTaskProcessingMultipleQueues() {

		$queue_1 = 'testSuccessfulTaskProcessingMultipleQueues';
		$queue_2 = 'testSuccessfulTaskProcessingMultipleQueues2';
		wpqt_register_queue( $queue_1, [
			'callback' => [ $this, 'queue_processor_callback' ],
			'bulk' => false,
		] );
		wpqt_register_queue( $queue_2, [
			'callback' => [ $this, 'queue_processor_callback' ],
			'bulk' => false,
		] );

		$expected_value = 'my data';
		$task_id = wpqt_create_task( [ $queue_1, $queue_2 ], $expected_value );

		Utils::lock_queue_process( $queue_1 );
		$processor_obj = new Processor();
		$queue_1_id = get_term_by( 'name', $queue_1, $this->taxonomy );
		$result = $processor_obj->run_processor( $queue_1, $queue_1_id->term_id );

		$this->assertTrue( $result );
		$this->assertEquals( [ $expected_value ], get_option( '_test_queue_processor_callback' ) );
		$this->assertNotNull( get_post( $task_id ) );
		$this->assertFalse( Utils::is_queue_process_locked( $queue_1 ) );

		Utils::lock_queue_process( $queue_2 );
		$queue_2_id = get_term_by( 'name', $queue_2, $this->taxonomy );
		$result = $processor_obj->run_processor( $queue_2, $queue_2_id->term_id );

		$this->assertTrue( $result );
		$this->assertEquals( [ $expected_value, $expected_value ], get_option( '_test_queue_processor_callback' ) );
		$this->assertNull( get_post( $task_id ) );
		$this->assertFalse( Utils::is_queue_process_locked( $queue_2 ) );
		delete_option( '_test_queue_processor_callback' );

	}

	/**
	 * Test for batch processing of tasks
	 */
	public function testSuccessfulBatchProcessing() {

		$queue = 'testSuccessfulBatchProcessing';
		wpqt_register_queue( $queue, [ 'callback' => [ $this, 'queue_processor_callback' ] ] );
		$task_id_1 = wpqt_create_task( $queue, 'some data' );
		$task_id_2 = wpqt_create_task( $queue, 'some other data' );

		Utils::lock_queue_process( $queue );
		$processor_obj = new Processor();
		$queue_id = get_term_by( 'name', $queue, $this->taxonomy );
		$result = $processor_obj->run_processor( $queue, $queue_id->term_id );

		$this->assertTrue( $result );
		$this->assertEquals( [
			[
				$task_id_1 => 'some data',
				$task_id_2 => 'some other data',
			]
		], get_option( '_test_queue_processor_callback') );
		$this->assertNull( get_post( $task_id_1 ) );
		$this->assertNull( get_post( $task_id_2 ) );
		$this->assertFalse( Utils::is_queue_process_locked( $queue ) );
		delete_option( '_test_queue_processor_callback' );

	}

	/**
	 * Test that if one task in batch processing fails, it doesn't remove it from the queue
	 */
	public function testPartialFailureBatchProcessing() {

		$queue = 'testPartialFailureBatchProcessing';
		wpqt_register_queue( $queue, [ 'callback' => [ $this, 'queue_processor_callback_failure' ] ] );
		$task_1_id = wpqt_create_task( $queue, 'test data' );
		$task_2_id = wpqt_create_task( $queue, 'some other data' );

		add_action( 'wpqt_bulk_processing_failed', function( $failed_tasks, $tasks_to_delete, $tasks, $queue_name ) {
			global $_test_wpqt_bulk_processing_failed;
			$_test_wpqt_bulk_processing_failed = [
				'failed_tasks' => count( $failed_tasks ),
				'tasks_to_delete' => $tasks_to_delete,
				'tasks' => $tasks,
				'queue_name' => $queue_name,
			];
		}, 10, 4 );

		Utils::lock_queue_process( $queue );
		$processor_obj = new Processor();
		$queue_id = get_term_by( 'name', $queue, $this->taxonomy );
		$result = $processor_obj->run_processor( $queue, $queue_id->term_id );
		global $_test_wpqt_bulk_processing_failed;
		$actual = $_test_wpqt_bulk_processing_failed;
		$expected = [
			'failed_tasks' => 1,
			'tasks_to_delete' => [ $task_1_id ],
			'tasks' => [
				$task_1_id => 'test data',
				$task_2_id => 'some other data',
			],
			'queue_name' => $queue,
		];

		$this->assertTrue( $result );
		$this->assertEquals( $expected, $actual );
		$this->assertNull( get_post( $task_1_id ) );
		$this->assertNotNull( get_post( $task_2_id ) );
		$this->assertFalse( Utils::is_queue_process_locked( $queue ) );

	}

	/**
	 * Test to make sure that a single task that fails to process is not removed from the queue.
	 */
	public function testSingleTaskFailure() {

		$queue = 'testSingleTaskFailure';
		wpqt_register_queue( $queue, [
			'callback' => [ $this, 'queue_processor_callback_failure' ],
			'bulk' => false,
		] );

		$task_id = wpqt_create_task( $queue, 'some data' );

		add_action( 'wpqt_single_task_failed', function( $failed_tasks, $tasks_to_delete, $post, $result, $queue_name ) {
			global $_test_wpqt_single_task_failed;
			$_test_wpqt_single_task_failed = [
				'failed_tasks' => $failed_tasks,
				'tasks_to_delete' => $tasks_to_delete,
				'post_id' => $post->ID,
				'payload' => $post->post_content,
				'result' => $result,
				'queue_name' => $queue_name,
			];
		}, 10, 5 );

		Utils::lock_queue_process( $queue );
		$processor_obj = new Processor();
		$queue_id = get_term_by( 'name', $queue, $this->taxonomy );
		$result = $processor_obj->run_processor( $queue, $queue_id->term_id );
		global $_test_wpqt_single_task_failed;
		$actual = $_test_wpqt_single_task_failed;
		$expected = [
			'failed_tasks' => [ $task_id ],
			'tasks_to_delete' => [],
			'post_id' => $task_id,
			'payload' => 'some data',
			'result' => false,
			'queue_name' => $queue,
		];

		$this->assertTrue( $result );
		$this->assertEquals( $expected, $actual );
		$this->assertNotNull( get_post( $task_id ) );
		$this->assertEquals( [ $queue => 1 ], get_post_meta( $task_id, 'wpqt_retry', true ) );
		$this->assertFalse( Utils::is_queue_process_locked( $queue ) );

	}

	public function testSingleTaskFailureMultipleInQueue() {

		$queue = 'testSingleTaskFailureMultipleInQueue';
		wpqt_register_queue( $queue, [
			'bulk' => false,
			'callback' => function( $data ) use ( $queue ) {
				if ( 'fail' === $data ) {
					return false;
				} else {
					update_option( '_test_' . $queue, $data );
					return true;
				}
			},
		] );

		$success_data = 'pass';
		$task_1 = wpqt_create_task( $queue, 'fail' );
		$task_2 = wpqt_create_task( $queue, $success_data );

		$queue_id = get_term_by( 'name', $queue, $this->taxonomy );

		$processor_obj = new Processor();
		$processor_obj->run_processor( $queue, $queue_id->term_id );

		$this->assertNull( get_post( $task_2 ) );
		$this->assertNotNull( get_post( $task_1 ) );
		$this->assertEquals( $success_data, get_option( '_test_' . $queue ) );

	}

	/**
	 * Test that a queue limited by time interval is able to re-run if more tasks need processing
	 */
	public function testProcessorReRunsTooManyTasks() {

		$queue = 'testProcessorReRuns';
		wpqt_register_queue( 'testProcessorReRuns', [
			'callback' => function( $data ) {
				return array_keys( $data );
			},
			'update_interval' => HOUR_IN_SECONDS,
		] );

		add_filter( 'wpqt_max_tasks_to_process', function( $max ) {
			return 2;
		} );

		wpqt_create_task( $queue, 'task 1' );
		wpqt_create_task( $queue, 'task 2' );
		wpqt_create_task( $queue, 'task 3' );
		$queue_id = get_term_by( 'name', $queue, $this->taxonomy );

		$processor_obj = new Processor();
		$result = $processor_obj->run_processor( $queue, $queue_id->term_id );

		$this->assertTrue( $result );
		$this->assertEmpty( get_term_meta( $queue_id->term_id, 'wpqt_queue_last_run' ) );

		$method = new ReflectionMethod( '\WPQueueTasks\Scheduler', 'should_process' );
		$method->setAccessible( true );

		$scheduler_obj = new \WPQueueTasks\Scheduler();
		$this->assertTrue( $method->invoke( $scheduler_obj, $queue, $queue_id->term_id, 2 ) );

		add_filter( 'wpqt_max_tasks_to_process', function( $max ) {
			return 100;
		}, 100 );

	}

	/**
	 * Test to make sure the scheduler will schedule another process if there are some failures in a
	 * queue that can only run at a timed interval
	 */
	public function testProcessorReRunsFailures() {

		$queue = 'testProcessorReRunsFailures';
		wpqt_register_queue( $queue, [
			'callback' => function( $data ) {
				return [];
			},
			'update_interval' => HOUR_IN_SECONDS,
		] );

		wpqt_create_task( $queue, 'task 1' );
		wpqt_create_task( $queue, 'task 2' );
		wpqt_create_task( $queue, 'task 3' );
		$queue_id = get_term_by( 'name', $queue, $this->taxonomy );

		$processor_obj = new Processor();
		$result = $processor_obj->run_processor( $queue, $queue_id->term_id );

		$this->assertTrue( $result );
		$this->assertEmpty( get_term_meta( $queue_id->term_id, 'wpqt_queue_last_run' ) );

		$method = new ReflectionMethod( '\WPQueueTasks\Scheduler', 'should_process' );
		$method->setAccessible( true );

		$scheduler_obj = new \WPQueueTasks\Scheduler();
		$this->assertTrue( $method->invoke( $scheduler_obj, $queue, $queue_id->term_id, 2 ) );

	}

	/**
	 * Test that a failed task with a retry set to 0 will be removed from the queue upon failure
	 */
	public function testFailedTaskRemovalForNoRetries() {

		$queue = 'testFailedTaskRemovalForNoRetries';
		wpqt_register_queue( $queue, [
			'callback' => '__return_false',
			'bulk' => false,
			'retry' => 0,
		] );

		$task_id = wpqt_create_task( $queue, 'test data' );
		$queue_id = get_term_by( 'name', $queue, $this->taxonomy );

		$processor_obj = new Processor();
		$result = $processor_obj->run_processor( $queue, $queue_id->term_id );

		$this->assertTrue( $result );

		$expected = get_term_by( 'name', $queue . '_failed', $this->taxonomy );
		$queues = get_the_terms( $task_id, $this->taxonomy );
		$this->assertEquals( [ $expected ], $queues );

	}

	public function testRetryCountIncreases() {

		$queue = 'testRetryCountIncreases';
		wpqt_register_queue( $queue, [
			'callback' => '__return_false',
			'bulk' => false,
		] );

		$task_id =  wpqt_create_task( $queue, 'test' );
		update_post_meta( $task_id, 'wpqt_retry', [ $queue => 2 ] );

		$queue_id = get_term_by( 'name', $queue, $this->taxonomy );
		$processor_obj = new Processor();
		$result = $processor_obj->run_processor( $queue, $queue_id->term_id );

		$this->assertTrue( $result );

		$expected = [ $queue => 3 ];
		$actual = get_post_meta( $task_id, 'wpqt_retry', true );

		$this->assertEquals( $expected, $actual );

	}

	/**
	 * Test that a task that exceeds its recount limit is removed from the processing queue and
	 * added to the "failed" queue
	 */
	public function testRetryCountExceeded() {

		$queue = 'testRetryCountExceeded';
		wpqt_register_queue( $queue, [ 'callback' => [ $this, 'queue_processor_callback_failure' ] ] );
		$task_1 = wpqt_create_task( $queue, 'test data' );
		$task_2 = wpqt_create_task( $queue, 'other test data' );

		update_post_meta( $task_2, 'wpqt_retry', [ $queue => 3 ] );

		$queue_id = get_term_by( 'name', $queue, $this->taxonomy );

		$processor_obj = new Processor();
		$result = $processor_obj->run_processor( $queue, $queue_id->term_id );

		$this->assertTrue( $result );
		$this->assertNull( get_post( $task_1 ) );

		$expected = get_term_by( 'name', $queue . '_failed', $this->taxonomy );
		$queues = get_the_terms( $task_2, $this->taxonomy );
		$this->assertEquals( [ $expected ], $queues );

	}

	/**
	 * Test that the retries work when a task is connected to multiple queues
	 */
	public function testRetryUpdateMultipleQueues() {

		$queue_1 = 'testRetryUpdateMultipleQueues';
		$queue_2 = 'testRetryUpdateMultipleQueues2';

		wpqt_register_queue( $queue_1, [
			'callback' => function( $data ) {
				return [];
			},
			'retry' => 2,
		] );

		wpqt_register_queue( $queue_2, [
			'callback' => function( $data ) {
				return [];
			},
		] );

		$task_id = wpqt_create_task( [ $queue_1, $queue_2 ], 'test data' );
		$queue_1_id = get_term_by( 'name', $queue_1, $this->taxonomy );
		$queue_2_id = get_term_by( 'name', $queue_2, $this->taxonomy );

		$processor_obj = new Processor();
		$processor_obj->run_processor( $queue_1, $queue_1_id->term_id );
		$processor_obj->run_processor( $queue_2, $queue_2_id->term_id );
		$this->assertEquals( [ $queue_1 => 1, $queue_2 => 1 ], get_post_meta( $task_id, 'wpqt_retry', true ) );

		// Second Run
		$processor_obj->run_processor( $queue_1, $queue_1_id->term_id );
		$processor_obj->run_processor( $queue_2, $queue_2_id->term_id );
		$this->assertEquals( [ $queue_1 => 2, $queue_2 => 2 ], get_post_meta( $task_id, 'wpqt_retry', true ) );

		// Third Run
		$processor_obj->run_processor( $queue_1, $queue_1_id->term_id );
		$processor_obj->run_processor( $queue_2, $queue_2_id->term_id );
		$this->assertEquals( [ $queue_2 => 3 ], get_post_meta( $task_id, 'wpqt_retry', true ) );

		$task_queues = get_the_terms( $task_id, $this->taxonomy );
		$failed_term_obj_1 = get_term_by( 'name', $queue_1 . '_failed', $this->taxonomy );
		$this->assertEquals( [ $failed_term_obj_1, $queue_2_id ], $task_queues );

		// Fourth run for second queue
		$processor_obj->run_processor( $queue_2, $queue_2_id->term_id );
		$this->assertEquals( [], get_post_meta( $task_id, 'wpqt_retry', true ) );

		$task_queues = get_the_terms( $task_id, $this->taxonomy );
		$failed_term_obj_2 = get_term_by( 'name', $queue_2 . '_failed', $this->taxonomy );
		$this->assertEquals( [ $failed_term_obj_1, $failed_term_obj_2 ], $task_queues );

	}

	/**
	 * Test for when a task attached to multiple queues fails in one queue, but succeeds in another.
	 */
	public function testSuccessInOneQueueFailureInOther() {

		$queue_1 = 'testSuccessInOneQueueFailureInOther';
		$queue_2 = 'testSuccessInOneQueueFailureInOther2';

		wpqt_register_queue( $queue_1, [
			'callback' => function( $data ) {
				return true;
			},
			'bulk' => false,
		] );

		wpqt_register_queue( $queue_2, [
			'callback' => function( $data ) {
				return false;
			},
			'bulk' => false,
		] );

		$task_id = wpqt_create_task( [ $queue_1, $queue_2 ], 'test' );

		$queue_1_id = get_term_by( 'name', $queue_1, $this->taxonomy );
		$queue_2_id = get_term_by( 'name', $queue_2, $this->taxonomy );

		$processor_obj = new Processor();
		$processor_obj->run_processor( $queue_1, $queue_1_id->term_id );
		$processor_obj->run_processor( $queue_2, $queue_2_id->term_id );

		$task_queues = get_the_terms( $task_id, $this->taxonomy );
		$this->assertEquals( $task_queues, [ $queue_2_id ] );
		$this->assertEquals( [ $queue_2 => 1 ], get_post_meta( $task_id, 'wpqt_retry', true ) );

	}

	public function queue_processor_callback( $data ) {

		$existing_data = get_option( '_test_queue_processor_callback', [] );
		$existing_data[] = $data;
		update_option( '_test_queue_processor_callback', $existing_data );

		if ( is_array( $data ) ) {
			return array_keys( $data );
		} else {
			return true;
		}

	}

	public function queue_processor_callback_failure( $data ) {

		if ( is_array( $data ) ) {
			return [ array_keys( $data )[0] ];
		} else {
			return false;
		}

	}

}

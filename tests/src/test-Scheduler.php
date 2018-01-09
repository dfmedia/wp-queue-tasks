<?php

use WPQueueTasks\Scheduler;
use WPQueueTasks\Utils;

class TestScheduler extends WP_UnitTestCase {

	/**
	 * Test that the scheduler gets setup to run on the shutdown hook
	 */
	public function testSchedulerSetup() {
		$scheduler_obj = new Scheduler();
		$scheduler_obj->setup();
		$this->assertEquals( 999, has_action( 'shutdown', [ $scheduler_obj, 'process_queue' ] ) );
	}

	/**
	 * Test that the processor doesn't run if there are no tasks to process
	 */
	public function testProcessorDoesntRun() {

		$queue = 'testProcessorDoesntRun';
		wp_insert_term( $queue, 'task-queue' );
		wpqt_register_queue( 'testProcessorDoesntRun', [ 'callback' => '__return_true', 'processor' => 'cron' ] );
		$scheduler_obj = new Scheduler();
		$scheduler_obj->process_queue();
		$this->assertFalse( wp_next_scheduled( 'wpqt_run_processor' ) );

	}

	/**
	 * Make sure the processor doesn't process an unregistered queue
	 */
	public function testProcessorSkipsNonRegisteredQueue() {

		$queue = 'testProcessorSkipsNonRegisteredQueue';
		wpqt_create_task( $queue, 'test' );
		$scheduler_obj = $this->getMockBuilder( 'WPQueueTasks\Scheduler' )
			->setMethods( [ 'is_queue_process_locked' ] )
			->getMock();

		$scheduler_obj->expects( $this->never() )
			->method( 'is_queue_process_locked' );

		$actual = $scheduler_obj->process_queue();
		$this->assertNull( $actual );

	}

	/**
	 * Test to make sure the processor will skip a queue that is locked
	 */
	public function testProcessorSkipsLockedQueue() {

		$queue = 'testProcessorSkipsLockedQueue';
		wpqt_register_queue( $queue, [ 'callback' => '__return_true', 'processor' => 'cron' ] );
		$result = wpqt_create_task( $queue, 'test' );
		$this->assertTrue( is_int( $result ) );
		Utils::lock_queue_process( $queue );
		$register = new Scheduler();
		$register->process_queue();
		$this->assertFalse( wp_next_scheduled( 'wpqt_run_processor' ) );

	}

	/**
	 * Make sure the processor doesn't run when not enough posts are in the queue.
	 */
	public function testProcessorSkipsQueueWithoutEnoughTasks() {

		$queue = 'testProcessorSkipsQueueWithoutEnoughPosts';
		wpqt_register_queue( $queue, [ 'callback' => '__return_true', 'minimum_count' => 10 ] );

		wpqt_create_task( $queue, 'test' );

		$scheduler_obj = $this->getMockBuilder( 'WPQueueTasks\Scheduler' )
			->setMethods( [ 'lock_queue_process' ] )
			->getMock();

		$scheduler_obj->expects( $this->never() )
			->method( 'lock_queue_process' );

		$actual = $scheduler_obj->process_queue();
		$this->assertNull( $actual );

	}

	/**
	 * Test that the cron event gets scheduled for processing the queue
	 */
	public function testProcessorSchedulesCron() {

		$queue = 'testProcessorSchedulesCron';
		wpqt_register_queue( $queue, [ 'callback' => '__return_true', 'processor' => 'cron' ] );
		wpqt_create_task( $queue, 'test' );

		$scheduler_obj = new Scheduler();
		$scheduler_obj->process_queue();

		$term_obj = get_term_by( 'name', $queue, 'task-queue' );

		$actual = wp_next_scheduled( 'wpqt_run_processor', [ 'queue_name' => $queue, 'term_id' => $term_obj->term_id ] );
		$this->assertNotFalse( $actual );
		$this->assertEquals( 'locked', Utils::is_queue_process_locked( $queue ) );

	}

	/**
	 * Test that a POST request goes out to the async request handler.
	 */
	public function testProcessorPostsToAsyncHandler() {

		$queue = 'testProcessorPostsToAsyncHandler';
		wpqt_register_queue( $queue, [ 'callback' => '__return_true' ] );
		wpqt_create_task( $queue, 'test' );

		add_action( 'http_api_debug', function( $response ) {
			global $test_request_response;
			$test_request_response = $response;
		} );

		$scheduler_obj = new Scheduler();
		$scheduler_obj->process_queue();

		global $test_request_response;

		$this->assertArrayHasKey( 'response', $test_request_response );

	}

	/**
	 * Run a test in a separate process with a clean scope that doesn't have the
	 * WPQT_PROCESSOR_SECRET constant defined, so we can make sure it doesn't post to the async
	 * handler
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testNoSecretDefinedFailure() {

		$queue = 'testNoSecretDefinedFailure';
		wpqt_register_queue( $queue, [ 'callback' => '__return_true' ] );
		wpqt_create_task( $queue, 'test' );

		add_action( 'http_api_debug', function( $response ) {
			global $test_request_response;
			$test_request_response = $response;
		} );

		$scheduler_obj = new Scheduler();
		$scheduler_obj->process_queue();

		global $test_request_response;

		$this->assertEmpty( $test_request_response );

	}

	/**
	 * Tests to make sure that a processor doesn't run too frequently
	 */
	public function testProcessorSkipsQueueNotTimeToRun() {

		$queue = 'testProcessorSkipsQueueNotTimeToRun';
		wpqt_register_queue( $queue, [ 'callback' => '__return_true', 'update_interval' => HOUR_IN_SECONDS ] );
		$post_id = wpqt_create_task( $queue, 'test' );
		$term_obj = get_term_by( 'name', $queue, 'task-queue' );
		update_term_meta( $term_obj->term_id, 'wpqt_queue_last_run', time() - ( 50 * MINUTE_IN_SECONDS ) );

		$scheduler_obj = new Scheduler();
		$scheduler_obj->process_queue();

		$this->assertNotFalse( get_post( $post_id ) );

	}
}

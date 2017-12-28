<?php

use WPQueueTasks\Register;

class TestRegister extends WP_UnitTestCase {

	/**
	 * Test to make sure the post type has been registered for tasks
	 */
	public function testPostTypeRegistered() {
		$register_obj = new Register();
		$register_obj->register_post_type();
		$actual = get_post_type_object( 'wpqt-task' );
		$this->assertNotNull( $actual );
	}

	/**
	 * Test to make sure the taxonomy has been registered for queues
	 */
	public function testTaxonomyRegistered() {
		$register_obj = new Register();
		$register_obj->register_taxonomy();
		$actual = get_taxonomy( 'task-queue' );
		$this->assertNotEquals( false, $actual );
	}

	/**
	 * Tests the queue locking and unlocking system
	 */
	public function testQueueLockProcess() {

		$name = 'testQueueLocks';

		$actual = Register::is_queue_process_locked( $name );
		$this->assertFalse( $actual );

		Register::lock_queue_process( $name );
		$expected = 'locked';
		$actual = Register::is_queue_process_locked( $name );
		$this->assertEquals( $expected, $actual );

		Register::unlock_queue_process( $name );
		$actual = Register::is_queue_process_locked( $name );
		$this->assertFalse( $actual );

	}

	/**
	 * Test that the processor doesn't run if there are no tasks to process
	 */
	public function testProcessorDoesntRun() {

		$queue = 'testProcessorDoesntRun';
		wp_insert_term( $queue, 'task-queue' );
		wpqt_register_queue( 'testProcessorDoesntRun', [ 'callback' => '__return_true', 'processor' => 'cron' ] );
		$register = new Register();
		$register->process_queue();
		$this->assertFalse( wp_next_scheduled( 'wpqt_run_processor' ) );

	}

	/**
	 * Make sure the processor doesn't process an unregistered queue
	 */
	public function testProcessorSkipsNonRegisteredQueue() {

		$queue = 'testProcessorSkipsNonRegisteredQueue';
		wpqt_create_task( $queue, 'test' );
		$register_obj = $this->getMockBuilder( 'WPQueueTasks\Register' )
			->setMethods( [ 'is_queue_process_locked' ] )
			->getMock();

		$register_obj->expects( $this->never() )
			->method( 'is_queue_process_locked' );

		$actual = $register_obj->process_queue();
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
		Register::lock_queue_process( $queue );
		$register = new Register();
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

		$register_obj = $this->getMockBuilder( 'WPQueueTasks\Register' )
			->setMethods( [ 'lock_queue_process' ] )
			->getMock();

		$register_obj->expects( $this->never() )
			->method( 'lock_queue_process' );

		$actual = $register_obj->process_queue();
		$this->assertNull( $actual );

	}

	/**
	 * Test that the cron event gets scheduled for processing the queue
	 */
	public function testProcessorSchedulesCron() {

		$queue = 'testProcessorSchedulesCron';
		wpqt_register_queue( $queue, [ 'callback' => '__return_true', 'processor' => 'cron' ] );
		wpqt_create_task( $queue, 'test' );

		$register_obj = new Register();
		$register_obj->process_queue();

		$term_obj = get_term_by( 'name', $queue, 'task-queue' );

		$actual = wp_next_scheduled( 'wpqt_run_processor', [ 'queue_name' => $queue, 'term_id' => $term_obj->term_id ] );
		$this->assertNotFalse( $actual );
		$this->assertEquals( 'locked', Register::is_queue_process_locked( $queue ) );

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

		$register_obj = new Register();
		$register_obj->process_queue();

		global $test_request_response;

		$this->assertArrayHasKey( 'response', $test_request_response );

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

		$register_obj = new Register();
		$register_obj->process_queue();

		$this->assertNotFalse( get_post( $post_id ) );

	}

}

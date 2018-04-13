<?php

use WPQueueTasks\Utils;

class TestUtils extends WP_UnitTestCase {

	/**
	 * Tests the queue locking and unlocking system
	 */
	public function testQueueLockProcess() {

		$name = 'testQueueLocks';

		$actual = Utils::is_queue_process_locked( $name );
		$this->assertFalse( $actual );

		$lock = uniqid();
		Utils::lock_queue_process( $name, $lock );
		$actual = Utils::is_queue_process_locked( $name );
		$this->assertEquals( $lock, $actual );

		Utils::unlock_queue_process( $name );
		$actual = Utils::is_queue_process_locked( $name );
		$this->assertFalse( $actual );

	}

	/**
	 * Test that we retrieve the value of the WP_QUEUE_TASKS_DEBUG constant
	 */
	public function testDebugModeOn() {

		if ( ! defined( 'WP_QUEUE_TASKS_DEBUG' ) ) {
			define( 'WP_QUEUE_TASKS_DEBUG', false );
		}

		$this->assertFalse( Utils::debug_on() );

	}

	/**
	 * Test to make sure that if a process owns a lock, it returns true
	 */
	public function testProcessOwnsLock() {

		$name = 'testProcessOwnsLock';
		$lock = uniqid();

		Utils::lock_queue_process( $name, $lock );

		$this->assertTrue( Utils::owns_lock( $name, $lock ) );

	}

	/**
	 * Test to make sure that if another process locked the queue, don't try to process it
	 */
	public function testProcessDoesntOwnLock() {

		$name = 'testProcessDoesntOwnLock';
		$process_lock = uniqid();
		$current_process_lock = uniqid();

		Utils::lock_queue_process( $name, $process_lock );

		$this->assertFalse( Utils::owns_lock( $name, $current_process_lock ) );

	}

}

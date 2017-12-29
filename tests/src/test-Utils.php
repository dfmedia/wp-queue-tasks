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

		Utils::lock_queue_process( $name );
		$expected = 'locked';
		$actual = Utils::is_queue_process_locked( $name );
		$this->assertEquals( $expected, $actual );

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

}

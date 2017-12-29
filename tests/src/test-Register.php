<?php

use WPQueueTasks\Register;

class TestRegister extends WP_UnitTestCase {

	/**
	 * Test to make sure that the post type and taxonomy get registered at the appropriate time
	 */
	public function testRegistrationSetup() {
		$register_obj = new Register();
		$register_obj->setup();
		$this->assertEquals( 10, has_action( 'init', [ $register_obj, 'register_taxonomy' ] ) );
		$this->assertEquals( 10, has_action( 'init', [ $register_obj, 'register_post_type' ] ) );
	}

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
	 * Test that a low level user can still trigger the creation of tasks and queues
	 */
	public function testLowLevelUserCanCreateTasks() {

		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );

		$result = wpqt_create_task( 'randomqueue', 'test' );
		$this->assertNotNull( get_post( $result ) );

		$queues = get_the_terms( $result, 'task-queue' );

		$this->assertTrue( is_array( $queues ) );

	}

}

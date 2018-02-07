<?php

class TestTemplateTags extends WP_UnitTestCase {

	/**
	 * Test that a task creation happens properly
	 */
	public function testTaskCreation() {

		$post_id = $this->factory->post->create();

		$args = [
			'post_title' => 'My Post Title',
			'post_parent' => $post_id,
			'post_content' => 'This data should not save',
			'post_type' => 'post',
			'post_status' => 'draft',
		];

		$task_id = wpqt_create_task( 'test', 'test data', $args );

		$task = get_post( $task_id );
		$this->assertEquals( $args['post_title'], $task->post_title );
		$this->assertEquals( $args['post_parent'], $task->post_parent );
		$this->assertEquals( 'test data', $task->post_content );
		$this->assertEquals( 'wpqt-task', $task->post_type );
		$this->assertEquals( 'publish', $task->post_status );
		$this->assertTrue( has_term( 'test', 'task-queue', $task_id ) );

	}

	/**
	 * Test that an exception is thrown if we try to register a queue without a callback
	 */
	public function testExceptionThrownForNoCallback() {

		$this->setExpectedException( 'Exception', 'You must add a callback when registering a queue' );
		$this->expectException( wpqt_register_queue( 'my-queue', [
			'retry' => 5,
			'bulk' => true,
			'minimum_count' => 5,
		] ) );

	}

	/**
	 * Test that an exception is thrown if we try to use an unsupported processor
	 */
	public function testExceptionThrownForUnsupportedProcessor() {

		$this->setExpectedException( 'Exception', 'An unsupported processor was specified. Please select either "async" or "cron" for the processor argument of your queue registration' );
		$this->expectException( wpqt_register_queue( 'my-queue', [
			'callback' => '__return_true',
			'retry' => 5,
			'bulk' => true,
			'minimum_count' => 5,
			'processor' => 'fake',
		] ) );

	}

}

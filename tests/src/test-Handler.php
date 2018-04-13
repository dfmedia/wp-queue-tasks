<?php

use WPQueueTasks\Handler;

class TestHandler extends WP_UnitTestCase {

	public function setUp() {
		parent::setUp();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server;
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		$this->secret = 'thisismysecret';
		if ( ! defined( 'WP_QUEUE_TASKS_PROCESSOR_SECRET' ) ) {
			define( 'WP_QUEUE_TASKS_PROCESSOR_SECRET', $this->secret );
		}
	}

	public function tearDown() {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tearDown();
	}

	/**
	 * Test to make sure that the callback to setup the REST endpoint gets added to the correct hook
	 */
	public function testRestRegistration() {

		$handler_obj = new Handler();
		$handler_obj->setup();

		$this->assertEquals( 10, has_action( 'rest_api_init', [ $handler_obj, 'register_rest_endpoint' ] ) );

	}

	/**
	 * Test that the endpoint gets registered correctly
	 */
	public function testEndpointRegistered() {

		$handler_obj = new Handler();
		$handler_obj->register_rest_endpoint();
		$endpoint = '/' . Handler::API_NAMESPACE . '/' . Handler::ENDPOINT_RUN . '/(?P<queue>[\w|-]+)';

		$this->assertTrue( isset( $this->server->get_routes()[ $endpoint ] ) );
		$this->assertTrue( isset( $this->server->get_routes()[ '/' . Handler::API_NAMESPACE ] ) );

	}

	/**
	 * Test that our route throws a 404 if we hit it with the wrong request method
	 */
	public function testInvalidRequest() {

		$handler_obj = new Handler();
		$handler_obj->register_rest_endpoint();

		$request = new \WP_REST_Request( 'GET', '/' . Handler::API_NAMESPACE . '/' . Handler::ENDPOINT_RUN . '/test' );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 404, $response->data['data']['status'] );
	}

	/**
	 * Test to make sure auth fails if we don't pass it the proper secret
	 */
	public function testAuthFailure() {

		$handler_obj = new Handler();
		$handler_obj->register_rest_endpoint();

		$request = new WP_REST_Request( 'PUT', '/' . Handler::API_NAMESPACE . '/' . Handler::ENDPOINT_RUN . '/test' );
		$request->set_body(
			wp_json_encode( [
				'secret' => 'somefakesecret',
			] )
		);
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 'no-secret', $response->data['code'] );
		$this->assertEquals( 500, $response->status );

	}

	/**
	 * Test that auth succeeds, but we still get an error for not enough info
	 */
	public function testAuthSuccess() {

		$handler_obj = new Handler();
		$handler_obj->register_rest_endpoint();

		$request = new WP_REST_Request( 'PUT', '/' . Handler::API_NAMESPACE . '/' . Handler::ENDPOINT_RUN . '/test' );
		$request->set_body(
			wp_json_encode( [
				'secret' => $this->secret,
			] )
		);
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 'rest-queue-invalid', $response->data['code'] );

	}

	/**
	 * Test that a request goes through successfully if it has everything it needs
	 * @dataProvider dataProviderEndpoints
	 */
	public function testSuccessfullRequest( $endpoint ) {

		$handler_obj = new Handler();
		$handler_obj->register_rest_endpoint();
		$lock = uniqid();

		$request = new WP_REST_Request( 'PUT', '/' . Handler::API_NAMESPACE . '/' . Handler::ENDPOINT_RUN . '/' . $endpoint );
		$request->set_body(
			wp_json_encode( [
				'secret' => $this->secret,
				'term_id' => 123,
				'lock' => $lock,
			] )
		);
		$response = $this->server->dispatch( $request );
		$this->assertEquals( $endpoint . ' queue processed', $response->data );
		$this->assertEquals( 200, $response->status );

	}

	/**
	 * Test to make sure the processor runs correctly in the REST request
	 */
	public function testSuccessfulQueueProcess() {

		$processor_obj = new \WPQueueTasks\Processor();
		$processor_obj->setup();

		$handler_obj = new Handler();
		$handler_obj->register_rest_endpoint();

		$queue = 'testSuccessfulQueueProcess';
		$key = '_test_' . $queue;
		$data = 'some data here';
		$lock = uniqid();

		wpqt_register_queue( $queue, [
			'callback' => function( $data ) use ( $key ) {
				update_option( $key, $data, false );
				return true;
			},
			'bulk' => false,
		] );

		$task_id = wpqt_create_task( $queue, $data );
		$queue_id = get_term_by( 'name', $queue, 'task-queue' );

		$request = new WP_REST_Request( 'PUT', '/' . Handler::API_NAMESPACE . '/' . Handler::ENDPOINT_RUN . '/' . $queue );
		$request->set_body(
			wp_json_encode( [
				'secret' => $this->secret,
				'term_id' => $queue_id->term_id,
				'lock' => $lock,
			] )
		);

		$response = $this->server->dispatch( $request );
		$this->assertEquals( $queue . ' queue processed', $response->data );
		$this->assertEquals( 200, $response->status );
		$this->assertEquals( $data, get_option( $key ) );
		$this->assertNull( get_post( $task_id ) );

	}

	/**
	 * Dataprovider for names of endpoints
	 * @return array
	 */
	public function dataProviderEndpoints() {

		return [
			[
				'test',
			],
			[
				'test-hypen',
			],
			[
				'CrazYlEttERs',
			],
			[
				'areallyreallyreallylongendpointnamebecauseheywhy-not',
			],
		];

	}

}

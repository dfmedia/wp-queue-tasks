# WP Queue Tasks
[![Build Status](https://travis-ci.org/dfmedia/wp-queue-tasks.svg?branch=master)](https://travis-ci.org/dfmedia/wp-queue-tasks)
[![codecov](https://codecov.io/gh/dfmedia/wp-queue-tasks/branch/master/graph/badge.svg)](https://codecov.io/gh/dfmedia/wp-queue-tasks)

This plugin create's a queue to process tasks. The architecture is pretty straight forward. There is a task post type, where a "task" post gets added to. The task post can be attached to a "queue" which is just a term within the "queues" taxonomy. On every admin shutdown hook, we find all of the queues that need processing, and then post an async request to `admin_post` for each of the queues, and then process the queue.

The idea of the queue is for it to be a dumb processor that simply recognizes that there are tasks to process. It's up to the callback of the registered queue to do something with the payload.

## Registering a queue
To create a new queue, you have to register it with the `dfm_register_queue()` function. The function accepts two parameters. The first is the `$queue_name` which is just the string name of the queue. It should be noted that this name should be a slug-friendly name, so it shouln't have any uppercase letters, or spaces. The second parameter that you can pass to this function is the arguments for registering the queue. The arguments that you can pass are outlined below.
- **callback** (callable) - The callback function to do something with the actual payload. The function is passed the payload of the task it's supposed to process. *required*
- **update_interval** (int|bool) - The interval at which the queue gets processed. You can pass something like `HOUR_IN_SECONDS` here, and that well have the queue only be processed once an hour. _Default: false_
- **minimum_count** (int) - The minimum amount of items in the queue before it should be processed.
- **bulk** (bool) - Whether or not the queue can handle sending multiple payloads at once. _Default: False_
- **processor** (string) - The type of processor you want to use. The options currently are "async" or "cron". Both of these options technically run the processor async, and share the same processor code. It just allows you some flexibility. The async option posts a small payload to a handler which runs the processor, and the cron option just schedules a cron event to run the processor.
- **retry** (int) - The amount of times a retry should be attempted for this particular queue. _Default: 3_

## Using the callback
The callback that is registered with the queue is what actually handles the payload, and does something with it. If the queue supports bulk processing, it will be passed an array of payloads with the ID of the task as the key, and the payload as the value. The callback should either return the ID's of the tasks that should be completed, or `false/WP_Error`. If `false` or `WP_Error` is returned, the tasks will remain in the queue to be processed later, otherwise they will be removed from the queue. It is good to note that if your queue doesn't support bulk processing it doesn't need to return the ID of the task if successful, it can just return something like `true`.

## Code Samples
Simple example of processing a single task at a time, and storing the contents of the task in an option.
```php
wpqt_register_queue( 'my-queue', [
    'callback' => 'sample_callback',
    'processor' => 'cron',
    'retry' => 5,
    'bulk' => false,
] );

wpqt_create_task( 'my-queue', 'sample data' );

function sample_callback( $data, $queue ) {
    $result = update_option( 'my_sample_option_' . $queue, $data );
    return $result;
}
```
Example of bulk processing some tasks. In this example, we set the minimum count to 10 so we let the queue build up some tasks for us to process in bulk. When setting bulk to true, your processor callback will receive an array of tasks with the key being the ID of the task, and the value being the data stored in the task. To signal the processor that the callback processed a task correctly, you should return the ID of the successfully completed task in an array. See the example below:
```php
wpqt_register_queue( 'my-queue', [
    'callback' => 'sample_callback',
    'bulk' => true,
    'minimum_count' => 10,
] );

$i = 0;
for ( $i < 10; $i++; ) {
    wpgt_create_task( 'my-queue', 'sample data #' . $i );
}

function sample_callback( $data, $queue ) {
    $successful = [];
    if ( ! empty( $data ) && is_array( $data ) {
        foreach ( $data as $id => $payload ) {
            $result = update_option( 'my_sample_option_' . $id, $payload );
            if ( true === $result ) {
                $successful[] = $id;
            }
        }
    }
    return $successful;
}
```
You can also use the `update_interval` argument to limit the processor to only running at your set interval. Below is an example of this.
```php
wpqt_register_queue( 'my-queue', [
    'callback' => 'sample_callback',
    'update_interval' => HOUR_IN_SECONDS,
    'bulk' => false,
] );

wpqt_create_task( 'my-queue', 'sample data' );

function sample_callback( $data, $queue ) {
    $result = update_option( 'my_sample_data_' . $queue, $data );
    return $result;
}
```

## Retries
To use the retry system, simply add a retry count when registering your queue. Each queue can have it's own retry limit, and will be tracked independently. Once the maximum retries have been hit, the task will remain in the system, but will be removed from the queue that it hit the limit on, and will be added to a new queue called {$queue_name}_failed so it can be further investigated in the future.

## PHP 7.0+
This plugin requires php version 7.0 and up. This is because of the exception handling we are doing for processor callbacks. This is needed so that a callback throwing an error doesn't hold up the entire queue from processing.
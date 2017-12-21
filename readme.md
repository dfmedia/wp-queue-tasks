# DFM Queue Tasks
This plugin create's a queue to process tasks. The architecture is pretty straight forward. There is a task post type, where a "task" post gets added to. The task post can be attached to a "queue" which is just a term within the "queues" taxonomy. On every admin shutdown hook, we find all of the queues that need processing, and then post an async request to `admin_post` for each of the queues, and then process the queue.

The idea of the queue is for it to be a dumb processor that simply recognizes that there are tasks to process. It's up to the callback of the registered queue to do something with the payload.

## Registering a queue
To create a new queue, you have to register it with the `dfm_register_queue()` function. The function accepts two parameters. The first is the `$queue_name` which is just the string name of the queue. It should be noted that this name should be a slug-friendly name, so it shouln't have any uppercase letters, or spaces. The second parameter that you can pass to this function is the arguments for registering the queue. The arguments that you can pass are outlined below.
- **callback** (callable) - The callback function to do something with the actual payload. The function is passed the payload of the task it's supposed to process. *required*
- **update_interval** (int|bool) - The interval at which the queue gets processed. You can pass something like `HOUR_IN_SECONDS` here, and that well have the queue only be processed once an hour. _Default: false_
- **minimum_count** (int) - The minimum amount of items in the queue before it should be processed.
- **bulk_processing_support** (bool) - Whether or not the queue can handle sending multiple payloads at once. _Default: False_

## Using the callback
The callback that is registered with the queue is what actually handles the payload, and does something with it. If the queue supports bulk processing, it will be passed an array of payloads with the ID of the task as the key, and the payload as the value. The callback should either return the ID's of the tasks that should be completed, or `false/WP_Error`. If `false` or `WP_Error` is returned, the tasks will remain in the queue to be processed later, otherwise they will be removed from the queue. It is good to note that if your queue doesn't support bulk processing it doesn't need to return the ID of the task if successful, it can just return something like `true`.
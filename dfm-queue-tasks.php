<?php
/**
 * Plugin Name:     DFM Queue Tasks
 * Plugin URI:      https://github.com/dfmedia/wp-mason/
 * Description:     Create's a task queue that gets processed on every shutdown hook.
 * Author:          Ryan Kanner, Digital First Media
 * Text Domain:     dfm-queue-tasks
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         Dfm_Queue_Tasks
 */

// ensure the wp environment is loaded properly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Only run in admin context
if ( ! is_admin() ) {
	return;
}

require_once( plugin_dir_path( __FILE__ ) . 'includes/class-dfm-queue-tasks.php' );
require_once( plugin_dir_path( __FILE__ ) . 'includes/template-tags.php' );
require_once( plugin_dir_path( __FILE__ ) . 'includes/class-dfm-queue-processor.php' );

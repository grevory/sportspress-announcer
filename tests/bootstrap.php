<?php
/**
 * PHPUnit bootstrap: load Brain\Monkey stubs so plugin classes can be
 * required without a running WordPress installation.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Stub ABSPATH so plugin files don't exit() on direct inclusion.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// Load the classes under test.
require_once dirname( __DIR__ ) . '/admin/class-spa-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-spa-message-formatter.php';
require_once dirname( __DIR__ ) . '/includes/class-spa-event-handler.php';

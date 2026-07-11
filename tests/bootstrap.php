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

// WordPress time constants used by scheduler/idempotency logic.
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// Minimal WP_Error stand-in for code paths that return one.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		/** @var string */
		private $code;
		/** @var string */
		private $message;
		/**
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 */
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		/** @return string */
		public function get_error_code() {
			return $this->code;
		}
		/** @return string */
		public function get_error_message() {
			return $this->message;
		}
	}
}

// Load the classes under test.
require_once dirname( __DIR__ ) . '/includes/licensing/class-spa-license.php';
require_once dirname( __DIR__ ) . '/admin/class-spa-pro-tab.php';
require_once dirname( __DIR__ ) . '/admin/class-spa-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-spa-message-formatter.php';
require_once dirname( __DIR__ ) . '/includes/class-spa-event-handler.php';
require_once dirname( __DIR__ ) . '/includes/digest/class-spa-digest-builder.php';
require_once dirname( __DIR__ ) . '/includes/digest/class-spa-digest-formatter.php';
require_once dirname( __DIR__ ) . '/includes/digest/class-spa-weekly-digest-scheduler.php';

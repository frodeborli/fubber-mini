<?php
/**
 * Mini Framework Bootstrap
 *
 * Early phase application bootstrap. Constructing the Mini class will set
 * the immutable singleton `mini\Mini::$mini`.
 *
 * Note: Default converters and exception handlers are registered in
 * src/Dispatcher/defaults.php which is loaded after all service registrations.
 */
new \mini\Mini();

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

/**
 * Include APCu polyfill if APCu extension is not available or if certain
 */
if (version_compare(phpversion('apcu') ?: '0.0.0', '5.1.0', '<')) {
    require __DIR__ . '/src/apcu-polyfill.php';
}

new \mini\Mini();

<?php
/**
 * Test Session configuration via PHP session functions
 *
 * Tests that Session respects session_name() and session_cache_expire()
 */

require __DIR__ . '/../../ensure-autoloader.php';
require __DIR__ . '/../assert.php';

use mini\Mini;
use mini\Session\Session;
use Psr\SimpleCache\CacheInterface;

// Clear any existing session data in cache
Mini::$mini->get(CacheInterface::class)->clear();

echo "Testing Session Configuration...\n";

// =============================================================================
// Tests
// =============================================================================

// Test: Cookie name matches session_name()
$configuredName = session_name(); // Whatever php.ini says
$session = new Session();
$session->getId();
$cookie = $session->getCookieToSet();
assert_eq($configuredName, $cookie['name'], "Cookie name should match session_name()");
echo "  ✓ Cookie name matches session_name(): $configuredName\n";

// Test: Cookie TTL matches session_cache_expire()
$configuredExpireMinutes = session_cache_expire(); // Whatever php.ini says
$expectedTtlSeconds = $configuredExpireMinutes * 60;
$session2 = new Session();
$session2->getId();
$cookie = $session2->getCookieToSet();
$actualTtl = $cookie['options']['expires'] - time();
assert_true(
    abs($actualTtl - $expectedTtlSeconds) < 2,
    "TTL should match session_cache_expire()"
);
echo "  ✓ Cookie TTL matches session_cache_expire(): {$configuredExpireMinutes} minutes\n";

// Test: session_name() and session_cache_expire() can be read in CLI
// (This verifies these functions work without web SAPI)
assert_true(is_string(session_name()), 'session_name() should return string in CLI');
assert_true(is_int(session_cache_expire()), 'session_cache_expire() should return int in CLI');
echo "  ✓ PHP session functions available in CLI\n";

// Test: Session ID format is valid (64 hex chars)
$session3 = new Session();
$id = $session3->getId();
assert_true(preg_match('/^[a-f0-9]{64}$/', $id) === 1, 'Session ID should be 64 lowercase hex chars');
echo "  ✓ Session ID format is valid\n";

// Test: Different session instances get different IDs
$session4 = new Session();
$session5 = new Session();
assert_true($session4->getId() !== $session5->getId(), 'Different sessions should have different IDs');
echo "  ✓ Different sessions get different IDs\n";

// Test: getCacheTtl() returns seconds (session_cache_expire * 60)
$ref = new ReflectionMethod(Session::class, 'getCacheTtl');
$ref->setAccessible(true);
$session6 = new Session();
$ttl = $ref->invoke($session6);
assert_eq(session_cache_expire() * 60, $ttl, 'getCacheTtl() should return minutes * 60');
echo "  ✓ getCacheTtl() correctly converts minutes to seconds\n";

// Test: getCookieOptions() uses session_get_cookie_params() with secure defaults
$ref = new ReflectionMethod(Session::class, 'getCookieOptions');
$ref->setAccessible(true);
$session7 = new Session();
$options = $ref->invoke($session7);
$params = session_get_cookie_params();

// Path should match or default to /
assert_eq($params['path'] ?: '/', $options['path'], 'Cookie path should match session config');
// HttpOnly should be true (our secure default when php.ini is empty)
assert_true($options['httponly'], 'Cookie httponly should default to true');
// SameSite should be Lax (our secure default when php.ini is empty)
assert_eq('Lax', $options['samesite'], 'Cookie samesite should default to Lax');
echo "  ✓ getCookieOptions() applies secure defaults\n";

// Test: isStrictMode() reads from ini
$ref = new ReflectionMethod(Session::class, 'isStrictMode');
$ref->setAccessible(true);
$session8 = new Session();
$strictMode = $ref->invoke($session8);
assert_eq((bool) ini_get('session.use_strict_mode'), $strictMode, 'isStrictMode() should match ini setting');
echo "  ✓ isStrictMode() reads from session.use_strict_mode\n";

echo "\nAll Configuration tests passed!\n";

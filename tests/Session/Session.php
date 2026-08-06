<?php
/**
 * Test Session implementation
 */

require __DIR__ . '/../../ensure-autoloader.php';
require __DIR__ . '/../assert.php';

use mini\Cache\TmpSqliteCache;
use mini\Session\Session;

// Sessions are tested against a private in-memory store. Resolving the
// application cache would make these tests depend on which driver the host
// machine happens to pick (and, for the /tmp SQLite driver, on a file that may
// be owned by another user) — session semantics must be verified deterministically.
$cache = new TmpSqliteCache(':memory:');

echo "Testing Session...\n";

// =============================================================================
// Tests
// =============================================================================

// Test: New session generates ID and sets cookie
$session = new Session($cache);
$id = $session->getId();
assert_true(strlen($id) === 64, 'Session ID should be 64 chars (hex of 32 bytes)');
assert_true(ctype_xdigit($id), 'Session ID should be hexadecimal');

$cookie = $session->getCookieToSet();
assert_not_null($cookie, 'New session should have cookie to set');
assert_eq(session_name(), $cookie['name'], 'Cookie name should match session_name()');
assert_eq($id, $cookie['value'], 'Cookie value should be session ID');
assert_true($cookie['options']['httponly'], 'Cookie should be HttpOnly');
assert_eq('Lax', $cookie['options']['samesite'], 'Cookie should have SameSite=Lax');
assert_eq('/', $cookie['options']['path'], 'Cookie path should be /');
echo "  ✓ New session generates ID and cookie\n";

// Test: getCookieToSet() clears pending cookie
$cookie2 = $session->getCookieToSet();
assert_null($cookie2, 'Second getCookieToSet() should return null');
echo "  ✓ getCookieToSet() clears pending cookie\n";

// Test: Session set/get
$session->set('user_id', 123);
assert_eq(123, $session->get('user_id'));
assert_eq('default', $session->get('nonexistent', 'default'));
echo "  ✓ Session set/get works\n";

// Test: Session has/remove
assert_true($session->has('user_id'));
assert_false($session->has('nonexistent'));
$session->remove('user_id');
assert_false($session->has('user_id'));
echo "  ✓ Session has/remove works\n";

// Test: Session all/clear
$session->set('a', 1);
$session->set('b', 2);
$all = $session->all();
assert_eq(1, $all['a']);
assert_eq(2, $all['b']);
$session->clear();
assert_eq([], $session->all());
echo "  ✓ Session all/clear works\n";

// Test: ArrayAccess interface
$session['key1'] = 'value1';
assert_eq('value1', $session['key1']);
assert_true(isset($session['key1']));
unset($session['key1']);
assert_false(isset($session['key1']));
echo "  ✓ ArrayAccess interface works\n";

// Test: Countable interface
$session->clear();
$session['a'] = 1;
$session['b'] = 2;
$session['c'] = 3;
assert_eq(3, count($session));
echo "  ✓ Countable interface works\n";

// Test: IteratorAggregate interface
$keys = [];
foreach ($session as $key => $value) {
    $keys[] = $key;
}
assert_eq(['a', 'b', 'c'], $keys);
echo "  ✓ IteratorAggregate interface works\n";

// Test: Session regenerate
$oldId = $session->getId();
$session->regenerate();
$newId = $session->getId();
assert_true($oldId !== $newId, 'regenerate() should create new ID');
$cookie = $session->getCookieToSet();
assert_not_null($cookie, 'regenerate() should set new cookie');
assert_eq($newId, $cookie['value'], 'New cookie should have new ID');
echo "  ✓ Session regenerate works\n";

// Test: Session data preserved after regenerate
assert_eq(1, $session['a'], 'Data should be preserved after regenerate');
echo "  ✓ Session data preserved after regenerate\n";

// Test: Session destroy
$session->destroy();
$cookie = $session->getCookieToSet();
assert_not_null($cookie, 'destroy() should set expiration cookie');
assert_eq('', $cookie['value'], 'Expiration cookie should have empty value');
assert_eq(1, $cookie['options']['expires'], 'Expiration cookie should expire in past');
echo "  ✓ Session destroy sets expiration cookie\n";

// Test: isStarted
$freshSession = new Session($cache);
assert_false($freshSession->isStarted(), 'Fresh session should not be started');
$freshSession->get('anything');
assert_true($freshSession->isStarted(), 'Session should be started after access');
echo "  ✓ isStarted works\n";

// Test: Session TTL uses session_cache_expire()
$expectedTtl = session_cache_expire() * 60;
$session2 = new Session($cache);
$session2->getId(); // Trigger cookie creation
$cookie = $session2->getCookieToSet();
$actualExpires = $cookie['options']['expires'];
$now = time();
$actualTtl = $actualExpires - $now;
// Allow 2 second tolerance for test execution time
assert_true(abs($actualTtl - $expectedTtl) < 2, "Cookie TTL should match session_cache_expire()");
echo "  ✓ Session TTL uses session_cache_expire()\n";

// Test: Session ID validation (valid formats)
$ref = new ReflectionMethod(Session::class, 'isValidSessionId');
$ref->setAccessible(true);
$testSession = new Session($cache);

assert_true($ref->invoke($testSession, 'abc123def456abc123def456abc123def456abc123def456abc123def456abcd1234'), 'Valid 64-char hex should pass');
assert_true($ref->invoke($testSession, 'ABCDEF1234567890abcdef'), 'Mixed case alphanumeric should pass');
assert_true($ref->invoke($testSession, 'session-id-with-dashes'), 'Dashes should be allowed');
assert_true($ref->invoke($testSession, 'session,id,with,commas'), 'Commas should be allowed');
assert_false($ref->invoke($testSession, 'short'), 'Too short should fail');
assert_false($ref->invoke($testSession, 'invalid!chars'), 'Special chars should fail');
assert_false($ref->invoke($testSession, ''), 'Empty should fail');
echo "  ✓ Session ID validation works\n";

echo "\nAll Session tests passed!\n";

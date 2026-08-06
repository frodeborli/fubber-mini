<?php
/**
 * Test Session strict mode (session.use_strict_mode)
 *
 * Strict mode rejects unknown session IDs and creates new sessions.
 * This prevents session fixation attacks.
 */

require __DIR__ . '/../../ensure-autoloader.php';
require __DIR__ . '/../assert.php';

use mini\Cache\TmpSqliteCache;
use mini\Session\Session;

// Private in-memory store: strict mode is about what is (not) in the session
// cache, so the test must own that cache rather than share the application one.
$cache = new TmpSqliteCache(':memory:');

echo "Testing Session Strict Mode...\n";

// =============================================================================
// Helper to create session with mocked request cookies
// =============================================================================

class StrictModeTestSession extends Session
{
    private array $mockCookies = [];
    private bool $strict = false;

    public function setMockCookies(array $cookies): void
    {
        $this->mockCookies = $cookies;
    }

    public function setStrictMode(bool $strict): void
    {
        $this->strict = $strict;
    }

    protected function getCookies(): array
    {
        return $this->mockCookies;
    }

    // ini_set() refuses session directives once output has started, so the
    // policy — not the branch logic under test — is stubbed here.
    protected function isStrictMode(): bool
    {
        return $this->strict;
    }
}

// Test: isStrictMode() returns current ini setting
$session = new Session($cache);
$ref = new ReflectionMethod($session, 'isStrictMode');
$ref->setAccessible(true);

$currentSetting = (bool) ini_get('session.use_strict_mode');
assert_eq($currentSetting, $ref->invoke($session), 'isStrictMode() should match ini setting');
echo "  ✓ isStrictMode() reads session.use_strict_mode (currently: " . ($currentSetting ? 'ON' : 'OFF') . ")\n";

// Test: Session ID validation accepts valid formats
$ref = new ReflectionMethod($session, 'isValidSessionId');
$ref->setAccessible(true);

// Valid session IDs (must be 22-256 chars, alphanumeric plus dash and comma)
assert_true($ref->invoke($session, str_repeat('a', 64)), '64 hex chars should be valid');
assert_true($ref->invoke($session, str_repeat('A', 32)), '32 uppercase hex chars should be valid');
assert_true($ref->invoke($session, 'abc-def-123,456-abc-def'), '22+ chars with dashes and commas should be valid');

// Invalid session IDs
assert_false($ref->invoke($session, 'short'), 'Too short should be invalid');
assert_false($ref->invoke($session, str_repeat('a', 300)), 'Too long should be invalid');
assert_false($ref->invoke($session, 'has spaces'), 'Spaces should be invalid');
assert_false($ref->invoke($session, 'has!special'), 'Special chars should be invalid');
assert_false($ref->invoke($session, ''), 'Empty should be invalid');

echo "  ✓ Session ID validation works correctly\n";

// Test: New sessions are always saved to cache (enabling strict mode to work)
$session2 = new Session($cache);
$id = $session2->getId();
$session2->set('test', 'value'); // This saves to cache

$cacheKey = 'session.' . $id;
assert_true($cache->has($cacheKey), 'Session should be saved to cache after write');
echo "  ✓ Sessions are saved to cache (required for strict mode)\n";

// Test: Cache has() works for strict mode validation
assert_true($cache->has($cacheKey), 'Cache has() should find existing session');
assert_false($cache->has('session.nonexistent123456789012345678901234567890123456789012345678901234'), 'Cache has() should not find nonexistent session');
echo "  ✓ Cache has() can validate session existence\n";

// Test: cache keys are PSR-16 safe (PSR-16 reserves {}()/\@: in keys)
assert_false((bool) preg_match('/[{}()\/\\\\@:]/', $cacheKey), 'Session cache key must not use PSR-16 reserved characters');
echo "  ✓ Session cache keys are PSR-16 safe\n";

// =============================================================================
// Strict mode behaviour (cookies mocked via getCookies())
// =============================================================================

// Known session ID (written above) is accepted
$known = new StrictModeTestSession($cache);
$known->setStrictMode(true);
$known->setMockCookies([session_name() => $id]);
assert_eq($id, $known->getId(), 'Strict mode should accept a session ID that exists in cache');
assert_eq('value', $known->get('test'), 'Accepted session should expose its stored data');
assert_null($known->getCookieToSet(), 'Accepted session should not issue a new cookie');
echo "  ✓ Strict mode accepts session IDs present in cache\n";

// Unknown session ID is rejected and replaced (session fixation protection)
$unknownId = str_repeat('f', 64);
$fixated = new StrictModeTestSession($cache);
$fixated->setStrictMode(true);
$fixated->setMockCookies([session_name() => $unknownId]);
$newId = $fixated->getId();
assert_true($newId !== $unknownId, 'Strict mode should reject a session ID not present in cache');
$cookie = $fixated->getCookieToSet();
assert_not_null($cookie, 'Rejected session should issue a fresh cookie');
assert_eq($newId, $cookie['value'], 'Fresh cookie should carry the newly generated ID');
echo "  ✓ Strict mode rejects unknown session IDs (fixation protection)\n";

// With strict mode off, an unknown but well-formed ID is adopted as-is
$lax = new StrictModeTestSession($cache);
$lax->setStrictMode(false);
$lax->setMockCookies([session_name() => $unknownId]);
assert_eq($unknownId, $lax->getId(), 'Without strict mode, a well-formed cookie ID is adopted');
echo "  ✓ Without strict mode, well-formed cookie IDs are adopted\n";

// Malformed IDs are rejected regardless of strict mode
$malformed = new StrictModeTestSession($cache);
$malformed->setStrictMode(false);
$malformed->setMockCookies([session_name() => 'not a valid id!']);
assert_true($malformed->getId() !== 'not a valid id!', 'Malformed cookie ID must never be adopted');
echo "  ✓ Malformed cookie IDs are never adopted\n";

echo "\nAll Strict Mode tests passed!\n";

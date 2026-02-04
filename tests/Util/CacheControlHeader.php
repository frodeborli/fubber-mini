<?php
/**
 * Test CacheControlHeader utility class
 */

require __DIR__ . '/../../ensure-autoloader.php';
require __DIR__ . '/../assert.php';

use mini\Util\CacheControlHeader;

echo "Testing CacheControlHeader...\n";

// =============================================================================
// Parsing tests
// =============================================================================

// Test: Parse empty/null
$cc = new CacheControlHeader(null);
assert_true($cc->isEmpty(), 'Null header should be empty');
assert_eq('', (string) $cc, 'Empty header should render as empty string');
echo "  ✓ Handles null/empty header\n";

// Test: Parse simple flags
$cc = new CacheControlHeader('no-cache, no-store, must-revalidate');
assert_true($cc->has('no-cache'));
assert_true($cc->has('no-store'));
assert_true($cc->has('must-revalidate'));
assert_false($cc->has('public'));
assert_eq(true, $cc->get('no-cache'), 'Flag directives should return true');
echo "  ✓ Parses flag directives\n";

// Test: Parse directives with values
$cc = new CacheControlHeader('public, max-age=3600, s-maxage=60');
assert_true($cc->has('public'));
assert_true($cc->has('max-age'));
assert_eq('3600', $cc->get('max-age'));
assert_eq('60', $cc->get('s-maxage'));
echo "  ✓ Parses value directives\n";

// Test: Case insensitivity
$cc = new CacheControlHeader('Public, Max-Age=100');
assert_true($cc->has('public'));
assert_true($cc->has('PUBLIC'));
assert_eq('100', $cc->get('MAX-AGE'));
echo "  ✓ Case insensitive\n";

// =============================================================================
// Modification tests
// =============================================================================

// Test: with() adds directive
$cc = new CacheControlHeader('public');
$cc2 = $cc->with('max-age', '3600');
assert_false($cc->has('max-age'), 'Original should be unchanged');
assert_true($cc2->has('max-age'), 'New instance should have directive');
assert_eq('3600', $cc2->get('max-age'));
echo "  ✓ with() is immutable and adds directives\n";

// Test: with() for flags
$cc = new CacheControlHeader();
$cc = $cc->with('private');
assert_true($cc->has('private'));
assert_eq(true, $cc->get('private'));
echo "  ✓ with() works for flag directives\n";

// Test: without() removes directive
$cc = new CacheControlHeader('public, max-age=3600');
$cc2 = $cc->without('max-age');
assert_true($cc->has('max-age'), 'Original should be unchanged');
assert_false($cc2->has('max-age'), 'New instance should not have directive');
assert_true($cc2->has('public'), 'Other directives should remain');
echo "  ✓ without() is immutable and removes directives\n";

// Test: without() on non-existent returns same instance
$cc = new CacheControlHeader('public');
$cc2 = $cc->without('nonexistent');
assert_true($cc === $cc2, 'Should return same instance if nothing to remove');
echo "  ✓ without() returns same instance if directive not present\n";

// =============================================================================
// Visibility restriction tests
// =============================================================================

// Test: Restrict public to private
$cc = new CacheControlHeader('public, max-age=3600');
$cc2 = $cc->withRestrictedVisibility('private');
assert_false($cc2->has('public'), 'public should be removed');
assert_true($cc2->has('private'), 'private should be added');
assert_true($cc2->has('max-age'), 'Other directives should remain');
echo "  ✓ withRestrictedVisibility() changes public to private\n";

// Test: Restrict private to no-cache (more restrictive)
$cc = new CacheControlHeader('private');
$cc2 = $cc->withRestrictedVisibility('no-cache');
assert_false($cc2->has('private'));
assert_true($cc2->has('no-cache'));
echo "  ✓ withRestrictedVisibility() can make more restrictive\n";

// Test: Don't loosen restriction
$cc = new CacheControlHeader('no-store');
$cc2 = $cc->withRestrictedVisibility('private');
assert_true($cc2->has('no-store'), 'no-store should remain (more restrictive)');
assert_false($cc2->has('private'), 'Should not add less restrictive');
echo "  ✓ withRestrictedVisibility() doesn't loosen restrictions\n";

// Test: withPrivate() shorthand
$cc = new CacheControlHeader('public');
$cc2 = $cc->withPrivate();
assert_true($cc2->has('private'));
assert_false($cc2->has('public'));
echo "  ✓ withPrivate() works\n";

// Test: withNoStore()
$cc = new CacheControlHeader('public, max-age=3600');
$cc2 = $cc->withNoStore();
assert_true($cc2->has('no-store'));
assert_true($cc2->has('no-cache'));
assert_true($cc2->has('must-revalidate'));
assert_false($cc2->has('public'));
echo "  ✓ withNoStore() sets full no-cache policy\n";

// =============================================================================
// TTL restriction tests
// =============================================================================

// Test: Cap max-age when higher
$cc = new CacheControlHeader('max-age=3600');
$cc2 = $cc->withMaxTtl(60);
assert_eq('60', $cc2->get('max-age'));
echo "  ✓ withMaxTtl() caps higher values\n";

// Test: Don't increase max-age
$cc = new CacheControlHeader('max-age=30');
$cc2 = $cc->withMaxTtl(3600);
assert_eq('30', $cc2->get('max-age'), 'Should keep lower value');
echo "  ✓ withMaxTtl() keeps lower values\n";

// Test: Set max-age when not present
$cc = new CacheControlHeader('public');
$cc2 = $cc->withMaxTtl(60);
assert_eq('60', $cc2->get('max-age'));
echo "  ✓ withMaxTtl() sets value when not present\n";

// Test: withMaxSharedTtl
$cc = new CacheControlHeader('s-maxage=3600');
$cc2 = $cc->withMaxSharedTtl(60);
assert_eq('60', $cc2->get('s-maxage'));
echo "  ✓ withMaxSharedTtl() works\n";

// =============================================================================
// Rendering tests
// =============================================================================

// Test: Render mixed directives
$cc = new CacheControlHeader();
$cc = $cc->with('private')->with('max-age', '3600')->with('must-revalidate');
$str = (string) $cc;
assert_contains('private', $str);
assert_contains('max-age=3600', $str);
assert_contains('must-revalidate', $str);
echo "  ✓ Renders correctly\n";

// Test: Invalid visibility throws
$thrown = false;
try {
    $cc = new CacheControlHeader();
    $cc->withRestrictedVisibility('invalid');
} catch (InvalidArgumentException $e) {
    $thrown = true;
}
assert_true($thrown, 'Should throw on invalid visibility');
echo "  ✓ Throws on invalid visibility\n";

echo "\nAll CacheControlHeader tests passed!\n";

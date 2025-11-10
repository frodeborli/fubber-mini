# Validator JSON Schema Export - Refactor Status

## Goal
Enable `toJsonSchema()` export by tracking validation metadata alongside closures.

## Architecture Decision
Store rules as: `$rules['keyword'] = ['closure' => fn, 'value' => X, 'message' => Y]`

This prevents duplicate rules and provides clean JSON Schema export.

## Completed ✅

### Infrastructure
- ✅ Changed `$rules` from array of closures to keyed array with metadata
- ✅ Added `addRule(keyword, closure, value, message)` helper
- ✅ Updated `isInvalid()` to use `$rule['closure']`
- ✅ Implemented `toJsonSchema()` export method

### Converted Methods
- ✅ Type validators: `isString`, `isInt`, `isNumber`, `isBool`, `isObject`, `isArray`, `isNull`
- ✅ Numeric constraints: `minimum`, `maximum`, `exclusiveMinimum`, `exclusiveMaximum`

## Remaining Work ⏳

### Methods to Convert (32 remaining)

**Pattern:**
```php
// Before:
$this->rules[] = function($v) use ($param, $message) {
    // validation logic
};

// After:
$this->addRule(
    'keyword',  // JSON Schema keyword
    function($v) use ($param, $message) {
        // same validation logic
    },
    $param,  // value for export
    $message
);
```

**List:**
1. `multipleOf` - keyword: `multipleOf`, value: `$divisor`
2. `minLength` - keyword: `minLength`, value: `$min`
3. `maxLength` - keyword: `maxLength`, value: `$max`
4. `pattern` - keyword: `pattern`, value: `$pattern`
5. `const` - keyword: `const`, value: `$value`
6. `enum` - keyword: `enum`, value: `$allowed`
7. `minItems` - keyword: `minItems`, value: `$min`
8. `maxItems` - keyword: `maxItems`, value: `$max`
9. `minProperties` - keyword: `minProperties`, value: `$min`
10. `maxProperties` - keyword: `maxProperties`, value: `$max`
11. `uniqueItems` - keyword: `uniqueItems`, value: `true`
12. `dependentRequired` - keyword: `dependentRequired:$property`, value: `$requiredProperties`
13. `email` - keyword: `format`, value: `'email'`
14. `url` - keyword: `format`, value: `'uri'`
15. `dateTime` - keyword: `format`, value: `'date-time'`
16. `date` - keyword: `format`, value: `'date'`
17. `time` - keyword: `format`, value: `'time'`
18. `ipv4` - keyword: `format`, value: `'ipv4'`
19. `ipv6` - keyword: `format`, value: `'ipv6'`
20. `uuid` - keyword: `format`, value: `'uuid'`
21. `slug` - keyword: `format`, value: `'slug'`
22. `isInstanceOf` - NOT exportable (PHP-specific)
23. `items` - Complex (child validators)
24. `additionalItems` - Complex (child validators)
25. `minContains` - Complex (child validators)
26. `maxContains` - Complex (child validators)
27. `anyOf` - Complex (child validators)
28. `allOf` - Complex (child validators)
29. `oneOf` - Complex (child validators)
30. `not` - Complex (child validators)
31. `callback` - NOT exportable (custom PHP closure)
32. `filter` - NOT exportable (PHP-specific)

### Complex Validators
For validators that accept `Validator` instances (anyOf, allOf, items, etc.):
- Store child validators in `value` field
- In `toJsonSchema()`, recursively call `$childValidator->toJsonSchema()`

### Non-exportable Validators
- `isInstanceOf` - PHP class check, no JSON Schema equivalent
- `callback` - Custom PHP closure
- `filter` - PHP's filter_var, no JSON Schema equivalent

These can remain as simple closures without export support.

## Testing
After conversion, test:
```php
$validator = (new Validator)
    ->isString()
    ->minLength(3)
    ->maxLength(50)
    ->pattern('/^[a-z]+$/');

$schema = $validator->toJsonSchema();
// Should output:
// [
//     'type' => 'string',
//     'minLength' => 3,
//     'maxLength' => 50,
//     'pattern' => '/^[a-z]+$/'
// ]
```

## Completion Estimate
- Simple validators: ~15 minutes per batch of 5
- Complex validators: ~30 minutes for all
- Testing: ~15 minutes
- **Total: ~2 hours**

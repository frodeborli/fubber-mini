<?php
/**
 * Test Validator class - validation rule builder
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;
use mini\Validator\Validator;

$test = new class extends Test {

    // ========================================
    // Required validation
    // ========================================

    public function testRequiredRejectsNull(): void
    {
        $v = (new Validator())->required();
        $this->assertNotNull($v->isInvalid(null));
    }

    public function testRequiredRejectsEmptyString(): void
    {
        $v = (new Validator())->required();
        $this->assertNotNull($v->isInvalid(''));
    }

    public function testRequiredRejectsEmptyArray(): void
    {
        $v = (new Validator())->required();
        $this->assertNotNull($v->isInvalid([]));
    }

    public function testRequiredAcceptsValue(): void
    {
        $v = (new Validator())->required();
        $this->assertNull($v->isInvalid('hello'));
    }

    public function testRequiredAcceptsZero(): void
    {
        $v = (new Validator())->required();
        $this->assertNull($v->isInvalid(0));
    }

    public function testRequiredCustomMessage(): void
    {
        $v = (new Validator())->required('Please fill this field');
        $error = $v->isInvalid(null);
        $this->assertSame('Please fill this field', (string) $error);
    }

    public function testOptionalSkipsValidationWhenEmpty(): void
    {
        $v = (new Validator())->type('string')->minLength(5);
        // Empty value should pass because not required
        $this->assertNull($v->isInvalid(null));
        $this->assertNull($v->isInvalid(''));
    }

    // ========================================
    // Type validation
    // ========================================

    public function testTypeString(): void
    {
        $v = (new Validator())->type('string');
        $this->assertNull($v->isInvalid('hello'));
        $this->assertNotNull($v->isInvalid(123));
        $this->assertNotNull($v->isInvalid(true));
    }

    public function testTypeInteger(): void
    {
        $v = (new Validator())->type('integer');
        $this->assertNull($v->isInvalid(42));
        $this->assertNotNull($v->isInvalid(3.14));
        $this->assertNotNull($v->isInvalid('42'));
    }

    public function testTypeNumber(): void
    {
        $v = (new Validator())->type('number');
        $this->assertNull($v->isInvalid(42));
        $this->assertNull($v->isInvalid(3.14));
        $this->assertNotNull($v->isInvalid('42'));
    }

    public function testTypeBoolean(): void
    {
        $v = (new Validator())->type('boolean');
        $this->assertNull($v->isInvalid(true));
        $this->assertNull($v->isInvalid(false));
        $this->assertNotNull($v->isInvalid(1));
        $this->assertNotNull($v->isInvalid('true'));
    }

    public function testTypeArray(): void
    {
        $v = (new Validator())->type('array');
        $this->assertNull($v->isInvalid([1, 2, 3]));
        $this->assertNotNull($v->isInvalid(['a' => 1])); // associative = object
    }

    public function testTypeObject(): void
    {
        $v = (new Validator())->type('object');
        $this->assertNull($v->isInvalid(['a' => 1]));
        $this->assertNull($v->isInvalid(new \stdClass()));
        $this->assertNotNull($v->isInvalid([1, 2, 3])); // list = array
    }

    public function testTypeNull(): void
    {
        // Note: type('null') without required() passes null because non-required
        // empty values skip validation. With required(), null is rejected as empty.
        // This tests that type('null') rejects non-null values.
        $v = (new Validator())->type('null');
        $this->assertNotNull($v->isInvalid('not null'));
        $this->assertNotNull($v->isInvalid(0));
    }

    public function testMultipleTypes(): void
    {
        $v = (new Validator())->type(['string', 'null']);
        $this->assertNull($v->isInvalid('hello'));
        $this->assertNull($v->isInvalid(null));
        $this->assertNotNull($v->isInvalid(123));
    }

    // ========================================
    // String constraints
    // ========================================

    public function testMinLength(): void
    {
        $v = (new Validator())->minLength(3);
        $this->assertNull($v->isInvalid('abc'));
        $this->assertNull($v->isInvalid('abcd'));
        $this->assertNotNull($v->isInvalid('ab'));
    }

    public function testMaxLength(): void
    {
        $v = (new Validator())->maxLength(5);
        $this->assertNull($v->isInvalid('hello'));
        $this->assertNull($v->isInvalid('hi'));
        $this->assertNotNull($v->isInvalid('hello!'));
    }

    public function testPattern(): void
    {
        $v = (new Validator())->pattern('/^[a-z]+$/');
        $this->assertNull($v->isInvalid('hello'));
        $this->assertNotNull($v->isInvalid('Hello'));
        $this->assertNotNull($v->isInvalid('hello123'));
    }

    public function testStringConstraintsOnlyApplyToStrings(): void
    {
        $v = (new Validator())->minLength(10);
        // Should pass because minLength only applies to strings
        $this->assertNull($v->isInvalid(42));
    }

    // ========================================
    // Numeric constraints
    // ========================================

    public function testMinimum(): void
    {
        $v = (new Validator())->minimum(10);
        $this->assertNull($v->isInvalid(10));
        $this->assertNull($v->isInvalid(15));
        $this->assertNotNull($v->isInvalid(9));
    }

    public function testMaximum(): void
    {
        $v = (new Validator())->maximum(100);
        $this->assertNull($v->isInvalid(100));
        $this->assertNull($v->isInvalid(50));
        $this->assertNotNull($v->isInvalid(101));
    }

    public function testExclusiveMinimum(): void
    {
        $v = (new Validator())->exclusiveMinimum(10);
        $this->assertNull($v->isInvalid(11));
        $this->assertNotNull($v->isInvalid(10));
    }

    public function testExclusiveMaximum(): void
    {
        $v = (new Validator())->exclusiveMaximum(100);
        $this->assertNull($v->isInvalid(99));
        $this->assertNotNull($v->isInvalid(100));
    }

    public function testMultipleOf(): void
    {
        $v = (new Validator())->multipleOf(5);
        $this->assertNull($v->isInvalid(10));
        $this->assertNull($v->isInvalid(15));
        $this->assertNotNull($v->isInvalid(12));
    }

    public function testNumericConstraintsOnlyApplyToNumbers(): void
    {
        $v = (new Validator())->minimum(100);
        // Should pass because minimum only applies to numbers
        $this->assertNull($v->isInvalid('5'));
    }

    // ========================================
    // Array constraints
    // ========================================

    public function testMinItems(): void
    {
        $v = (new Validator())->minItems(2);
        $this->assertNull($v->isInvalid([1, 2]));
        $this->assertNull($v->isInvalid([1, 2, 3]));
        $this->assertNotNull($v->isInvalid([1]));
    }

    public function testMaxItems(): void
    {
        $v = (new Validator())->maxItems(3);
        $this->assertNull($v->isInvalid([1, 2, 3]));
        $this->assertNull($v->isInvalid([1]));
        $this->assertNotNull($v->isInvalid([1, 2, 3, 4]));
    }

    public function testUniqueItems(): void
    {
        $v = (new Validator())->uniqueItems();
        $this->assertNull($v->isInvalid([1, 2, 3]));
        $this->assertNotNull($v->isInvalid([1, 2, 2]));
    }

    public function testItemsWithSingleValidator(): void
    {
        $v = (new Validator())->items((new Validator())->type('integer'));
        $this->assertNull($v->isInvalid([1, 2, 3]));
        $this->assertNotNull($v->isInvalid([1, 'two', 3]));
    }

    public function testItemsWithTupleValidation(): void
    {
        $v = (new Validator())->items([
            (new Validator())->type('string'),
            (new Validator())->type('integer'),
        ]);
        $this->assertNull($v->isInvalid(['hello', 42]));
        $this->assertNotNull($v->isInvalid([42, 'hello']));
    }

    // ========================================
    // Object constraints
    // ========================================

    public function testMinProperties(): void
    {
        $v = (new Validator())->minProperties(2);
        $this->assertNull($v->isInvalid(['a' => 1, 'b' => 2]));
        $this->assertNotNull($v->isInvalid(['a' => 1]));
    }

    public function testMaxProperties(): void
    {
        $v = (new Validator())->maxProperties(2);
        $this->assertNull($v->isInvalid(['a' => 1, 'b' => 2]));
        $this->assertNotNull($v->isInvalid(['a' => 1, 'b' => 2, 'c' => 3]));
    }

    // ========================================
    // Enum and const
    // ========================================

    public function testEnum(): void
    {
        $v = (new Validator())->enum(['red', 'green', 'blue']);
        $this->assertNull($v->isInvalid('red'));
        $this->assertNull($v->isInvalid('blue'));
        $this->assertNotNull($v->isInvalid('yellow'));
    }

    public function testConst(): void
    {
        $v = (new Validator())->const('fixed');
        $this->assertNull($v->isInvalid('fixed'));
        $this->assertNotNull($v->isInvalid('other'));
    }

    // ========================================
    // Format validation
    // ========================================

    public function testFormatEmail(): void
    {
        $v = (new Validator())->format('email');
        $this->assertNull($v->isInvalid('user@example.com'));
        $this->assertNotNull($v->isInvalid('invalid-email'));
    }

    public function testFormatUri(): void
    {
        $v = (new Validator())->format('uri');
        $this->assertNull($v->isInvalid('https://example.com'));
        $this->assertNotNull($v->isInvalid('not a url'));
    }

    public function testFormatDate(): void
    {
        $v = (new Validator())->format('date');
        $this->assertNull($v->isInvalid('2024-01-15'));
        $this->assertNull($v->isInvalid('2024-12-31'));
        $this->assertNotNull($v->isInvalid('01-15-2024')); // wrong format
    }

    public function testFormatUuid(): void
    {
        $v = (new Validator())->format('uuid');
        $this->assertNull($v->isInvalid('550e8400-e29b-41d4-a716-446655440000'));
        $this->assertNotNull($v->isInvalid('not-a-uuid'));
    }

    // ========================================
    // Property validation
    // ========================================

    public function testForProperty(): void
    {
        $v = (new Validator())
            ->forProperty('name', (new Validator())->type('string')->required())
            ->forProperty('age', (new Validator())->type('integer'));

        $this->assertNull($v->isInvalid(['name' => 'John', 'age' => 30]));

        $errors = $v->isInvalid(['name' => '', 'age' => 30]);
        $this->assertArrayHasKey('name', $errors);
    }

    public function testProperties(): void
    {
        $v = (new Validator())->properties([
            'x' => (new Validator())->type('integer'),
            'y' => (new Validator())->type('integer'),
        ]);

        $this->assertNull($v->isInvalid(['x' => 1, 'y' => 2]));
        $errors = $v->isInvalid(['x' => 'a', 'y' => 2]);
        $this->assertArrayHasKey('x', $errors);
    }

    public function testAdditionalPropertiesFalse(): void
    {
        $v = (new Validator())
            ->forProperty('name', (new Validator())->type('string'))
            ->additionalProperties(false);

        $this->assertNull($v->isInvalid(['name' => 'John']));
        $errors = $v->isInvalid(['name' => 'John', 'extra' => 'field']);
        $this->assertArrayHasKey('extra', $errors);
    }

    public function testMagicGetForPropertyValidator(): void
    {
        $v = (new Validator())
            ->forProperty('email', (new Validator())->format('email'));

        $this->assertInstanceOf(Validator::class, $v->email);
        $this->assertNull($v->nonexistent);
    }

    // ========================================
    // Combinators
    // ========================================

    public function testAnyOf(): void
    {
        $v = (new Validator())->anyOf([
            (new Validator())->type('string'),
            (new Validator())->type('integer'),
        ]);

        $this->assertNull($v->isInvalid('hello'));
        $this->assertNull($v->isInvalid(42));
        $this->assertNotNull($v->isInvalid(true));
    }

    public function testAllOf(): void
    {
        $v = (new Validator())->allOf([
            (new Validator())->type('string'),
            (new Validator())->minLength(3),
        ]);

        $this->assertNull($v->isInvalid('hello'));
        $this->assertNotNull($v->isInvalid('hi'));
    }

    public function testOneOf(): void
    {
        $v = (new Validator())->oneOf([
            (new Validator())->type('string')->minLength(5),
            (new Validator())->type('string')->maxLength(3),
        ]);

        $this->assertNull($v->isInvalid('hello')); // matches first only
        $this->assertNull($v->isInvalid('hi'));    // matches second only
        $this->assertNotNull($v->isInvalid('four')); // matches neither
    }

    public function testNot(): void
    {
        $v = (new Validator())->not((new Validator())->type('string'));
        $this->assertNull($v->isInvalid(42));
        $this->assertNotNull($v->isInvalid('hello'));
    }

    // ========================================
    // Custom validation
    // ========================================

    public function testCustomValidator(): void
    {
        $v = (new Validator())->custom(fn($value) => $value % 2 === 0);
        $this->assertNull($v->isInvalid(4));
        $this->assertNotNull($v->isInvalid(3));
    }

    public function testCustomValidatorWithContext(): void
    {
        $v = (new Validator())
            ->forProperty('password', (new Validator())->required())
            ->forProperty('confirm', (new Validator())
                ->required()
                ->custom(fn($val, $ctx) => $val === ($ctx['password'] ?? null))
            );

        $this->assertNull($v->isInvalid(['password' => 'secret', 'confirm' => 'secret']));
        $errors = $v->isInvalid(['password' => 'secret', 'confirm' => 'different']);
        $this->assertArrayHasKey('confirm', $errors);
    }

    // ========================================
    // Custom error messages
    // ========================================

    public function testCustomErrorMessage(): void
    {
        $v = (new Validator())->minLength(5, 'Too short!');
        $error = $v->isInvalid('hi');
        $this->assertSame('Too short!', (string) $error);
    }

    // ========================================
    // Immutability
    // ========================================

    public function testMethodsReturnNewInstance(): void
    {
        $original = new Validator();
        $modified = $original->type('string');

        $this->assertFalse($original === $modified);
    }

    public function testChainedMethodsPreserveAllRules(): void
    {
        $v = (new Validator())
            ->type('string')
            ->minLength(3)
            ->maxLength(10)
            ->required();

        $this->assertNotNull($v->isInvalid(null));      // required
        $this->assertNotNull($v->isInvalid(123));       // type
        $this->assertNotNull($v->isInvalid('ab'));      // minLength
        $this->assertNotNull($v->isInvalid('hello world!')); // maxLength
        $this->assertNull($v->isInvalid('hello'));      // all pass
    }

    // ========================================
    // JSON serialization
    // ========================================

    public function testJsonSerializeBasicRules(): void
    {
        $v = (new Validator())
            ->type('string')
            ->minLength(3)
            ->maxLength(10);

        $json = $v->jsonSerialize();

        $this->assertSame('string', $json['type']);
        $this->assertSame(3, $json['minLength']);
        $this->assertSame(10, $json['maxLength']);
    }

    public function testJsonSerializeWithProperties(): void
    {
        $v = (new Validator())
            ->forProperty('name', (new Validator())->type('string')->required())
            ->forProperty('age', (new Validator())->type('integer'));

        $json = $v->jsonSerialize();

        $this->assertArrayHasKey('properties', $json);
        $this->assertArrayHasKey('name', $json['properties']);
        $this->assertArrayHasKey('required', $json);
        $this->assertTrue(in_array('name', $json['required']));
    }

    public function testJsonSerializeCustomErrorMessages(): void
    {
        $v = (new Validator())
            ->minLength(5, 'Too short!')
            ->maxLength(10, 'Too long!');

        $json = $v->jsonSerialize();

        $this->assertArrayHasKey('x-error', $json);
        $this->assertSame('Too short!', $json['x-error']['minLength']);
        $this->assertSame('Too long!', $json['x-error']['maxLength']);
    }

    public function testJsonEncodeWorks(): void
    {
        $v = (new Validator())->type('string')->minLength(3);
        $json = json_encode($v);

        $this->assertJson($json);
        $decoded = json_decode($json, true);
        $this->assertSame('string', $decoded['type']);
    }

    // ========================================
    // Invokable
    // ========================================

    public function testInvokable(): void
    {
        $v = (new Validator())->type('string');

        $this->assertNull($v('hello'));
        $this->assertNotNull($v(123));
    }

    // ========================================
    // withFields / withoutFields
    // ========================================

    public function testWithoutFields(): void
    {
        $v = (new Validator())
            ->forProperty('a', (new Validator())->type('string'))
            ->forProperty('b', (new Validator())->type('string'))
            ->forProperty('c', (new Validator())->type('string'));

        $partial = $v->withoutFields(['b', 'c']);

        $this->assertNotNull($partial->a);
        $this->assertNull($partial->b);
        $this->assertNull($partial->c);
    }

    public function testWithFields(): void
    {
        $v = (new Validator())
            ->forProperty('a', (new Validator())->type('string'))
            ->forProperty('b', (new Validator())->type('string'))
            ->forProperty('c', (new Validator())->type('string'));

        $partial = $v->withFields(['a']);

        $this->assertNotNull($partial->a);
        $this->assertNull($partial->b);
        $this->assertNull($partial->c);
    }

    // ========================================
    // ValidationError
    // ========================================

    public function testValidationErrorIsStringable(): void
    {
        $v = (new Validator())->required('Field is required');
        $error = $v->isInvalid(null);

        $this->assertSame('Field is required', (string) $error);
    }

    public function testValidationErrorArrayAccess(): void
    {
        $v = (new Validator())
            ->forProperty('name', (new Validator())->required('Name required'))
            ->forProperty('email', (new Validator())->required('Email required'));

        $error = $v->isInvalid([]);

        // ArrayAccess
        $this->assertNotNull($error['name']);
        $this->assertNotNull($error['email']);
        $this->assertNull($error['nonexistent']);

        // Property errors are also ValidationError instances
        $this->assertSame('Name required', (string) $error['name']);
        $this->assertSame('Email required', (string) $error['email']);
    }

    public function testValidationErrorIteration(): void
    {
        $v = (new Validator())
            ->forProperty('a', (new Validator())->required())
            ->forProperty('b', (new Validator())->required());

        $error = $v->isInvalid([]);

        $fields = [];
        foreach ($error as $field => $fieldError) {
            $fields[] = $field;
            $this->assertInstanceOf(\mini\Validator\ValidationError::class, $fieldError);
        }

        $this->assertTrue(in_array('a', $fields));
        $this->assertTrue(in_array('b', $fields));
    }

    public function testValidationErrorJsonSerialize(): void
    {
        $v = (new Validator())
            ->forProperty('name', (new Validator())->required('Name is required'))
            ->forProperty('age', (new Validator())->type('integer'));

        // Scalar error serializes to string
        $scalarError = (new Validator())->required('Required')->isInvalid(null);
        $this->assertSame('Required', json_decode(json_encode($scalarError)));

        // Object error serializes to object
        $objectError = $v->isInvalid(['age' => 'not a number']);
        $json = json_decode(json_encode($objectError), true);
        $this->assertArrayHasKey('name', $json);
        $this->assertArrayHasKey('age', $json);
    }

    public function testValidationErrorNestedAccess(): void
    {
        $v = (new Validator())
            ->forProperty('user', (new Validator())
                ->forProperty('email', (new Validator())->required('Email required'))
            );

        $error = $v->isInvalid(['user' => []]);

        // Drill down into nested errors
        $this->assertNotNull($error['user']);
        $this->assertNotNull($error['user']['email']);
        $this->assertSame('Email required', (string) $error['user']['email']);
    }

    public function testValidationErrorHasPropertyErrors(): void
    {
        $v = (new Validator())
            ->forProperty('name', (new Validator())->required());

        // Object error has property errors
        $objectError = $v->isInvalid([]);
        $this->assertTrue($objectError->hasPropertyErrors());

        // Scalar error does not
        $scalarError = (new Validator())->required()->isInvalid(null);
        $this->assertFalse($scalarError->hasPropertyErrors());
    }
};

exit($test->run());

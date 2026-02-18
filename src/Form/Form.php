<?php

declare(strict_types=1);

namespace mini\Form;

use mini\Validator\ValidationError;
use function mini\validator;
use function mini\t;

/**
 * Base class for form objects
 *
 * Forms describe fields, validation rules, and optionally actions.
 * They work with both HTML forms and REST APIs.
 *
 * Usage:
 * ```php
 * class LoginForm extends Form
 * {
 *     #[Required('Email is required')]
 *     #[Format('email', 'Please enter a valid email')]
 *     public string $email = '';
 *
 *     #[Required('Password is required')]
 *     public string $password = '';
 *
 *     protected function validate(): void
 *     {
 *         $user = User::findByEmail($this->email);
 *         if (!$user || !$user->verifyPassword($this->password)) {
 *             $this->addError('email', 'Invalid email or password');
 *         }
 *     }
 * }
 *
 * // In controller:
 * $form = new LoginForm();
 * if ($request->getMethod() === 'POST') {
 *     $form->accept($request->getParsedBody());
 *     if (!$form->isInvalid()) {
 *         // Handle success
 *     }
 * }
 * return render('login.php', ['form' => $form]);
 *
 * // In template:
 * <?php $error = $form->isInvalid(); ?>
 * <?php if ($error): ?>
 *     <div class="error"><?= $error->getMessage() ?></div>
 * <?php endif; ?>
 * <input name="email" value="<?= $form->email ?>"
 *        class="<?= $error && $error['email'] ? 'is-invalid' : '' ?>">
 * ```
 */
abstract class Form
{
    /** @var array<string, string[]> Field errors: field name => error messages */
    private array $errors = [];

    /** @var ?ValidationError Cached validation result */
    private ?ValidationError $validationError = null;

    /** @var bool Whether validation has been run */
    private bool $validated = false;

    /** @var bool Whether the form has been submitted */
    private bool $submitted = false;

    /**
     * Fill form with submitted data
     *
     * @param array<string, mixed>|\ArrayAccess $data Form data (typically $_POST)
     * @return static
     */
    public function accept(array|\ArrayAccess $data): static
    {
        $this->submitted = true;
        $this->validated = false;
        $this->validationError = null;
        $this->errors = [];

        $reflection = new \ReflectionClass($this);

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();

            if (!isset($data[$name])) {
                continue;
            }

            $value = $data[$name];
            $type = $property->getType();

            // Type coercion for common types
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                // Skip non-builtin types (objects)
                continue;
            }

            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;

            $this->$name = match ($typeName) {
                'int' => (int) $value,
                'float' => (float) $value,
                'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
                'string' => (string) $value,
                'array' => is_array($value) ? $value : [$value],
                default => $value,
            };
        }

        return $this;
    }

    /**
     * Check if form data is invalid
     *
     * Returns null if valid, ValidationError if invalid.
     * The ValidationError provides:
     * - getMessage(): Form-level error message
     * - Array access: $error['fieldName'] for field-specific errors
     * - Iteration: foreach ($error as $field => $fieldError)
     *
     * @return ValidationError|null Null if valid
     */
    public function isInvalid(): ?ValidationError
    {
        if (!$this->validated) {
            $this->runValidation();
        }

        return $this->validationError;
    }

    /**
     * Check if form data is valid
     *
     * @return bool True if valid
     */
    public function isValid(): bool
    {
        return $this->isInvalid() === null;
    }

    /**
     * Check if form has been submitted
     */
    public function isSubmitted(): bool
    {
        return $this->submitted;
    }

    /**
     * Check if errors have been collected so far
     *
     * Use in validate() to skip cross-field checks if field validation already failed.
     */
    protected function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Add an error for a field
     *
     * Use in validate() for custom validation logic.
     */
    public function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    /**
     * Get form data as array
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];
        $reflection = new \ReflectionClass($this);

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();
            $data[$name] = $this->$name;
        }

        return $data;
    }

    /**
     * Custom validation logic
     *
     * Override to add form-level or cross-field validation.
     * Use addError() to report validation errors.
     */
    protected function validate(): void
    {
        // Override in subclasses
    }

    /**
     * Get the form-level error message
     *
     * Override to customize the message shown when validation fails.
     */
    protected function getFormErrorMessage(): string
    {
        return (string) t('Please correct the errors below.');
    }

    /**
     * Run validation
     */
    private function runValidation(): void
    {
        $this->validated = true;
        $this->errors = [];

        // Only validate if submitted
        if (!$this->submitted) {
            $this->validationError = null;
            return;
        }

        // Run attribute-based validation via the Validator API
        $this->validationError = validator(static::class)->isInvalid($this->toArray());

        if ($this->validationError !== null) {
            return;
        }

        // Run custom validation (subclasses use addError() to report errors)
        $this->validate();

        if (empty($this->errors)) {
            return;
        }

        $propertyErrors = [];
        foreach ($this->errors as $field => $messages) {
            $propertyErrors[$field] = new ValidationError($messages[0]);
        }

        $this->validationError = new ValidationError(
            message: $this->getFormErrorMessage(),
            propertyErrors: $propertyErrors,
        );
    }
}

<?php

namespace mini\Converter;

/**
 * Converter that wraps a typed closure
 *
 * Uses reflection to extract input/output types from closure signature.
 * Supports union input types but not union output types or nullable types.
 */
class ClosureConverter implements ConverterInterface
{
    private \ReflectionFunction $reflection;
    private string $inputTypeString;
    private string $outputType;
    /** @var list<string> */
    private array $inputTypes;

    /**
     * @param \Closure $closure Must have exactly one typed parameter and typed return
     * @param ?string $targetName Optional explicit target type (bypasses return type validation)
     * @throws \InvalidArgumentException If closure signature is invalid
     */
    public function __construct(private \Closure $closure, ?string $targetName = null)
    {
        $this->reflection = new \ReflectionFunction($closure);

        // Validate closure has exactly one parameter with type
        $params = $this->reflection->getParameters();
        if (count($params) !== 1) {
            throw new \InvalidArgumentException(
                'Converter closure must have exactly one parameter'
            );
        }

        // Get and validate input type
        $inputType = $params[0]->getType();
        if (!$inputType) {
            throw new \InvalidArgumentException(
                'Converter closure parameter must have a type declaration'
            );
        }

        // Parse input type (handles union types)
        $this->inputTypes = array_map('trim', explode('|', $inputType->__toString()));

        // Reject null in input types
        if (in_array('null', $this->inputTypes)) {
            throw new \InvalidArgumentException(
                'Converter closure parameter cannot accept null'
            );
        }

        // Normalize union string (sort alphabetically for canonical form)
        // So "string|array" and "array|string" are treated as the same
        sort($this->inputTypes);
        $this->inputTypeString = implode('|', $this->inputTypes);

        // Get and validate return type
        // If targetName is specified, use it directly (bypasses return type validation)
        if ($targetName !== null) {
            $this->outputType = $targetName;
        } else {
            $outputType = $this->reflection->getReturnType();
            if (!$outputType) {
                throw new \InvalidArgumentException(
                    'Converter closure must have a return type declaration'
                );
            }

            $this->outputType = $outputType->__toString();

            // Reject null in output type
            if (str_contains($this->outputType, 'null')) {
                throw new \InvalidArgumentException(
                    'Converter closure cannot return null'
                );
            }

            // Reject union output types
            if (str_contains($this->outputType, '|')) {
                throw new \InvalidArgumentException(
                    'Converter closure cannot have union return type'
                );
            }
        }
    }

    public function getInputType(): string
    {
        return $this->inputTypeString;
    }

    public function getOutputType(): string
    {
        return $this->outputType;
    }

    public function supports(mixed $input, string $targetType): bool
    {
        // Check if input matches any of the accepted input types
        foreach ($this->inputTypes as $acceptedType) {
            if ($this->valueMatchesType($input, $acceptedType)) {
                // Check if output type matches or is subclass of target
                return $this->outputType === $targetType
                    || is_subclass_of($this->outputType, $targetType);
            }
        }

        return false;
    }

    public function convert(mixed $input, string $targetType): mixed
    {
        return ($this->closure)($input);
    }

    /**
     * Check if a value matches a type name
     */
    private function valueMatchesType(mixed $value, string $typeName): bool
    {
        // Handle built-in types
        if (in_array($typeName, ['string', 'int', 'float', 'bool', 'array', 'object', 'resource', 'callable'])) {
            $actualType = get_debug_type($value);
            // Special handling for integers/floats
            if ($typeName === 'int' && $actualType === 'int') return true;
            if ($typeName === 'float' && in_array($actualType, ['float', 'double'])) return true;
            return $actualType === $typeName;
        }

        // Handle 'mixed' type
        if ($typeName === 'mixed') {
            return true;
        }

        // Handle classes/interfaces
        if (is_object($value)) {
            return is_a($value, $typeName);
        }

        return false;
    }
}

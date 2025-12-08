<?php

namespace mini\Converter;

/**
 * Registry for type converters
 *
 * Manages converters that transform values from one type to another.
 * Supports union input types and automatic resolution to most specific converter.
 *
 * Features:
 * - Union input types (e.g., string|int)
 * - Single-type converters can override union members (more specific wins)
 * - Detects conflicting registrations (overlapping unions, duplicates)
 * - Type hierarchy resolution for objects (class → interfaces → parent → parent interfaces)
 *
 * Resolution order:
 * - Direct single-type converter (most specific)
 * - Union type converter via alias (less specific)
 * - Parent class converters
 * - Interface converters
 *
 * @see ConverterRegistryInterface For the public API documentation
 */
class ConverterRegistry implements ConverterRegistryInterface
{
    /**
     * Converters indexed by target type, then input type
     *
     * Structure: targetType => [
     *     'A|B' => ConverterInterface, // union converter
     *     'A'   => 'A|B',              // alias → union key
     *     'B'   => 'A|B',              // alias → union key
     *     'C'   => ConverterInterface, // direct single-type converter
     * ]
     *
     * @var array<string, array<string, ConverterInterface|string>>
     */
    private array $converters = [];

    /**
     * Register a converter
     *
     * @param ConverterInterface|\Closure $converter Converter instance or typed closure
     * @param ?string $targetName Optional explicit target name (bypasses return type validation for closures)
     * @throws \InvalidArgumentException If converter conflicts with existing registration
     */
    public function register(ConverterInterface|\Closure $converter, ?string $targetName = null): void
    {
        $this->doRegister($converter, false, $targetName);
    }

    /**
     * Replace an existing converter
     *
     * @param ConverterInterface|\Closure $converter Converter instance or typed closure
     * @param ?string $targetName Optional explicit target name (bypasses return type validation for closures)
     * @throws \InvalidArgumentException If closure signature is invalid
     */
    public function replace(ConverterInterface|\Closure $converter, ?string $targetName = null): void
    {
        $this->doRegister($converter, true, $targetName);
    }

    /**
     * Internal registration logic
     *
     * @param ConverterInterface|\Closure $converter Converter instance or typed closure
     * @param bool $allowReplace Whether to allow replacing existing converters
     * @param ?string $targetName Optional explicit target name (bypasses return type validation for closures)
     * @throws \InvalidArgumentException If converter conflicts with existing registration
     */
    private function doRegister(ConverterInterface|\Closure $converter, bool $allowReplace, ?string $targetName = null): void
    {
        if ($converter instanceof \Closure) {
            $converter = new ClosureConverter($converter, $targetName);
        }

        $targetType = $converter->getOutputType();
        [$inputKey, $inputTypes] = $this->normalizeInputTypes($converter->getInputType());

        if (!isset($this->converters[$targetType])) {
            $this->converters[$targetType] = [];
        }

        $byInput = &$this->converters[$targetType];

        // Single-type converter
        if (count($inputTypes) === 1) {
            $single = $inputTypes[0];

            // Exact key (single or union key) already registered
            if (isset($byInput[$single]) && $byInput[$single] instanceof ConverterInterface) {
                if (!$allowReplace) {
                    // direct converter already exists for this type → conflict
                    throw new \InvalidArgumentException(
                        sprintf('Converter already exists from %s to %s', $single, $targetType)
                    );
                }
                // replace mode: just overwrite
            }

            // If this type currently aliases to a union, we are more specific → override allowed
            // (We deliberately ignore/overwrite string alias here.)
            $byInput[$single] = $converter;
            return;
        }

        // Union converter
        // Prevent exact same union key duplicate
        if (isset($byInput[$inputKey]) && $byInput[$inputKey] instanceof ConverterInterface) {
            if (!$allowReplace) {
                throw new \InvalidArgumentException(
                    sprintf('Converter already exists from %s to %s', $inputKey, $targetType)
                );
            }
            // replace mode: just overwrite below
        }

        // For each member type, ensure no conflicting converter/alias exists.
        foreach ($inputTypes as $single) {
            if (!isset($byInput[$single])) {
                continue;
            }

            $existing = $byInput[$single];

            // If there's already a direct converter for this single type,
            // union is less specific → conflict (even in replace mode).
            if ($existing instanceof ConverterInterface && !$allowReplace) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Cannot register union converter %s→%s: single-type converter for %s already exists',
                        $inputKey,
                        $targetType,
                        $single
                    )
                );
            }

            // If there is already an alias for this single type, it points to another union.
            // Two overlapping unions for the same single type → conflict (even in replace mode).
            if (is_string($existing) && !$allowReplace) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Cannot register union converter %s→%s: %s already part of union %s',
                        $inputKey,
                        $targetType,
                        $single,
                        $existing
                    )
                );
            }
        }

        // No conflicts (or replace mode): register union converter and alias each member type to it
        $byInput[$inputKey] = $converter;

        foreach ($inputTypes as $single) {
            $byInput[$single] = $inputKey;
        }
    }

    /**
     * Check if a converter exists for input to target type
     *
     * @param mixed $input The value to convert
     * @param class-string $targetType The desired output type
     * @return bool
     */
    public function has(mixed $input, string $targetType): bool
    {
        return $this->findConverter($input, $targetType) !== null;
    }

    /**
     * Get the converter for input to target type
     *
     * @param mixed $input The value to convert
     * @param class-string $targetType The desired output type
     * @return ConverterInterface|null
     */
    public function get(mixed $input, string $targetType): ?ConverterInterface
    {
        return $this->findConverter($input, $targetType);
    }

    /**
     * Convert a value to target type
     *
     * @template O
     * @param mixed $input The value to convert
     * @param class-string<O> $targetType The desired output type
     * @return O|null The converted value, or null if no converter found
     */
    public function convert(mixed $input, string $targetType): mixed
    {
        $converter = $this->findConverter($input, $targetType);
        if ($converter === null) {
            return null;
        }

        return $converter->convert($input, $targetType);
    }

    /**
     * Find most specific converter for input to target type
     *
     * @param mixed $input
     * @param class-string $targetType
     * @return ConverterInterface|null
     */
    private function findConverter(mixed $input, string $targetType): ?ConverterInterface
    {
        if (!isset($this->converters[$targetType])) {
            return null;
        }

        // Scalars: only one "type"
        if (!is_object($input)) {
            $inputType = get_debug_type($input);
            return $this->lookupConverterForType($inputType, $targetType, $input);
        }

        // Objects: walk class + interfaces + parents in specificity order
        foreach ($this->walkInputTypes($input) as $inputType) {
            $converter = $this->lookupConverterForType($inputType, $targetType, $input);
            if ($converter !== null) {
                return $converter;
            }
        }

        return null;
    }

    /**
     * Look up a converter for specific input type to target type
     *
     * @param string $inputType The type to look up
     * @param string $targetType The target type
     * @param mixed $input The actual input value (for supports() check)
     * @return ConverterInterface|null
     */
    private function lookupConverterForType(string $inputType, string $targetType, mixed $input): ?ConverterInterface
    {
        if (!isset($this->converters[$targetType][$inputType])) {
            return null;
        }

        $entry = $this->converters[$targetType][$inputType];

        // Direct converter
        if ($entry instanceof ConverterInterface) {
            return $entry->supports($input, $targetType) ? $entry : null;
        }

        // Alias → resolve union key
        if (is_string($entry) && isset($this->converters[$targetType][$entry])) {
            $conv = $this->converters[$targetType][$entry];
            if ($conv instanceof ConverterInterface && $conv->supports($input, $targetType)) {
                return $conv;
            }
        }

        return null;
    }

    /**
     * Normalize input type string into a canonical key and list of member types.
     *
     * Examples:
     *   "string"         → ["string", ["string"]]
     *   "int|string"     → ["int|string", ["int", "string"]]
     *   "  B | A | A  "  → ["A|B", ["A", "B"]]
     *
     * @param string $inputTypeString
     * @return array{0: string, 1: list<string>}
     */
    private function normalizeInputTypes(string $inputTypeString): array
    {
        // Expecting something like "A" or "A|B|C" from our own converters
        $parts = explode('|', $inputTypeString);
        $parts = array_values(array_unique($parts));

        if ($parts === []) {
            throw new \InvalidArgumentException('Converter input type cannot be empty');
        }

        if (count($parts) === 1) {
            // Single type: key == type
            return [$parts[0], $parts];
        }

        sort($parts, SORT_STRING);
        $key = implode('|', $parts);

        return [$key, $parts];
    }

    /**
     * Walk the type hierarchy for an object
     *
     * Yields types in specificity order: class, its direct interfaces, parent class, parent's direct interfaces, etc.
     *
     * @param object $input Must be an object (not scalar)
     * @return \Generator<string>
     */
    private function walkInputTypes(object $input): \Generator
    {
        $rc = new \ReflectionClass($input);

        while ($rc !== false) {
            // Yield the class itself
            yield $rc->getName();

            // Yield direct interfaces (not inherited from parent)
            $parent = $rc->getParentClass();
            foreach ($rc->getInterfaceNames() as $interfaceName) {
                // Skip if parent already implements this interface
                if ($parent === false || !$parent->implementsInterface($interfaceName)) {
                    yield $interfaceName;
                }
            }

            // Move to parent
            $rc = $parent;
        }
    }
}

<?php

namespace mini\Tables;

use mini\Tables\Codecs\CodecInterface;
use mini\Tables\Codecs\StringCodecInterface;
use mini\Tables\Codecs\IntegerCodecInterface;

/**
 * Codec Registry - Manages type conversion codecs
 *
 * Central registry for field type codecs used by repositories and tables.
 * Provides built-in codecs for common PHP types and allows custom codec registration.
 *
 * Usage:
 *   CodecRegistry::register(Money::class, new MoneyCodec());
 *   $codec = CodecRegistry::get(DateTime::class);
 */
class CodecRegistry
{
    private static ?\mini\Util\InstanceStore $codecs = null;

    /**
     * Initialize the codec registry with built-in codecs
     */
    private static function init(): void
    {
        if (self::$codecs !== null) {
            return;
        }

        self::$codecs = new \mini\Util\InstanceStore(CodecInterface::class);
        self::registerBuiltins();
    }

    /**
     * Register a custom codec for a PHP type
     *
     * @param class-string $className The PHP class name to register codec for
     * @param CodecInterface $codec The codec implementation
     */
    public static function register(string $className, CodecInterface $codec): void
    {
        self::init();
        self::$codecs->set($className, $codec);
    }

    /**
     * Get registered codec for a PHP type
     *
     * @param class-string $className The PHP class name
     * @return CodecInterface|null The codec or null if not registered
     */
    public static function get(string $className): ?CodecInterface
    {
        self::init();
        return self::$codecs->get($className);
    }

    /**
     * Check if codec is registered for a PHP type
     *
     * @param class-string $className The PHP class name
     * @return bool True if codec is registered
     */
    public static function has(string $className): bool
    {
        self::init();
        return self::$codecs->has($className);
    }

    /**
     * Register built-in codec types
     *
     * Provides out-of-the-box support for common PHP types:
     * - DateTime (string and integer backends)
     * - DateTimeImmutable (string and integer backends)
     *
     * Called automatically on first registry access.
     */
    private static function registerBuiltins(): void
    {
        // DateTime support - handles both string and integer backends
        self::$codecs->set(\DateTime::class, new class
            implements StringCodecInterface, IntegerCodecInterface {

            public function fromBackendString(string $value): \DateTime
            {
                return new \DateTime($value);
            }

            public function toBackendString(mixed $value): string
            {
                return $value->format('Y-m-d H:i:s');
            }

            public function fromBackendInteger(int $value): \DateTime
            {
                $dt = new \DateTime();
                $dt->setTimestamp($value);
                return $dt;
            }

            public function toBackendInteger(mixed $value): int
            {
                return $value->getTimestamp();
            }
        });

        // DateTimeImmutable support - handles both string and integer backends
        self::$codecs->set(\DateTimeImmutable::class, new class
            implements StringCodecInterface, IntegerCodecInterface {

            public function fromBackendString(string $value): \DateTimeImmutable
            {
                return new \DateTimeImmutable($value);
            }

            public function toBackendString(mixed $value): string
            {
                return $value->format('Y-m-d H:i:s');
            }

            public function fromBackendInteger(int $value): \DateTimeImmutable
            {
                $dt = new \DateTimeImmutable();
                return $dt->setTimestamp($value);
            }

            public function toBackendInteger(mixed $value): int
            {
                return $value->getTimestamp();
            }
        });
    }
}

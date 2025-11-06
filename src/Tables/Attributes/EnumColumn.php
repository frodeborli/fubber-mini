<?php

namespace mini\Tables\Attributes;

use Attribute;

/**
 * ENUM column attribute for enumerated value storage
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class EnumColumn extends Column
{
    public function __construct(
        ?string $name = null,
        ?array $enum = null,
        string $backed = 'string',         // 'string'|'int' - backing type
        ?string $phpEnum = null,           // class-string<\BackedEnum> for PHP enum mapping
        bool $nullable = false,
        mixed $default = null
    ) {
        parent::__construct(
            name: $name,
            type: $backed === 'int' ? 'integer' : 'string',
            nullable: $nullable,
            default: $default,
            enum: $enum
        );

        $this->backed = $backed;
        $this->phpEnum = $phpEnum;
    }

    public readonly string $backed;
    public readonly ?string $phpEnum;
}

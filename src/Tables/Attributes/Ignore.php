<?php

namespace mini\Tables\Attributes;

use Attribute;

/**
 * Ignore attribute for excluding properties from database mapping
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Ignore
{
    public function __construct() {}
}

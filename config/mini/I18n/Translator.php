<?php
/**
 * Default Translator configuration for Mini framework
 *
 * Applications can override by creating _config/mini/I18n/Translator.php
 */

use mini\I18n\Translator;
use mini\Mini;

$translationsPath = Mini::$mini->root . '/_translations';
$translator = new Translator($translationsPath);

// Register mini framework translation scope
$miniFrameworkPath = dirname(__FILE__, 4) . '/src';
$translator->addNamedScope('MINI-FRAMEWORK', $miniFrameworkPath);

return $translator;

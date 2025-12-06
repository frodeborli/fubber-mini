<?php
/**
 * Tests for mini\I18n\Translator
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Mini;
use mini\Test;
use mini\I18n\Translator;
use mini\I18n\Translatable;
use mini\Util\PathsRegistry;

$test = new class extends Test {

    private Translator $translator;
    private string $originalLocale;

    protected function setUp(): void
    {
        $this->originalLocale = \Locale::getDefault();

        // Create translator with test translations path
        $paths = new PathsRegistry(__DIR__ . '/_translations');
        $this->translator = new Translator($paths, autoCreateDefaults: false);

        // Register our test translator
        Mini::$mini->set(Translator::class, $this->translator);
        Mini::$mini->set(\mini\I18n\TranslatorInterface::class, $this->translator);

        \mini\bootstrap();
    }

    public function testSimpleTranslation(): void
    {
        \Locale::setDefault('de_DE');

        $translatable = new Translatable('Hello');
        $result = $this->translator->translate($translatable);

        $this->assertSame('Hallo', $result);
    }

    public function testTranslationWithVariables(): void
    {
        \Locale::setDefault('de_DE');

        $translatable = new Translatable('Hello {name}', ['name' => 'World']);
        $result = $this->translator->translate($translatable);

        $this->assertSame('Hallo World', $result);
    }

    public function testPluralTranslation(): void
    {
        \Locale::setDefault('de_DE');

        $pattern = '{count, plural, =0{no items} one{# item} other{# items}}';

        // Zero
        $result = $this->translator->translate(new Translatable($pattern, ['count' => 0]));
        $this->assertSame('keine Artikel', $result);

        // One
        $result = $this->translator->translate(new Translatable($pattern, ['count' => 1]));
        $this->assertSame('1 Artikel', $result);

        // Many
        $result = $this->translator->translate(new Translatable($pattern, ['count' => 5]));
        $this->assertSame('5 Artikel', $result);
    }

    public function testLocaleSwitch(): void
    {
        // German
        \Locale::setDefault('de_DE');
        $result = $this->translator->translate(new Translatable('Goodbye'));
        $this->assertSame('Auf Wiedersehen', $result);

        // Norwegian
        \Locale::setDefault('nb_NO');
        $result = $this->translator->translate(new Translatable('Goodbye'));
        $this->assertSame('Ha det', $result);

        // Back to German
        \Locale::setDefault('de_DE');
        $result = $this->translator->translate(new Translatable('Goodbye'));
        $this->assertSame('Auf Wiedersehen', $result);
    }

    public function testFallbackToDefault(): void
    {
        // Use a locale that doesn't have translations
        \Locale::setDefault('fr_FR');

        $result = $this->translator->translate(new Translatable('Hello'));

        // Should fall back to default
        $this->assertSame('Hello', $result);
    }

    public function testMissingTranslationReturnsSourceText(): void
    {
        \Locale::setDefault('de_DE');

        // This string doesn't exist in any translation file
        $result = $this->translator->translate(new Translatable('This string does not exist'));

        // Should return the source text with variable interpolation
        $this->assertSame('This string does not exist', $result);
    }

    public function testMissingTranslationWithVariables(): void
    {
        \Locale::setDefault('de_DE');

        // Missing translation but with variables
        $result = $this->translator->translate(
            new Translatable('Unknown greeting {name}', ['name' => 'Test'])
        );

        // Should interpolate variables in source text
        $this->assertSame('Unknown greeting Test', $result);
    }

    public function __destruct()
    {
        // Restore original locale
        if (isset($this->originalLocale)) {
            \Locale::setDefault($this->originalLocale);
        }
    }
};

exit($test->run());

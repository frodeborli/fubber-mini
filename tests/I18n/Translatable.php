<?php
/**
 * Tests for mini\I18n\Translatable and t() function
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Mini;
use mini\Test;
use mini\I18n\Translator;
use mini\I18n\Translatable;
use mini\Util\PathsRegistry;
use function mini\t;

$test = new class extends Test {

    private string $originalLocale;

    protected function setUp(): void
    {
        $this->originalLocale = \Locale::getDefault();

        // Create translator with test translations path
        $paths = new PathsRegistry(__DIR__ . '/_translations');
        $translator = new Translator($paths, autoCreateDefaults: false);

        // Register our test translator
        Mini::$mini->set(Translator::class, $translator);
        Mini::$mini->set(\mini\I18n\TranslatorInterface::class, $translator);

        \mini\bootstrap();
    }

    public function testTFunctionReturnsTranslatable(): void
    {
        $result = t('Hello');

        $this->assertInstanceOf(Translatable::class, $result);
    }

    public function testTFunctionWithVariables(): void
    {
        $result = t('Hello {name}', ['name' => 'World']);

        $this->assertInstanceOf(Translatable::class, $result);
        $this->assertSame(['name' => 'World'], $result->getVars());
    }

    public function testTranslatableGetSourceText(): void
    {
        $translatable = new Translatable('Test source text');

        $this->assertSame('Test source text', $translatable->getSourceText());
    }

    public function testTranslatableGetVars(): void
    {
        $translatable = new Translatable('Hello {name}', ['name' => 'Test']);

        $this->assertSame(['name' => 'Test'], $translatable->getVars());
    }

    public function testTranslatableGetSourceFile(): void
    {
        $translatable = t('Hello');

        // Should capture the file where t() was called
        $this->assertContains('Translatable.php', $translatable->getSourceFile());
    }

    public function testTranslatableToStringTranslates(): void
    {
        \Locale::setDefault('de_DE');

        // The __toString() calls the translator
        $result = (string) t('Hello');

        $this->assertSame('Hallo', $result);
    }

    public function testTranslatableToStringWithVariables(): void
    {
        \Locale::setDefault('de_DE');

        $result = (string) t('Hello {name}', ['name' => 'World']);

        $this->assertSame('Hallo World', $result);
    }

    public function testTranslatableToStringLocaleAware(): void
    {
        // German
        \Locale::setDefault('de_DE');
        $de = (string) t('Goodbye');

        // Norwegian
        \Locale::setDefault('nb_NO');
        $nb = (string) t('Goodbye');

        $this->assertSame('Auf Wiedersehen', $de);
        $this->assertSame('Ha det', $nb);
    }

    public function testTranslatableCanBeEchoed(): void
    {
        \Locale::setDefault('de_DE');

        ob_start();
        echo t('Hello');
        $output = ob_get_clean();

        $this->assertSame('Hallo', $output);
    }

    public function testTranslatableInStringInterpolation(): void
    {
        \Locale::setDefault('de_DE');

        $greeting = t('Hello');
        $result = "Say: $greeting";

        $this->assertSame('Say: Hallo', $result);
    }

    public function __destruct()
    {
        if (isset($this->originalLocale)) {
            \Locale::setDefault($this->originalLocale);
        }
    }
};

exit($test->run());

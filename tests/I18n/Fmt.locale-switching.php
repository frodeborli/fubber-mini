<?php
/**
 * Tests that Fmt respects locale changes during execution
 *
 * This catches any internal caching that might cause stale formatting
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\I18n\Fmt;

$test = new class extends Test {

    private string $originalLocale;
    private DateTime $date;
    private float $number = 1234.56;

    protected function setUp(): void
    {
        $this->originalLocale = \Locale::getDefault();
        $this->date = new DateTime('2024-12-25 14:30:00');
    }

    /**
     * Formatting the same values under different locales must produce different
     * output, and switching back must reproduce the original output exactly.
     *
     * The steps are order-dependent: en → de → en on the same static formatters.
     */
    public function testLocaleSwitchingIsNotCached(): void
    {
        $date = $this->date;
        $number = $this->number;

        // Format in English
        \Locale::setDefault('en_US');
        $enNumber = Fmt::number($number, 2);
        $enDate = Fmt::dateLong($date);
        $enCurrency = Fmt::currency(19.99, 'USD');

        // Switch to German and format same values
        \Locale::setDefault('de_DE');
        $deNumber = Fmt::number($number, 2);
        $deDate = Fmt::dateLong($date);
        $deCurrency = Fmt::currency(19.99, 'EUR');

        // Verify they're different (locale was respected)
        $this->assertTrue($enNumber !== $deNumber, "Number format should differ: en='$enNumber' de='$deNumber'");
        $this->assertTrue($enDate !== $deDate, "Date format should differ: en='$enDate' de='$deDate'");

        // Switch back to English - should match original
        \Locale::setDefault('en_US');
        $enNumber2 = Fmt::number($number, 2);
        $enDate2 = Fmt::dateLong($date);

        $this->assertSame($enNumber, $enNumber2, 'Switching back to en_US produces same number format');
        $this->assertSame($enDate, $enDate2, 'Switching back to en_US produces same date format');

        \Locale::setDefault($this->originalLocale);
    }

    /**
     * Rapid switching test - format alternating between locales
     */
    public function testRapidLocaleSwitchingIsStable(): void
    {
        $number = $this->number;

        $results = [];
        for ($i = 0; $i < 5; $i++) {
            \Locale::setDefault('en_US');
            $results[] = ['en', Fmt::number($number, 2)];

            \Locale::setDefault('de_DE');
            $results[] = ['de', Fmt::number($number, 2)];
        }

        // Verify all English results match each other, all German match each other
        $enResults = array_filter($results, fn($r) => $r[0] === 'en');
        $deResults = array_filter($results, fn($r) => $r[0] === 'de');

        $enValues = array_unique(array_column($enResults, 1));
        $deValues = array_unique(array_column($deResults, 1));

        $this->assertCount(1, $enValues, 'All en_US results should be identical');
        $this->assertCount(1, $deValues, 'All de_DE results should be identical');
        $this->assertTrue($enValues !== $deValues, 'en_US and de_DE should produce different results');

        \Locale::setDefault($this->originalLocale);
    }
};

exit($test->run());

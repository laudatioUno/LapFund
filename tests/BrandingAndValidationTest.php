<?php

declare(strict_types=1);

namespace LapFund\Tests;

use LapFund\Database\Migrator;
use LapFund\Service\Branding;
use LapFund\Validation;
use PHPUnit\Framework\TestCase;

final class BrandingAndValidationTest extends TestCase
{
    public function testColorValidationBlocksCssInjection(): void
    {
        self::assertSame('#0B5FA8', Branding::validColor('#0b5fa8'));
        self::assertNull(Branding::validColor('red'));
        self::assertNull(Branding::validColor('#000;}body{display:none'));
        self::assertNull(Branding::validColor('#FFF'));
    }

    public function testTextColorOnAccentHasEnoughContrast(): void
    {
        foreach (['#0B5FA8', '#C8202A', '#FFE14D', '#FFFFFF', '#14212E', '#1B6B3A', '#7FB2E5'] as $color) {
            $text = Branding::textColorOn($color);
            self::assertGreaterThanOrEqual(4.5, Branding::contrast($color, $text), "text on {$color}");
        }
        self::assertSame('#FFFFFF', Branding::textColorOn('#0B5FA8'));
        self::assertSame('#14212E', Branding::textColorOn('#FFE14D'));
    }

    public function testValidation(): void
    {
        self::assertNull(Validation::username('anna.muster'));
        self::assertNotNull(Validation::username('An'));
        self::assertNotNull(Validation::username('Anna'));
        self::assertNull(Validation::email('anna@example.ch'));
        self::assertNotNull(Validation::email('anna@'));
        self::assertNull(Validation::optionalUrl(''));
        self::assertNull(Validation::optionalUrl('https://example.ch/datenschutz'));
        self::assertNotNull(Validation::optionalUrl('javascript:alert(1)'));
        self::assertNotNull(Validation::optionalUrl('ftp://example.ch'));
    }

    public function testMigrationSplitter(): void
    {
        $sql = "-- comment\nCREATE TABLE a (x INT);\n\nCREATE TABLE b (\n  y TEXT\n);\n";
        self::assertSame(['CREATE TABLE a (x INT)', "CREATE TABLE b (\n  y TEXT\n)"], Migrator::splitStatements($sql));
    }

    public function testInitialMigrationParses(): void
    {
        $statements = Migrator::splitStatements((string) file_get_contents(dirname(__DIR__) . '/migrations/001_init.sql'));
        self::assertCount(6, $statements);
        foreach ($statements as $statement) {
            self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS', $statement);
        }
    }
}

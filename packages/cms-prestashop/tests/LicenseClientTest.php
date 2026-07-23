<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests unitarios para LicenseClient.
 * Prueba formatExpiresAt() — la conversion de ISO 8601 (formato que manda
 * bot-miki) a DATETIME de MySQL (#115).
 */
class LicenseClientTest extends TestCase
{
    public function test_formatExpiresAt_converts_iso_with_milliseconds_and_z(): void
    {
        $this->assertSame('2026-07-23 08:36:58', LicenseClient::formatExpiresAt('2026-07-23T08:36:58.123Z'));
    }

    public function test_formatExpiresAt_converts_iso_without_milliseconds(): void
    {
        $this->assertSame('2026-01-01 00:00:00', LicenseClient::formatExpiresAt('2026-01-01T00:00:00Z'));
    }

    public function test_formatExpiresAt_result_is_accepted_by_a_real_DATETIME_literal(): void
    {
        // MySQL rechaza "2026-07-23T08:36:58.123Z" directo (ERROR 1292) —
        // el resultado de formatExpiresAt() debe tener la forma exacta que
        // MySQL SI acepta: "YYYY-MM-DD HH:MM:SS".
        $formatted = LicenseClient::formatExpiresAt('2026-07-23T08:36:58.123Z');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $formatted);
    }
}

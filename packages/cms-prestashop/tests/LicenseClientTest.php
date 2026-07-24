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

    // ─── isWithinStaleJwtGrace (#128: modo degradado por caida de bot-miki) ──

    public function test_isWithinStaleJwtGrace_jwt_aun_no_expirado(): void
    {
        $future = gmdate('Y-m-d H:i:s', time() + 300);
        $this->assertTrue(LicenseClient::isWithinStaleJwtGrace($future));
    }

    public function test_isWithinStaleJwtGrace_expirado_hace_2_horas_esta_dentro_de_la_ventana(): void
    {
        $twoHoursAgo = gmdate('Y-m-d H:i:s', time() - 2 * 3600);
        $this->assertTrue(LicenseClient::isWithinStaleJwtGrace($twoHoursAgo));
    }

    public function test_isWithinStaleJwtGrace_expirado_hace_25_horas_esta_fuera_de_la_ventana(): void
    {
        $twentyFiveHoursAgo = gmdate('Y-m-d H:i:s', time() - 25 * 3600);
        $this->assertFalse(LicenseClient::isWithinStaleJwtGrace($twentyFiveHoursAgo));
    }

    public function test_isWithinStaleJwtGrace_limite_exacto_de_24_horas_esta_dentro(): void
    {
        $exactly24hAgo = gmdate('Y-m-d H:i:s', time() - 24 * 3600);
        $this->assertTrue(LicenseClient::isWithinStaleJwtGrace($exactly24hAgo));
    }
}

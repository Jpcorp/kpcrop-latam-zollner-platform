<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests unitarios para LicenseClient.
 * Prueba formatExpiresAt() — la conversion de ISO 8601 (formato que manda
 * bot-miki) a DATETIME de MySQL (#115).
 */
class LicenseClientTest extends TestCase
{
    protected function setUp(): void
    {
        Db::reset();
    }

    // ─── El JWT se cachea por tienda, no por el Context ──────────────────────

    public function testElJwtCacheadoSeBuscaPorLaTiendaDelConstructor(): void
    {
        // Antes las 3 consultas usaban Context::getContext()->shop->id, que en el
        // bootstrap CLI (config.inc.php sin init.php) resuelve siempre a la tienda
        // por defecto: `sync.php --shop=2` pisaba el JWT de la tienda 1 con el de
        // la 2, y la 2 nunca cacheaba nada (revalidaba contra bot-miki cada vez).
        $sqlVisto = null;
        Db::getInstance()->queryResults['license_jwt'] = function (string $sql) use (&$sqlVisto) {
            $sqlVisto = $sql;
            return [
                'license_jwt'         => 'jwt-de-la-tienda-7',
                'license_jwt_expires' => gmdate('Y-m-d H:i:s', time() + 3600),
            ];
        };

        $token = (new LicenseClient('https://miki.test', 'api-key', 7))->getToken();

        $this->assertSame('jwt-de-la-tienda-7', $token, 'Debe devolver el JWT cacheado sin salir a la red');
        $this->assertStringContainsString('id_shop = 7', $sqlVisto);
        $this->assertStringNotContainsString('id_shop = 1', $sqlVisto);
    }

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

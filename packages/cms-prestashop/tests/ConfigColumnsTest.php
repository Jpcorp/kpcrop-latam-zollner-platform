<?php

use PHPUnit\Framework\TestCase;

/**
 * Toda columna de synkrop_config que el codigo ESCRIBE tiene que existir en el
 * esquema (install.sql para instalaciones nuevas, migrations.php para las que
 * ya estaban instaladas).
 *
 * El fallo que previene es de produccion, no de desarrollo: agregar un campo al
 * update de LicenseClient y olvidar la migracion hace que el UPDATE reviente
 * —o que PrestaShop lo descarte en silencio— solo en las tiendas ya instaladas.
 * En local, donde el modulo se reinstala con install.sql, todo parece bien.
 */
class ConfigColumnsTest extends TestCase
{
    private const BASE = __DIR__ . '/../synkrop';

    /** Columnas de synkrop_config declaradas en el esquema. */
    private function columnasDelEsquema(): array
    {
        $columnas = [];

        // install.sql: instalaciones nuevas
        $install = (string) file_get_contents(self::BASE . '/sql/install.sql');
        if (preg_match('/CREATE TABLE[^;]*synkrop_config(.*?);/is', $install, $m)) {
            preg_match_all('/^\s*`?(\w+)`?\s+(?:INT|VARCHAR|TEXT|TINYINT|DATETIME|DECIMAL|JSON|BIGINT)/im', $m[1], $cols);
            $columnas = array_merge($columnas, $cols[1]);
        }

        // migrations.php: tiendas ya instaladas
        $migraciones = require self::BASE . '/sql/migrations.php';
        foreach ($migraciones as $mig) {
            if ($mig[0] === 'column' && $mig[1] === 'synkrop_config') {
                $columnas[] = $mig[2];
            }
        }

        return array_unique($columnas);
    }

    /** Columnas que LicenseClient escribe en su update de synkrop_config. */
    private function columnasQueEscribeLicenseClient(): array
    {
        $codigo = (string) file_get_contents(self::BASE . '/classes/LicenseClient.php');

        if (!preg_match("/update\(\s*'synkrop_config'\s*,\s*\[(.*?)\]\s*,/is", $codigo, $m)) {
            $this->fail('No encontre el update de synkrop_config en LicenseClient');
        }

        preg_match_all("/'(\w+)'\s*=>/", $m[1], $claves);

        return array_unique($claves[1]);
    }

    public function testLicenseClientSoloEscribeColumnasQueExisten(): void
    {
        $esquema  = $this->columnasDelEsquema();
        $escritas = $this->columnasQueEscribeLicenseClient();

        $this->assertNotEmpty($escritas, 'No se detecto ninguna columna escrita');

        $faltantes = array_diff($escritas, $esquema);

        $this->assertSame(
            [],
            array_values($faltantes),
            'LicenseClient escribe columnas que no estan en el esquema: '
            . implode(', ', $faltantes)
            . '. Agregalas a sql/migrations.php (y a install.sql si corresponde).'
        );
    }

    /** El plan que se muestra en el panel viaja por estas tres columnas. */
    public function testLasColumnasDelPlanEstanDeclaradas(): void
    {
        $esquema = $this->columnasDelEsquema();

        foreach (['license_plan', 'license_max_stores', 'license_features'] as $columna) {
            $this->assertContains(
                $columna,
                $esquema,
                "Falta '$columna' en el esquema: el panel no podria mostrar el plan"
            );
        }
    }

    /** Y LicenseClient tiene que persistirlas, o el panel las mostraria vacias. */
    public function testLicenseClientPersisteElPlan(): void
    {
        $escritas = $this->columnasQueEscribeLicenseClient();

        foreach (['license_plan', 'license_max_stores', 'license_features'] as $columna) {
            $this->assertContains(
                $columna,
                $escritas,
                "LicenseClient no guarda '$columna': el panel mostraria el plan vacio"
            );
        }
    }
}

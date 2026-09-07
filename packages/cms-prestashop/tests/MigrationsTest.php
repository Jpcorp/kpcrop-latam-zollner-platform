<?php

use PHPUnit\Framework\TestCase;

/**
 * Protege sql/migrations.php contra el error que costo 6 semanas de produccion:
 * una migracion que existe como .sql pero que nadie aplico en la tienda.
 *
 * El .sql es la fuente historica; migrations.php es lo que se ejecuta hoy. Si
 * divergen, alguien agrego un .sql y olvido registrarlo (o al reves), y en la
 * proxima tienda esa migracion no se va a aplicar — en silencio, porque un
 * ALTER que no corre no hace ruido.
 */
class MigrationsTest extends TestCase
{
    private const SQL_DIR = __DIR__ . '/../synkrop/sql';

    /** @return array<int, array> */
    private function migrations(): array
    {
        return require self::SQL_DIR . '/migrations.php';
    }

    /**
     * Nombres declarados en migrations.php, por tipo.
     *
     * @return array{columns: string[], indexes: string[], tables: string[], drops: string[]}
     */
    private function declared(): array
    {
        $out = ['columns' => [], 'indexes' => [], 'tables' => [], 'drops' => []];

        foreach ($this->migrations() as $m) {
            switch ($m[0]) {
                case 'column':
                    $out['columns'][] = $m[2];
                    break;
                case 'index':
                case 'unique':
                    $out['indexes'][] = $m[2];
                    break;
                case 'dropindex':
                    $out['drops'][] = $m[2];
                    break;
                case 'table':
                    $out['tables'][] = $m[1];
                    break;
            }
        }

        return $out;
    }

    /** Lo que los .sql historicos dicen que hay que hacer. */
    private function fromSqlFiles(): array
    {
        $out = ['columns' => [], 'indexes' => [], 'tables' => [], 'drops' => []];

        foreach (glob(self::SQL_DIR . '/migrate_add_*.sql') as $file) {
            // Sin los comentarios: migrate_add_job_id.sql cita la sintaxis
            // "ADD COLUMN IF NOT EXISTS" en una linea `--`, y el parser la
            // tomaba por una migracion llamada "IF".
            $sql = preg_replace('/--[^\n]*/', '', file_get_contents($file));

            // Igual se tolera IF NOT EXISTS por si alguna migracion lo usa de verdad.
            $ine = '(?:IF\s+NOT\s+EXISTS\s+)?';

            preg_match_all("/ADD COLUMN\s+{$ine}`?(\w+)`?/i", $sql, $m);
            $out['columns'] = array_merge($out['columns'], $m[1]);

            preg_match_all("/ADD (?:UNIQUE )?(?:KEY|INDEX)\s+{$ine}`?(\w+)`?/i", $sql, $m);
            $out['indexes'] = array_merge($out['indexes'], $m[1]);

            preg_match_all("/CREATE INDEX\s+{$ine}`?(\w+)`?/i", $sql, $m);
            $out['indexes'] = array_merge($out['indexes'], $m[1]);

            preg_match_all("/DROP INDEX\s+{$ine}`?(\w+)`?/i", $sql, $m);
            $out['drops'] = array_merge($out['drops'], $m[1]);

            preg_match_all("/CREATE TABLE IF NOT EXISTS `',\s*@db_prefix,\s*'(\w+)`/i", $sql, $m);
            $out['tables'] = array_merge($out['tables'], $m[1]);
        }

        return array_map(
            static fn(array $v): array => array_values(array_unique($v)),
            $out
        );
    }

    public function testCubreTodasLasColumnasDeLosSqlHistoricos(): void
    {
        $faltan = array_diff($this->fromSqlFiles()['columns'], $this->declared()['columns']);

        $this->assertSame(
            [],
            array_values($faltan),
            'Hay columnas en los migrate_*.sql que migrations.php no aplica: '
            . implode(', ', $faltan)
        );
    }

    public function testCubreTodosLosIndicesDeLosSqlHistoricos(): void
    {
        $faltan = array_diff($this->fromSqlFiles()['indexes'], $this->declared()['indexes']);

        $this->assertSame([], array_values($faltan), 'Indices sin declarar: ' . implode(', ', $faltan));
    }

    public function testCubreTodasLasTablasDeLosSqlHistoricos(): void
    {
        $faltan = array_diff($this->fromSqlFiles()['tables'], $this->declared()['tables']);

        $this->assertSame([], array_values($faltan), 'Tablas sin declarar: ' . implode(', ', $faltan));
    }

    public function testCubreLosDropIndexDeLosSqlHistoricos(): void
    {
        $faltan = array_diff($this->fromSqlFiles()['drops'], $this->declared()['drops']);

        $this->assertSame([], array_values($faltan), 'DROP INDEX sin declarar: ' . implode(', ', $faltan));
    }

    /**
     * variant_unique elimina idx_bsale_variant y crea uk_variant_shop sobre las
     * mismas columnas. Al reves, MySQL rechaza el DROP: el indice ya no existe
     * con ese nombre y la migracion muere a mitad.
     */
    public function testElDropIndexVaAntesDelUniqueQueLoReemplaza(): void
    {
        $posDrop = $posUnique = null;

        foreach ($this->migrations() as $i => $m) {
            if ($m[0] === 'dropindex' && $m[2] === 'idx_bsale_variant') {
                $posDrop = $i;
            }
            if ($m[0] === 'unique' && $m[2] === 'uk_variant_shop') {
                $posUnique = $i;
            }
        }

        $this->assertNotNull($posDrop, 'falta el dropindex de idx_bsale_variant');
        $this->assertNotNull($posUnique, 'falta el unique uk_variant_shop');
        $this->assertLessThan($posUnique, $posDrop, 'el DROP INDEX debe ir antes del UNIQUE');
    }

    public function testNoHayOperacionesDuplicadas(): void
    {
        $vistas = [];

        foreach ($this->migrations() as $m) {
            $clave = $m[0] . ':' . $m[1] . ':' . ($m[0] === 'table' ? '' : $m[2]);
            $this->assertNotContains($clave, $vistas, "operacion duplicada: $clave");
            $vistas[] = $clave;
        }
    }

    public function testTodaOperacionTieneTipoValidoYFormaCorrecta(): void
    {
        $conSpec = ['column', 'index', 'unique'];

        foreach ($this->migrations() as $i => $m) {
            $this->assertContains(
                $m[0],
                ['column', 'index', 'unique', 'dropindex', 'table'],
                "tipo desconocido en la posicion $i: {$m[0]}"
            );
            $this->assertNotEmpty($m[1], "falta la tabla en la posicion $i");
            $this->assertNotEmpty($m[2], "falta el nombre en la posicion $i");

            if (in_array($m[0], $conSpec, true)) {
                $this->assertArrayHasKey(3, $m, "{$m[0]} '{$m[2]}' necesita spec");
                $this->assertNotEmpty($m[3], "{$m[0]} '{$m[2]}' tiene spec vacia");
            }
        }
    }

    /** Las columnas de un indice van entre parentesis o el ALTER sale mal formado. */
    public function testLosIndicesDeclaranSusColumnasEntreParentesis(): void
    {
        foreach ($this->migrations() as $m) {
            if ($m[0] === 'index' || $m[0] === 'unique') {
                $this->assertMatchesRegularExpression(
                    '/^\(.+\)$/',
                    trim($m[3]),
                    "el indice '{$m[2]}' debe declarar sus columnas como '(col_a, col_b)'"
                );
            }
        }
    }

    /**
     * El prefijo lo pone migrate.php desde _DB_PREFIX_. Si alguien lo escribe
     * aca, la migracion vuelve a quedar atada a una sola tienda — que es
     * exactamente el bug que este runner vino a eliminar.
     */
    public function testNingunaTablaTraeElPrefijoHardcodeado(): void
    {
        foreach ($this->migrations() as $m) {
            $this->assertStringStartsWith(
                'synkrop_',
                $m[1],
                "la tabla '{$m[1]}' parece traer prefijo; debe ir sin el"
            );
        }
    }
}

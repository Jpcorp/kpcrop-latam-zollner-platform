<?php

use PHPUnit\Framework\TestCase;

/**
 * getopt() corta en el primer argumento posicional: todo lo que venga despues
 * se descarta EN SILENCIO. Con las 3 tiendas del cliente sobre un mismo Bsale,
 * un cron escrito `sync.php stock --shop=2` sincronizaba la tienda 1 y dejaba
 * la fila de synkrop_log con id_shop=1 — desde el panel no se veia nada raro.
 *
 * Estos tests ejecutan el CLI de verdad. Funcionan porque el parseo de
 * argumentos corre ANTES del bootstrap de PrestaShop: en el repo no hay
 * config/config.inc.php, asi que un argumento valido llega hasta el error de
 * bootstrap y uno invalido muere antes. Esa diferencia es la asercion.
 */
class CliSyncArgsTest extends TestCase
{
    /** @return array{0:int,1:string} exit code y salida (stdout + stderr) */
    private function ejecutar(array $args): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg('synkrop/cli/sync.php');
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $descriptores = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proceso = proc_open($cmd . ' 2>&1', $descriptores, $tuberias, dirname(__DIR__));
        $this->assertIsResource($proceso, 'No se pudo lanzar el CLI');

        $salida = stream_get_contents($tuberias[1]);
        fclose($tuberias[1]);
        fclose($tuberias[2]);

        return [proc_close($proceso), $salida];
    }

    public function testOpcionDespuesDeLaEntidadFallaEnVozAlta(): void
    {
        list($codigo, $salida) = $this->ejecutar(['stock', '--shop=2']);

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('--shop=2', $salida);
        $this->assertStringContainsString('va antes de la entidad', $salida);
    }

    public function testDryRunDespuesDeLaEntidadTambienFalla(): void
    {
        list($codigo, $salida) = $this->ejecutar(['products', '--dry-run']);

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('va antes de la entidad', $salida);
    }

    public function testOpcionAntesDeLaEntidadPasaElParseo(): void
    {
        list($codigo, $salida) = $this->ejecutar(['--shop=2', 'stock']);

        // Solo se asierta que el PARSEO acepto la entrada. Nada sobre el exit code
        // ni sobre el error del bootstrap: eso depende de que en el repo no haya
        // PrestaShop instalado, un accidente del entorno (el docker compose del
        // paquete levanta uno) y no el invariante que cuida este archivo.
        $this->assertStringNotContainsString('va antes de la entidad', $salida);
        $this->assertStringNotContainsString('Entidad inválida', $salida);
    }

    public function testEntidadInvalidaSeRechaza(): void
    {
        list($codigo, $salida) = $this->ejecutar(['frutas']);

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('Entidad inválida', $salida);
    }
}

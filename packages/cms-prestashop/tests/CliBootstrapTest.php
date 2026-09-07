<?php

use PHPUnit\Framework\TestCase;

/**
 * Los CLI del modulo NO deben cargar init.php de PrestaShop.
 *
 * init.php instancia un FrontController y llama a init(), que en contexto CLI
 * puede terminar el proceso via Tools::redirect() (header + exit). El sintoma
 * es el peor posible: exit 0, sin salida, sin error. Un cron configurado asi
 * parece correr todos los dias y no hace absolutamente nada.
 *
 * Paso de verdad: los 3 CLI de este modulo estuvieron rotos asi en
 * strainmachine.com. Verificado el 07-sep-2026 ejecutando en el servidor:
 *
 *   config.inc.php OK -> FrontController instanciado -> init() nunca retorna
 *
 * Ninguno de estos scripts usa Context, Shop, Employee ni Tools, y
 * SynkropService tampoco: config.inc.php alcanza.
 */
class CliBootstrapTest extends TestCase
{
    private const CLI_DIR = __DIR__ . '/../synkrop/cli';

    /** @return string[] */
    private function scripts(): array
    {
        $encontrados = glob(self::CLI_DIR . '/*.php') ?: [];
        $this->assertNotEmpty($encontrados, 'No se encontro ningun CLI en synkrop/cli/');

        return $encontrados;
    }

    public function testNingunCliCargaInitPhp(): void
    {
        foreach ($this->scripts() as $ruta) {
            $codigo = file_get_contents($ruta);

            // Se ignoran los comentarios: el que explica por que NO se carga
            // menciona init.php a proposito.
            $sinComentarios = preg_replace('#^\s*(//|\*|/\*).*$#m', '', $codigo);

            $this->assertDoesNotMatchRegularExpression(
                '#require(_once)?\s*[^;]*init\.php#i',
                (string) $sinComentarios,
                basename($ruta) . ' carga init.php: en CLI eso puede matar el proceso '
                . 'con exit 0 y sin salida. Usa solo config.inc.php.'
            );
        }
    }

    /** config.inc.php si es necesario: sin el no hay Db ni constantes de PrestaShop. */
    public function testTodoCliCargaConfigIncPhp(): void
    {
        foreach ($this->scripts() as $ruta) {
            $this->assertMatchesRegularExpression(
                '#require(_once)?\s*[^;]*config\.inc\.php#i',
                (string) file_get_contents($ruta),
                basename($ruta) . ' no carga config.inc.php'
            );
        }
    }

    /** Un CLI accesible por web seria un endpoint sin autenticacion. */
    public function testTodoCliRechazaEjecucionPorWeb(): void
    {
        foreach ($this->scripts() as $ruta) {
            $this->assertStringContainsString(
                'PHP_SAPI',
                (string) file_get_contents($ruta),
                basename($ruta) . ' no verifica PHP_SAPI: seria ejecutable por HTTP'
            );
        }
    }
}

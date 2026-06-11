<?php
/**
 * Bootstrap para tests PHPUnit sin PrestaShop instalado.
 * Define los stubs mínimos de las clases y constantes de PS necesarias.
 */

define('_PS_VERSION_', '1.7.8.0');
define('_DB_PREFIX_', 'ps_');

function pSQL(string $str): string
{
    return addslashes($str);
}

// ── Stub de Db (Singleton con implementación reemplazable para tests) ─────────

class Db
{
    private static ?Db $instance = null;

    /** @var array<int, array> */
    public array $calls = [];

    /** Configura los valores que devolverán getValue/executeS/getRow */
    public array $queryResults = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = new self();
    }

    public function getValue(string $sql)
    {
        foreach ($this->queryResults as $pattern => $value) {
            if (strpos($sql, (string)$pattern) !== false) {
                return $value;
            }
        }
        return null;
    }

    public function getRow(string $sql)
    {
        foreach ($this->queryResults as $pattern => $value) {
            if (strpos($sql, (string)$pattern) !== false) {
                return is_array($value) ? $value : false;
            }
        }
        return false;
    }

    public function executeS(string $sql): array
    {
        foreach ($this->queryResults as $pattern => $value) {
            if (strpos($sql, (string)$pattern) !== false) {
                return is_array($value) ? $value : [];
            }
        }
        return [];
    }

    public function execute(string $sql): bool
    {
        $this->calls[] = ['method' => 'execute', 'sql' => $sql];
        return true;
    }

    public function insert(string $table, array $data): bool
    {
        $this->calls[] = ['method' => 'insert', 'table' => $table, 'data' => $data];
        return true;
    }

    public function update(string $table, array $data, string $where = ''): bool
    {
        $this->calls[] = ['method' => 'update', 'table' => $table, 'data' => $data, 'where' => $where];
        return true;
    }

    public function delete(string $table, string $where = ''): bool
    {
        $this->calls[] = ['method' => 'delete', 'table' => $table, 'where' => $where];
        return true;
    }

    public function countCalls(string $method, string $table = ''): int
    {
        return count(array_filter($this->calls, function ($c) use ($method, $table) {
            return $c['method'] === $method && ($table === '' || ($c['table'] ?? '') === $table);
        }));
    }

    public function getCalls(string $method): array
    {
        return array_values(array_filter($this->calls, function ($c) use ($method) {
            return $c['method'] === $method;
        }));
    }
}

// ── Stubs de clases PrestaShop usadas por los adapters ────────────────────────

class StockAvailable
{
    public static array $calls = [];

    public static function setQuantity(int $idProduct, int $idProductAttribute, int $quantity): void
    {
        self::$calls[] = compact('idProduct', 'idProductAttribute', 'quantity');
    }

    public static function reset(): void
    {
        self::$calls = [];
    }
}

class Product
{
    public ?int $id = null;
    public string $reference = '';
    public array $name = [];
    public array $description = [];
    public float $price = 0.0;
    public int $active = 1;
    public static array $added = [];
    public static array $updated = [];

    public function __construct(int $id = 0)
    {
        $this->id = $id ?: null;
    }

    public function add(): bool
    {
        $this->id = rand(100, 9999);
        self::$added[] = $this;
        return true;
    }

    public function update(): bool
    {
        self::$updated[] = $this;
        return true;
    }

    public static function reset(): void
    {
        self::$added = [];
        self::$updated = [];
    }
}

class Configuration
{
    public static function get(string $key)
    {
        if ($key === 'PS_LANG_DEFAULT') return 1;
        return null;
    }
}

// ── Autoload de clases del módulo ─────────────────────────────────────────────

require_once __DIR__ . '/../synkrop/classes/BsaleApiClient.php';
require_once __DIR__ . '/../synkrop/classes/LicenseClient.php';
require_once __DIR__ . '/../synkrop/classes/SynkropService.php';

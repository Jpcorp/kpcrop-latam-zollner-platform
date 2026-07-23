<?php
/**
 * Bootstrap para tests PHPUnit sin PrestaShop instalado.
 * Define los stubs mínimos de las clases y constantes de PS necesarias.
 */

define('_PS_VERSION_', '1.7.8.0');
define('_DB_PREFIX_', 'ps_');
define('_PS_MODULE_DIR_', __DIR__ . '/../../');
// #115: llave de prueba para TokenCipher — nunca la real de produccion.
define('_COOKIE_KEY_', 'clave_de_prueba_para_phpunit_no_es_la_real_1234567890');

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
                return $value instanceof \Closure ? $value($sql) : $value;
            }
        }
        // #115: GET_LOCK() (lock de sincronizacion en upsertVariant) tiene
        // exito por default en los tests — configurar
        // $db->queryResults['GET_LOCK'] = 0 explicitamente para simular que
        // otro proceso ya tiene el lock.
        if (strpos($sql, 'GET_LOCK') !== false) {
            return 1;
        }
        return null;
    }

    public function getRow(string $sql)
    {
        foreach ($this->queryResults as $pattern => $value) {
            if (strpos($sql, (string)$pattern) !== false) {
                $resolved = $value instanceof \Closure ? $value($sql) : $value;
                return is_array($resolved) ? $resolved : false;
            }
        }
        return false;
    }

    public function executeS(string $sql): array
    {
        foreach ($this->queryResults as $pattern => $value) {
            if (strpos($sql, (string)$pattern) !== false) {
                $resolved = $value instanceof \Closure ? $value($sql) : $value;
                return is_array($resolved) ? $resolved : [];
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
    public int $id_category_default = 0;
    public array $link_rewrite = [];
    public int $id_tax_rules_group = 0;
    public int $minimal_quantity = 1;
    public int $show_price = 1;
    public int $is_virtual = 0;
    public int $state = 1;
    public string $visibility = 'both';
    public int $available_for_order = 1;
    public string $condition = 'new';
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

    public function addToCategories(array $categoryIds): bool
    {
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
    /** @var array<string, mixed> configurar antes del test para sobreescribir un valor */
    public static array $values = [];

    public static function get(string $key)
    {
        if (array_key_exists($key, self::$values)) return self::$values[$key];
        if ($key === 'PS_LANG_DEFAULT') return 1;
        return null;
    }

    public static function reset(): void
    {
        self::$values = [];
    }
}

// #87: stub de categoria PS (sync de categorias Bsale -> PS)
class Category
{
    public $id;
    public $id_parent = 0;
    public $active = 1;
    public array $name = [];
    public array $link_rewrite = [];

    public static array $added = [];

    public function add(): bool
    {
        $this->id = rand(1000, 9999);
        self::$added[] = $this;
        return true;
    }

    public static function reset(): void
    {
        self::$added = [];
    }
}

class Tools
{
    public static function link_rewrite(string $name): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
        return $slug !== '' ? $slug : 'produit';
    }
}

// #128: stubs para OrderDocumentService::notifyDocumentEmitted()

class Order
{
    public $id;
    public $id_customer = 0;
    public $id_lang = 1;

    /** @var array<int, array{id_customer?:int, id_lang?:int}> configurar antes de instanciar */
    public static array $fixtures = [];

    public function __construct(int $id = 0)
    {
        // Sin fixture registrada = el pedido no existe (Order no cargado) —
        // simetrico a PrestaShop real (Order($id) con $id inexistente en BD).
        if ($id && isset(self::$fixtures[$id])) {
            $this->id           = $id;
            $this->id_customer  = self::$fixtures[$id]['id_customer'] ?? 999;
            $this->id_lang      = self::$fixtures[$id]['id_lang'] ?? 1;
        } else {
            $this->id = null;
        }
    }

    public static function reset(): void
    {
        self::$fixtures = [];
    }
}

class Customer
{
    public $id;
    public $email = '';
    public $firstname = '';
    public $lastname = '';

    /** @var array<int, array{email?:string, firstname?:string, lastname?:string}> */
    public static array $fixtures = [];

    public function __construct(int $id = 0)
    {
        $this->id = $id ?: null;
        $f = self::$fixtures[$id] ?? ($id ? ['email' => 'cliente@test.cl', 'firstname' => 'Juan', 'lastname' => 'Perez'] : null);
        if ($f !== null) {
            $this->email     = $f['email'] ?? '';
            $this->firstname = $f['firstname'] ?? '';
            $this->lastname  = $f['lastname'] ?? '';
        }
    }

    public static function reset(): void
    {
        self::$fixtures = [];
    }
}

class Validate
{
    public static function isLoadedObject($object): bool
    {
        return isset($object->id) && (int)$object->id > 0;
    }
}

class Mail
{
    /** @var array<int, array> */
    public static array $calls = [];
    public static bool $returnValue = true;

    public static function Send(...$args): bool
    {
        self::$calls[] = $args;
        return self::$returnValue;
    }

    public static function reset(): void
    {
        self::$calls = [];
        self::$returnValue = true;
    }
}

// ── Autoload de clases del módulo ─────────────────────────────────────────────

require_once __DIR__ . '/../synkrop/classes/BsaleApiClient.php';
require_once __DIR__ . '/../synkrop/classes/LicenseClient.php';
require_once __DIR__ . '/../synkrop/classes/SynkropService.php';
require_once __DIR__ . '/../synkrop/classes/OrderDocumentService.php';
require_once __DIR__ . '/../synkrop/classes/TokenCipher.php';

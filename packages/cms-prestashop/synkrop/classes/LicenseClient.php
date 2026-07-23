<?php
/**
 * Valida la licencia del tenant contra bot-miki.
 * Cachea el JWT por 4 minutos para no bloquear cada operacion en la red.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class LicenseClient
{
    private $daemonUrl;
    private $apiKey;

    public function __construct(string $daemonUrl, string $apiKey)
    {
        $this->daemonUrl = rtrim($daemonUrl, '/');
        $this->apiKey    = $apiKey;
    }

    /**
     * Devuelve el JWT de licencia. Usa cache local (tabla synkrop_config) si no expiro.
     * @throws LicenseException Si la licencia no es valida o esta expirada
     */
    public function getToken(): string
    {
        $cached = $this->getCachedJwt();
        if ($cached !== null) {
            return $cached;
        }

        return $this->fetchAndCacheToken();
    }

    /**
     * Verifica rapidamente si la licencia es valida sin lanzar excepcion.
     */
    public function isValid(): bool
    {
        try {
            $this->getToken();
            return true;
        } catch (LicenseException $e) {
            return false;
        }
    }

    private function getCachedJwt()
    {
        $config = Db::getInstance()->getRow(
            'SELECT license_jwt, license_jwt_expires FROM `' . _DB_PREFIX_ . 'synkrop_config`
             WHERE id_shop = ' . (int)Context::getContext()->shop->id
        );

        if (!$config || empty($config['license_jwt'])) {
            return null;
        }

        $expires = strtotime($config['license_jwt_expires']);
        if ($expires < time() + 60) { // Renovar 60s antes de expirar
            return null;
        }

        return $config['license_jwt'];
    }

    private function fetchAndCacheToken(): string
    {
        $url = $this->daemonUrl . '/v1/license/token';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_HTTPHEADER      => ['X-API-Key: ' . $this->apiKey],
            CURLOPT_TIMEOUT         => 15,
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_USERAGENT       => 'Synkrop/1.0 PrestaShop',
        ]);

        $body      = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlErrno !== 0) {
            throw new LicenseException("No se pudo conectar al servidor de licencias: {$curlError} (errno {$curlErrno})", 0);
        }

        if ($httpCode === 402) {
            throw new LicenseException('Licencia expirada o suspendida. Renueva en kpcrop.com/billing', 402);
        }

        if ($httpCode !== 200) {
            throw new LicenseException("Error al validar licencia (HTTP {$httpCode})", $httpCode);
        }

        $data = json_decode($body, true);
        if (empty($data['token'])) {
            throw new LicenseException('Respuesta invalida del servidor de licencias', 500);
        }

        // Guardar JWT en la tabla de config del modulo (Db::update auto-agrega _DB_PREFIX_)
        Db::getInstance()->update(
            'synkrop_config',
            [
                'license_jwt'         => pSQL($data['token']),
                'license_jwt_expires' => pSQL(self::formatExpiresAt($data['expiresAt'])),
            ],
            'id_shop = ' . (int)Context::getContext()->shop->id
        );

        return $data['token'];
    }

    /**
     * #115: license_jwt_expires es DATETIME — bot-miki manda expiresAt en
     * ISO 8601 (ej. "2026-07-23T08:36:58.123Z"), formato que MySQL rechaza
     * directo en una columna DATETIME (ERROR 1292: Incorrect datetime value,
     * verificado). El UPDATE fallaba en silencio (no se chequeaba el
     * resultado) y el cache de 4 minutos nunca llegaba a guardarse — cada
     * operacion terminaba re-validando la licencia contra bot-miki en vivo.
     */
    public static function formatExpiresAt(string $iso): string
    {
        return (new DateTime($iso))->format('Y-m-d H:i:s');
    }
}

class LicenseException extends RuntimeException
{
    public function __construct(string $message, int $code = 0)
    {
        parent::__construct($message, $code);
    }

    public function isExpired(): bool { return $this->getCode() === 402; }
}

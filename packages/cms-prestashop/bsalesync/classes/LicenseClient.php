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
    private string $daemonUrl;
    private string $apiKey;
    private string $tenantId;

    public function __construct(string $daemonUrl, string $apiKey, string $tenantId)
    {
        $this->daemonUrl = rtrim($daemonUrl, '/');
        $this->apiKey    = $apiKey;
        $this->tenantId  = $tenantId;
    }

    /**
     * Devuelve el JWT de licencia. Usa cache local (tabla bsalesync_config) si no expiro.
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

    private function getCachedJwt(): ?string
    {
        $config = Db::getInstance()->getRow(
            'SELECT license_jwt, license_jwt_expires FROM `' . _DB_PREFIX_ . 'bsalesync_config`
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
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['X-API-Key: ' . $this->apiKey],
            CURLOPT_TIMEOUT        => 10,
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

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
            'bsalesync_config',
            [
                'license_jwt'         => pSQL($data['token']),
                'license_jwt_expires' => pSQL($data['expiresAt']),
            ],
            'id_shop = ' . (int)Context::getContext()->shop->id
        );

        return $data['token'];
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

<?php
/**
 * Cliente HTTP para la API de Bsale.
 * Rate limit conservador: 10 req/s hasta confirmar el limite real (ver ADR-004).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class BsaleApiClient
{
    private const BASE_URL = 'https://api.bsale.io';
    private const RATE_LIMIT_MS = 100; // 10 req/s = 1 req cada 100ms

    private $accessToken;
    private $lastRequestMs = 0;

    public function __construct(string $accessToken)
    {
        $this->accessToken = $accessToken;
    }

    /**
     * GET paginado — itera automaticamente todas las paginas.
     * @return array Lista completa de items de todas las paginas
     */
    public function getAll(string $path, array $params = []): array
    {
        $items = [];
        $params['limit'] = 50;
        $params['offset'] = 0;

        do {
            $response = $this->get($path, $params);
            $items = array_merge($items, $response['items'] ?? []);
            $params['offset'] += $params['limit'];
        } while (count($response['items'] ?? []) === $params['limit']);

        return $items;
    }

    /**
     * GET simple — devuelve la respuesta cruda de Bsale.
     */
    public function get(string $path, array $params = []): array
    {
        $this->throttle();

        $url = self::BASE_URL . $path;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'access_token: ' . $this->accessToken,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 429) {
            sleep(60); // Sin header Retry-After documentado — esperar 60s
            return $this->get($path, $params);
        }

        if ($httpCode >= 400) {
            throw new BsaleApiException($httpCode, $body);
        }

        return json_decode($body, true) ?? [];
    }

    /**
     * POST — crea un recurso en Bsale (ej: documents.json). Espera HTTP 200/201.
     */
    public function post(string $path, array $data): array
    {
        $this->throttle();

        $ch = curl_init(self::BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => [
                'access_token: ' . $this->accessToken,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 429) {
            sleep(60);
            return $this->post($path, $data);
        }

        if ($httpCode >= 400 || $httpCode === 0) {
            throw new BsaleApiException($httpCode, (string)$body);
        }

        return json_decode($body, true) ?? [];
    }

    /**
     * DELETE — elimina/anula un recurso en Bsale. Espera HTTP 200.
     */
    public function delete(string $path): array
    {
        $this->throttle();

        $ch = curl_init(self::BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_HTTPHEADER     => [
                'access_token: ' . $this->accessToken,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 429) {
            sleep(60);
            return $this->delete($path);
        }

        if ($httpCode >= 400 || $httpCode === 0) {
            throw new BsaleApiException($httpCode, (string)$body);
        }

        return json_decode($body, true) ?? [];
    }

    private function throttle()
    {
        $now = (int)(microtime(true) * 1000);
        $elapsed = $now - $this->lastRequestMs;
        if ($elapsed < self::RATE_LIMIT_MS) {
            usleep(($elapsed - self::RATE_LIMIT_MS) * -1000);
        }
        $this->lastRequestMs = (int)(microtime(true) * 1000);
    }
}

class BsaleApiException extends RuntimeException
{
    public function __construct(int $httpCode, string $body)
    {
        parent::__construct("Bsale API {$httpCode}: {$body}", $httpCode);
    }

    public function isClientError(): bool { return $this->getCode() >= 400 && $this->getCode() < 500; }
    public function isServerError(): bool { return $this->getCode() >= 500; }
}

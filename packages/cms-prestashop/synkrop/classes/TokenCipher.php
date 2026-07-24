<?php
/**
 * Cifrado en reposo del token de acceso a Bsale (#115).
 * Extraida de Synkrop (que extiende Module) para poder testearla sin
 * depender del framework de PrestaShop — mismo patron que BsaleApiClient/
 * LicenseClient/SynkropService.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class TokenCipher
{
    // AES-256-CBC sin MAC es cifrado maleable, sin integridad — se migro a
    // AES-256-GCM (autenticado, mismo criterio que ya usa bot-miki para
    // bsale_access_token, ver #107). El prefijo "gcm1$" distingue el formato
    // nuevo del legado — SIN migracion forzada: los tokens ya cifrados en
    // CBC siguen descifrando bien (ver decrypt()), y se re-cifran solos a
    // GCM la proxima vez que se guarden desde el admin. Esto evita romper
    // el token en produccion (strainmachine.com) con un cambio de formato
    // de un dia para el otro.
    private const GCM_PREFIX = 'gcm1$';

    public static function encrypt(string $token): string
    {
        $key = substr(_COOKIE_KEY_, 0, 32);
        $iv  = openssl_random_pseudo_bytes(12); // 12 bytes recomendado para GCM
        $tag = '';
        $ciphertext = openssl_encrypt($token, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return self::GCM_PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $encrypted): string
    {
        $key = substr(_COOKIE_KEY_, 0, 32);

        if (strpos($encrypted, self::GCM_PREFIX) === 0) {
            $raw = base64_decode(substr($encrypted, strlen(self::GCM_PREFIX)));
            $iv         = substr($raw, 0, 12);
            $tag        = substr($raw, 12, 16);
            $ciphertext = substr($raw, 28);
            return openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag) ?: '';
        }

        // Formato legado AES-256-CBC (sin prefijo) — retrocompatibilidad.
        $data = base64_decode($encrypted);
        $parts = explode('::', $data, 2);
        $iv = $parts[0] ?? '';
        $ciphertext = $parts[1] ?? '';
        return openssl_decrypt($ciphertext, 'AES-256-CBC', $key, 0, $iv) ?: '';
    }
}

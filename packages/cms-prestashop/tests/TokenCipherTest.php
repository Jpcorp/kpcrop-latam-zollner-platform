<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests unitarios para TokenCipher (#115).
 * Cubre el nuevo formato AES-256-GCM y la retrocompatibilidad con el
 * formato legado AES-256-CBC (tokens ya cifrados en produccion antes de
 * este cambio no deben dejar de descifrar).
 */
class TokenCipherTest extends TestCase
{
    // ── Formato nuevo (GCM) ─────────────────────────────────────────────────

    public function test_round_trip_gcm(): void
    {
        $original = 'bsale_access_token_secreto_xyz123';
        $encrypted = TokenCipher::encrypt($original);
        $this->assertSame($original, TokenCipher::decrypt($encrypted));
    }

    public function test_encrypted_value_never_contains_plaintext(): void
    {
        $original = 'token_bsale_muy_identificable_12345';
        $encrypted = TokenCipher::encrypt($original);
        $this->assertStringNotContainsString($original, $encrypted);
    }

    public function test_encrypting_same_value_twice_gives_different_output(): void
    {
        $original = 'mismo-token';
        $a = TokenCipher::encrypt($original);
        $b = TokenCipher::encrypt($original);
        $this->assertNotSame($a, $b); // IV aleatorio
        $this->assertSame($original, TokenCipher::decrypt($a));
        $this->assertSame($original, TokenCipher::decrypt($b));
    }

    public function test_new_format_has_gcm_prefix(): void
    {
        $encrypted = TokenCipher::encrypt('token');
        $this->assertStringStartsWith('gcm1$', $encrypted);
    }

    public function test_tampered_gcm_ciphertext_fails_to_decrypt(): void
    {
        $encrypted = TokenCipher::encrypt('token-original');
        // Corrompe el ultimo byte del payload cifrado (despues del prefijo)
        $payload = substr($encrypted, 5);
        $raw = base64_decode($payload);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0xFF);
        $tampered = 'gcm1$' . base64_encode($raw);

        // GCM autenticado: un tamper debe fallar (string vacio), no devolver basura
        $this->assertSame('', TokenCipher::decrypt($tampered));
    }

    // ── Retrocompatibilidad con el formato legado (AES-256-CBC) ────────────
    // Simula un token cifrado ANTES de este cambio, con el algoritmo viejo,
    // para confirmar que decrypt() lo sigue leyendo bien sin migracion forzada.

    private function legacyEncrypt(string $token): string
    {
        $key = substr(_COOKIE_KEY_, 0, 32);
        $iv  = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($token, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . '::' . $encrypted);
    }

    public function test_decrypts_legacy_cbc_format_correctly(): void
    {
        $original = 'token_cifrado_con_el_metodo_viejo';
        $legacyEncrypted = $this->legacyEncrypt($original);

        $this->assertSame($original, TokenCipher::decrypt($legacyEncrypted));
    }

    public function test_legacy_format_has_no_gcm_prefix(): void
    {
        $legacyEncrypted = $this->legacyEncrypt('token');
        $this->assertStringStartsNotWith('gcm1$', $legacyEncrypted);
    }
}

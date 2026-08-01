<?php
defined('ABSPATH') || exit;

/**
 * WCA_Transient_Store - Encrypted WordPress transient wrapper.
 *
 * Payloads are serialized to JSON and encrypted with AES-256-CBC before storage.
 * The encryption key is derived from WordPress's AUTH_KEY constant.
 */
class WCA_Transient_Store
{

    private const CIPHER = 'aes-256-cbc';

    // --- set --------------------------------------------------------------

    /**
     * Store an encrypted payload as a WordPress transient.
     *
     * @param string $key     Full transient key (e.g. "wca_pending_{token}").
     * @param array  $data    Payload to encrypt and store.
     * @param int    $ttl     TTL in seconds.
     *
     * @return bool
     */
    public function set(string $key, array $data, int $ttl): bool
    {
        $json = wp_json_encode($data);
        $encrypted = $this->encrypt($json);

        if ($encrypted === false) {
            return false;
        }

        return set_transient($key, $encrypted, $ttl);
    }

    // --- get --------------------------------------------------------------

    /**
     * Retrieve and decrypt a transient payload.
     *
     * @param string $key  Full transient key or session token.
     *
     * @return array|false  Decrypted payload array, or false if expired/missing.
     */
    public function get(string $key): array|false
    {
        // Accept either a full key or a raw session token.
        $transient_key = $this->resolve_key($key);
        $encrypted = get_transient($transient_key);

        if ($encrypted === false) {
            return false;
        }

        $json = $this->decrypt($encrypted);

        if ($json === false) {
            return false;
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : false;
    }

    // --- delete -----------------------------------------------------------

    /**
     * Delete a transient.
     *
     * @param string $key  Full transient key or session token.
     *
     * @return bool
     */
    public function delete(string $key): bool
    {
        return delete_transient($this->resolve_key($key));
    }

    // --- Key resolution ---------------------------------------------------

    /**
     * If the key already starts with "wca_", use it directly.
     * Otherwise treat it as a session token and prefix accordingly.
     */
    private function resolve_key(string $key): string
    {
        if (str_starts_with($key, 'wca_')) {
            return $key;
        }
        // Default: registration session token.
        return WCA_Constants::transient_registration($key);
    }

    // --- Encryption -------------------------------------------------------

    private function encrypt(string $plaintext): string|false
    {
        $key = WCA_Constants::encryption_key();
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(self::CIPHER));

        $encrypted = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            return false;
        }

        // Prepend IV to the ciphertext so we can extract it on decryption.
        return base64_encode($iv . $encrypted);
    }

    // --- Decryption -------------------------------------------------------

    private function decrypt(string $ciphertext_b64): string|false
    {
        $key = WCA_Constants::encryption_key();
        $decoded = base64_decode($ciphertext_b64, true);
        $iv_length = openssl_cipher_iv_length(self::CIPHER);

        if (strlen($decoded) <= $iv_length) {
            return false;
        }

        $iv = substr($decoded, 0, $iv_length);
        $ciphertext = substr($decoded, $iv_length);

        return openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
    }
}

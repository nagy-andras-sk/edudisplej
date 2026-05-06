<?php
/**
 * Security configuration and symmetric encryption helpers.
 * EduDisplej Control Panel
 *
 * encrypt_data / decrypt_data are used to protect short-lived in-session tokens
 * (e.g. the OTP-pending token written during two-factor authentication).
 *
 * The encryption key is read from the EDUDISPLEJ_ENCRYPT_KEY environment variable.
 * If that variable is not set, a key is derived from the database credentials that
 * are already defined by dbkonfiguracia.php (which must be loaded first).
 *
 * To set a strong, stable key on a cPanel host:
 *   Add "SetEnv EDUDISPLEJ_ENCRYPT_KEY your_random_64_char_hex_string" to .htaccess
 */

define('EDUDISPLEJ_ENCRYPT_CIPHER', 'AES-256-CBC');

function edudisplej_get_encryption_key(): string {
    $env_key = getenv('EDUDISPLEJ_ENCRYPT_KEY');
    if ($env_key !== false && trim($env_key) !== '') {
        return substr(hash('sha256', trim($env_key), true), 0, 32);
    }

    // Fallback: derive a stable key from the DB credentials already defined in
    // dbkonfiguracia.php. This ensures the key is unique per installation without
    // requiring manual configuration.
    $seed = (defined('DB_HOST') ? DB_HOST : '') . ':' .
            (defined('DB_USER') ? DB_USER : '') . ':' .
            (defined('DB_NAME') ? DB_NAME : '') . ':edudisplej_enc_v1';

    return substr(hash('sha256', $seed, true), 0, 32);
}

/**
 * Encrypt a string using AES-256-CBC.
 *
 * @param  string $data  Plain-text string to encrypt
 * @return string        Base64-encoded IV + ciphertext
 * @throws RuntimeException on OpenSSL failure
 */
function encrypt_data(string $data): string {
    $cipher = EDUDISPLEJ_ENCRYPT_CIPHER;
    $key    = edudisplej_get_encryption_key();
    $iv_len = openssl_cipher_iv_length($cipher);
    $iv     = random_bytes($iv_len);

    $encrypted = openssl_encrypt($data, $cipher, $key, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) {
        throw new RuntimeException('encrypt_data: OpenSSL encryption failed.');
    }

    return base64_encode($iv . $encrypted);
}

/**
 * Decrypt a string that was produced by encrypt_data().
 *
 * @param  string $data  Base64-encoded IV + ciphertext
 * @return string        Recovered plain-text
 * @throws RuntimeException on decryption failure
 */
function decrypt_data(string $data): string {
    $cipher = EDUDISPLEJ_ENCRYPT_CIPHER;
    $key    = edudisplej_get_encryption_key();

    $raw = base64_decode($data, true);
    if ($raw === false) {
        throw new RuntimeException('decrypt_data: invalid base64 input.');
    }

    $iv_len = openssl_cipher_iv_length($cipher);
    if (strlen($raw) <= $iv_len) {
        throw new RuntimeException('decrypt_data: payload too short.');
    }

    $iv         = substr($raw, 0, $iv_len);
    $ciphertext = substr($raw, $iv_len);
    $decrypted  = openssl_decrypt($ciphertext, $cipher, $key, OPENSSL_RAW_DATA, $iv);

    if ($decrypted === false) {
        throw new RuntimeException('decrypt_data: OpenSSL decryption failed.');
    }

    return $decrypted;
}

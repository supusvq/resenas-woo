<?php
/**
 * Cifrado AES-256-GCM de la clave de licencia de SupuHub.
 *
 * La clave de cifrado se deriva de las salts de wp-config.php con un contexto
 * propio del plugin: no depende de archivos externos y es estable por sitio.
 */

namespace MRG\License;

if (!defined('ABSPATH')) {
    exit;
}

class Crypto
{
    /**
     * True si el sitio puede cifrar (salts definidas y OpenSSL disponible).
     */
    public static function can_encrypt()
    {
        return defined('AUTH_KEY') && '' !== AUTH_KEY
            && defined('SECURE_AUTH_KEY') && '' !== SECURE_AUTH_KEY
            && function_exists('openssl_encrypt');
    }

    private static function key()
    {
        return hash('sha256', AUTH_KEY . '|' . SECURE_AUTH_KEY . '|resenaswoo', true);
    }

    /**
     * Cifra un secreto. Devuelve enc, iv y tag en base64.
     *
     * @throws \RuntimeException Si no se puede cifrar.
     */
    public static function encrypt($secret)
    {
        if (!self::can_encrypt()) {
            throw new \RuntimeException(__('No se puede guardar la licencia de forma segura: faltan las claves de seguridad de wp-config.php o la extensión OpenSSL de PHP.', 'mis-resenas-de-google'));
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt((string) $secret, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        if (false === $ciphertext || '' === $tag) {
            throw new \RuntimeException(__('No se pudo cifrar la licencia.', 'mis-resenas-de-google'));
        }

        return [
            'enc' => base64_encode($ciphertext),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
        ];
    }

    /**
     * Descifra un secreto. Devuelve null si no se puede (salts cambiadas, datos corruptos...).
     */
    public static function decrypt($enc, $iv, $tag)
    {
        if (!self::can_encrypt() || !$enc || !$iv || !$tag) {
            return null;
        }

        $raw_cipher = base64_decode((string) $enc, true);
        $raw_iv = base64_decode((string) $iv, true);
        $raw_tag = base64_decode((string) $tag, true);

        if (false === $raw_cipher || false === $raw_iv || false === $raw_tag) {
            return null;
        }

        $plain = openssl_decrypt($raw_cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $raw_iv, $raw_tag);

        return (false === $plain) ? null : $plain;
    }
}

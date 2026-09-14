<?php

class cCrypt {
    private const CIPHER    = 'aes-256-gcm';
    private const MAGIC     = 'v2';   // Praefix kennzeichnet GCM; ohne Praefix ist es Altbestand in CBC.
    private const IV_LEN    = 12;
    private const TAG_LEN   = 16;

    public function encryptIt($q, $cryptKey) {
        $key = $this->deriveKey($cryptKey);

        $iv = openssl_random_pseudo_bytes(self::IV_LEN, $strong);
        if ($iv === false || $strong !== true) {
            return false;
        }

        $tag       = '';
        $encrypted = openssl_encrypt((string) $q, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN);
        if ($encrypted === false) {
            return false;
        }

        return base64_encode(self::MAGIC . $iv . $tag . $encrypted);
    }

    public function decryptIt($q, $cryptKey) {
        $raw = base64_decode((string) $q, true);
        if ($raw === false || $raw === '') {
            return false;
        }

        if (!str_starts_with($raw, self::MAGIC)) {
            return $this->decryptLegacyCbc($raw, $cryptKey);
        }

        $offset = strlen(self::MAGIC);
        if (strlen($raw) < $offset + self::IV_LEN + self::TAG_LEN) {
            return false;
        }

        $iv         = substr($raw, $offset, self::IV_LEN);
        $tag        = substr($raw, $offset + self::IV_LEN, self::TAG_LEN);
        $ciphertext = substr($raw, $offset + self::IV_LEN + self::TAG_LEN);

        return openssl_decrypt($ciphertext, self::CIPHER, $this->deriveKey($cryptKey), OPENSSL_RAW_DATA, $iv, $tag);
    }

    // Liest das alte Format "ciphertext::iv" von vor der Umstellung auf AES-GCM.
    private function decryptLegacyCbc(string $raw, $cryptKey) {
        $parts = explode('::', $raw, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$encrypted, $iv] = $parts;
        if (strlen($iv) !== (int) openssl_cipher_iv_length('aes-256-cbc')) {
            return false;
        }

        return openssl_decrypt($encrypted, 'aes-256-cbc', $cryptKey, 0, $iv);
    }

    private function deriveKey($cryptKey): string {
        return hash('sha256', (string) $cryptKey, true);
    }
}

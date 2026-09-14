<?php

class cWeb {
    private $defaultUserAgent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36";

    public ?int    $lastHttpCode = null;
    public ?string $lastError    = null;

    private ?string $mailerUrl = null;

    public function __construct(?string $mailerUrl = null) {
        $url = $mailerUrl
            ?? (defined('CONFIG') ? (CONFIG['server']['mailer_url'] ?? null) : null);

        $this->mailerUrl = ($url === null || trim($url) === '') ? null : trim($url);
    }

    public function sendmail($destMail, $subject, $msg, $sender = "pve") {
        if ($this->mailerUrl === null) {
            $this->lastError = 'cWeb: keine mailer_url in der Konfiguration';
            return false;
        }

        $data = [
            "sender"   => $sender,
            "subject"  => $subject,
            "message"  => $msg,
            "DestMail" => $destMail
        ];
        return $this->request($this->mailerUrl, [
            'method' => 'POST',
            'json'   => $data
        ]);
    }

    public function request($url, $options = []) {
        $method  = strtoupper($options['method'] ?? 'GET');
        $postVar = $options['data'] ?? null;
        $jsonVar = $options['json'] ?? null;
        $timeout = $options['timeout'] ?? 30;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->defaultUserAgent);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        $headers = $options['headers'] ?? [];

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($jsonVar) {
                $payload = json_encode($jsonVar);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                $headers[] = 'Content-Type: application/json';
                $headers[] = 'Content-Length: ' . strlen($payload);
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postVar);
            }
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->lastHttpCode = $httpCode ? (int) $httpCode : null;
        $this->lastError    = $error ?: null;

        if ($error) {
            return false;
        }

        if (($options['fail_on_error'] ?? false) && $this->lastHttpCode >= 400) {
            $this->lastError = 'HTTP ' . $this->lastHttpCode;
            return false;
        }

        return $response;
    }

    public function download(
        string  $fileUrl,
        string  $destPath,
        ?string $expectedChecksum = null,
        ?string $algo             = 'sha256',
        ?string $checksumUrl      = null,
        int     $timeout          = 300,
        bool    $verifyChecksum   = true
    ): array {
        $algo = strtolower($algo ?? 'sha256');

        $dir = dirname($destPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            return $this->downloadResult(false, error: "Cannot create directory: $dir");
        }

        $fh = fopen($destPath, 'wb');
        if ($fh === false) {
            return $this->downloadResult(false, error: "Cannot open file for writing: $destPath");
        }

        $ch = curl_init($fileUrl);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => $this->defaultUserAgent,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FAILONERROR    => true,
        ]);

        $ok    = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fh);

        if (!$ok || $errno) {
            @unlink($destPath);
            return $this->downloadResult(
                false,
                error: "cURL error ($errno / HTTP $code): $error"
            );
        }

        if (!file_exists($destPath) || filesize($destPath) === 0) {
            return $this->downloadResult(false, error: "Downloaded file is empty or missing.");
        }

        if (!$verifyChecksum) {
            return $this->downloadResult(
                true,
                path:             $destPath,
                algo:             null,
                checksumVerified: false
            );
        }

        if ($expectedChecksum === null) {
            $expectedChecksum = $this->resolveChecksum($fileUrl, $checksumUrl, $algo);
        }

        if ($expectedChecksum !== null) {
            $actualHash = hash_file($algo, $destPath);

            $expectedNorm = strtolower(trim(preg_split('/\s+/', trim($expectedChecksum))[0]));

            $match = ($actualHash === $expectedNorm);

            if (!$match) {
                @unlink($destPath);
                return $this->downloadResult(
                    false,
                    path:              $destPath,
                    algo:              $algo,
                    checksumVerified:  true,
                    checksumMatch:     false,
                    expected:          $expectedNorm,
                    actual:            $actualHash,
                    error:             "Checksum mismatch – file deleted."
                );
            }

            return $this->downloadResult(
                true,
                path:             $destPath,
                algo:             $algo,
                checksumVerified: true,
                checksumMatch:    true,
                expected:         $expectedNorm,
                actual:           $actualHash
            );
        }

        return $this->downloadResult(
            true,
            path:             $destPath,
            algo:             $algo,
            checksumVerified: false
        );
    }

    private function resolveChecksum(string $fileUrl, ?string $checksumUrl, string &$algo): ?string {
        if ($checksumUrl !== null) {
            $raw  = $this->request($checksumUrl, ['timeout' => 15]);
            $hash = $this->extractHash($raw, $algo);
            return $hash;
        }

        $probes = [
            'sha256'    => ['sha256', 'sha256sum'],
            'sha1'      => ['sha1',   'sha1sum'],
            'md5'       => ['md5',    'md5sum'],
        ];

        foreach ($probes as $probeAlgo => $extensions) {
            foreach ($extensions as $ext) {
                $raw  = $this->request("$fileUrl.$ext", ['timeout' => 10]);
                $hash = $this->extractHash($raw, $probeAlgo);
                if ($hash !== null) {
                    $algo = $probeAlgo;
                    return $hash;
                }
            }
        }

        return null;
    }

    private function extractHash($raw, string $algo): ?string {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $token = strtolower(preg_split('/\s+/', trim($raw))[0]);

        $len = match (strtolower($algo)) {
            'md5'  => 32,
            'sha1' => 40,
            default => 64,
        };

        return preg_match('/^[0-9a-f]{' . $len . '}$/', $token) ? $token : null;
    }

    private function downloadResult(
        bool    $success,
        ?string $path             = null,
        ?string $algo             = null,
        bool    $checksumVerified = false,
        ?bool   $checksumMatch    = null,
        ?string $expected         = null,
        ?string $actual           = null,
        ?string $error            = null
    ): array {
        return [
            'success'           => $success,
            'path'              => $path,
            'algo'              => $algo,
            'checksum_verified' => $checksumVerified,
            'checksum_match'    => $checksumMatch,
            'expected'          => $expected,
            'actual'            => $actual,
            'error'             => $error,
        ];
    }

    public function url_get_contents($url, $postVar = null) {
        return $this->request($url, [
            'method' => $postVar ? 'POST' : 'GET',
            'data'   => $postVar
        ]);
    }
}

<?php

namespace DriveSnap;

use RuntimeException;

class EncryptedFile
{
    private const MAGIC = "DRIVESNAP1\n";

    private const CHUNK = 65536;

    public function encrypt(string $source, string $destination): void
    {
        $input = @fopen($source, 'rb');
        if ($input === false) {
            throw new RuntimeException('Cannot open the snapshot.');
        }
        $output = @fopen($destination, 'xb');
        if ($output === false) {
            fclose($input);
            throw new RuntimeException('Cannot create a new archive.');
        }
        chmod($destination, 0600);
        $complete = false;
        try {
            $key = $this->keys()[0];
            $id = substr(hash('sha256', $key, true), 0, 8);
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            $this->write($output, self::MAGIC.$id.$header);
            do {
                $plain = fread($input, self::CHUNK);
                if ($plain === false) {
                    throw new RuntimeException('Cannot read snapshot.');
                }
                $final = feof($input);
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plain, self::MAGIC.$id, $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
                $this->write($output, pack('N', strlen($cipher)).$cipher);
            } while (! $final);
            $complete = true;
        } finally {
            fclose($input);
            fclose($output);
            if (! $complete && is_file($destination)) {
                unlink($destination);
            }
        }
    }

    public function decrypt(string $source, string $destination): void
    {
        $input = @fopen($source, 'rb');
        if ($input === false) {
            throw new RuntimeException('Cannot open the archive.');
        }
        $output = @fopen($destination, 'xb');
        if ($output === false) {
            fclose($input);
            throw new RuntimeException('Cannot create a new restore file.');
        }
        chmod($destination, 0600);
        $complete = false;
        try {
            $magic = fread($input, strlen(self::MAGIC));
            $id = fread($input, 8);
            $header = fread($input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if ($magic !== self::MAGIC || strlen($id) !== 8 || strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new RuntimeException('Invalid DriveSnap archive.');
            }
            $key = null;
            foreach ($this->keys() as $candidate) {
                if (hash_equals($id, substr(hash('sha256', $candidate, true), 0, 8))) {
                    $key = $candidate;
                    break;
                }
            }
            if ($key === null) {
                throw new RuntimeException('The archive encryption key is unavailable.');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            while (! feof($input)) {
                $size = fread($input, 4);
                if (strlen($size) !== 4) {
                    throw new RuntimeException('Truncated archive.');
                }
                $length = unpack('Nlength', $size)['length'];
                if ($length < 17 || $length > self::CHUNK + 17) {
                    throw new RuntimeException('Invalid archive block.');
                }
                $cipher = fread($input, $length);
                if (strlen($cipher) !== $length) {
                    throw new RuntimeException('Truncated archive block.');
                }
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher, self::MAGIC.$id);
                if ($result === false) {
                    throw new RuntimeException('Archive authentication failed.');
                }
                [$plain, $tag] = $result;
                $this->write($output, $plain);
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    if (fread($input, 1) !== '') {
                        throw new RuntimeException('Unexpected archive content.');
                    }
                    $complete = true;
                    break;
                }
            }
            if (! $complete) {
                throw new RuntimeException('Archive is incomplete.');
            }
        } finally {
            fclose($input);
            fclose($output);
            if (! $complete && is_file($destination)) {
                unlink($destination);
            }
        }
    }

    private function keys(): array
    {
        $values = array_filter([config('drivesnap.encryption_key') ?: config('app.key'), ...config('app.previous_keys', [])]);
        if ($values === []) {
            throw new RuntimeException('Configure an encryption key before taking backups.');
        }

        return array_map(function (string $key): string {
            $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
            if (! is_string($raw) || strlen($raw) < 32) {
                throw new RuntimeException('The encryption key must contain at least 32 bytes.');
            }

            return hash_hkdf('sha256', $raw, 32, 'drivesnap/archive/v1');
        }, array_values($values));
    }

    private function write(mixed $stream, string $bytes): void
    {
        if (fwrite($stream, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Cannot write backup data.');
        }
    }
}

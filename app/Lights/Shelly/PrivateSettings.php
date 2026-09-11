<?php

namespace App\Lights\Shelly;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

/** Local commissioning only; independent of the publicly known demo APP_KEY. */
class PrivateSettings
{
    public const SERVER = 'https://shelly-277-eu.shelly.cloud';
    public const DEVICE = '2cbcbba011f8';

    public function __construct(private string $directory) {}

    public function configured(): bool
    {
        return is_file($this->directory.'/credentials.enc');
    }

    public function save(#[\SensitiveParameter] string $secret): void
    {
        if (!preg_match('/\A[A-Za-z0-9+\/_=.-]{16,4096}\z/D', $secret)) {
            throw new RuntimeException('Enter the cloud authorization key, without spaces.');
        }
        $this->directory();
        $lock = fopen($this->directory.'/settings.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('Private settings are unavailable.'); }
        try {
            $keyPath = $this->directory.'/encryption.key';
            if (!is_file($keyPath)) {
                if ($this->configured()) { throw new RuntimeException('The private encryption key is missing.'); }
                $this->write($keyPath, random_bytes(32));
            }
            $encrypted = $this->encrypter()->encryptString($secret);
            $this->write($this->directory.'/credentials.enc', $encrypted);
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    public function secret(): string
    {
        if (!$this->configured()) { throw new RuntimeException('Save the cloud key first.'); }
        return $this->encrypter()->decryptString(file_get_contents($this->directory.'/credentials.enc'));
    }

    private function encrypter(): Encrypter
    {
        $key = is_file($this->directory.'/encryption.key') ? file_get_contents($this->directory.'/encryption.key') : '';
        if (strlen($key) !== 32) { throw new RuntimeException('Private settings are unavailable.'); }
        return new Encrypter($key, 'AES-256-CBC');
    }

    private function directory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Private settings are unavailable.');
        }
    }

    private function write(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)) {
            throw new RuntimeException('Could not save private settings.');
        }
        chmod($path, 0600);
    }
}

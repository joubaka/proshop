<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as BaseEncrypter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Extends the base EncryptCookies middleware to add a per-cookie HMAC prefix
 * before encrypting and validates it after decrypting.
 *
 * This backports the CookieValuePrefix guard introduced in laravel/framework
 * 6.20.44 (GHSA-3p32-j457-pg5x - Laravel Framework Deserialization Vulnerability).
 * The prefix binds each cookie value to its name + the application key, so a
 * value encrypted for one cookie cannot be replayed under a different name and
 * attacker-controlled payloads are rejected before any unserialization occurs.
 */
class EncryptCookies extends BaseEncrypter
{
    /**
     * The names of the cookies that should not be encrypted.
     *
     * @var array
     */
    protected $except = [
        //
    ];

    /**
     * Decrypt a single cookie value.
     *
     * After decryption we validate the per-cookie HMAC prefix. If the prefix
     * is missing or incorrect the cookie is discarded (returns null), preventing
     * stale or attacker-crafted ciphertexts from reaching the application.
     *
     * @param  string  $name
     * @param  string|array  $cookie
     * @return string|array|null
     */
    protected function decryptCookie($name, $cookie)
    {
        $decrypted = parent::decryptCookie($name, $cookie);

        $prefix = $this->cookieValuePrefix($name);

        if (is_string($decrypted) && strncmp($decrypted, $prefix, strlen($prefix)) === 0) {
            return substr($decrypted, strlen($prefix));
        }

        return null;
    }

    /**
     * Encrypt the cookies on an outgoing response.
     *
     * Prepends the per-cookie HMAC prefix to the plaintext before encrypting
     * so that decryptCookie() can validate it on subsequent requests.
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function encrypt(Response $response)
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($this->isDisabled($cookie->getName())) {
                continue;
            }

            if ($cookie->getValue() === null) {
                continue;
            }

            $prefixedValue = $this->cookieValuePrefix($cookie->getName()) . $cookie->getValue();

            $response->headers->setCookie($this->duplicate(
                $cookie,
                $this->encrypter->encrypt($prefixedValue, static::serialized($cookie->getName()))
            ));
        }

        return $response;
    }

    /**
     * Build the per-cookie HMAC prefix.
     *
     * Uses an SHA-1 HMAC of the cookie name keyed with the first 16 bytes of
     * the application encryption key, followed by a pipe delimiter. Binding
     * the prefix to the cookie name prevents ciphertext replay across cookies.
     *
     * @param  string  $cookieName
     * @return string
     */
    protected function cookieValuePrefix($cookieName)
    {
        $appKey = config('app.key');

        $rawKey = strncmp($appKey, 'base64:', 7) === 0
            ? substr(base64_decode(substr($appKey, 7), true), 0, 16)
            : substr($appKey, 0, 16);

        return hash_hmac('sha1', $cookieName, $rawKey) . '|';
    }
}

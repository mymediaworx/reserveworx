<?php

namespace App\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Contracts\Encryption\StringEncrypter;
use RuntimeException;

/**
 * Resolves Laravel's encrypter on the first read or write.
 * Building a session store does not require an application key.
 * Nothing here writes a price, a payment, a tax, a fee, or a refund.
 */
final class DeferredEncrypter implements EncrypterContract, StringEncrypter
{
    public function __construct(private Container $container) {}

    /**
     * @param  mixed  $value
     */
    public function encrypt(#[\SensitiveParameter] $value, $serialize = true): string
    {
        return $this->encrypter()->encrypt($value, $serialize);
    }

    /**
     * @param  string  $payload
     */
    public function decrypt($payload, $unserialize = true): mixed
    {
        return $this->encrypter()->decrypt($payload, $unserialize);
    }

    public function getKey(): string
    {
        return $this->encrypter()->getKey();
    }

    /**
     * @return array<int, string>
     */
    public function getAllKeys(): array
    {
        return $this->encrypter()->getAllKeys();
    }

    /**
     * @return array<int, string>
     */
    public function getPreviousKeys(): array
    {
        return $this->encrypter()->getPreviousKeys();
    }

    /**
     * @param  string  $value
     */
    public function encryptString(#[\SensitiveParameter] $value): string
    {
        return $this->encrypter()->encryptString($value);
    }

    /**
     * @param  string  $payload
     */
    public function decryptString($payload): string
    {
        return $this->encrypter()->decryptString($payload);
    }

    private function encrypter(): EncrypterContract&StringEncrypter
    {
        $encrypter = $this->container->make('encrypter');

        if (! $encrypter instanceof EncrypterContract || ! $encrypter instanceof StringEncrypter || $encrypter instanceof self) {
            throw new RuntimeException('The encrypter is not available.');
        }

        return $encrypter;
    }
}

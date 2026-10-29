<?php

namespace App\Support;

use Illuminate\Session\EncryptedStore;
use Illuminate\Session\SessionManager;

/**
 * Builds an encrypted session store without resolving the encrypter.
 * The application key is read when a payload is stored or loaded.
 * Nothing here writes a price, a payment, a tax, a fee, or a refund.
 */
final class EncryptedSessionManager extends SessionManager
{
    /**
     * @param  \SessionHandlerInterface  $handler
     */
    protected function buildEncryptedSession($handler): EncryptedStore
    {
        return new EncryptedStore(
            $this->config->get('session.cookie'),
            $handler,
            new DeferredEncrypter($this->container),
            null,
            $this->config->get('session.serialization', 'php'),
        );
    }
}

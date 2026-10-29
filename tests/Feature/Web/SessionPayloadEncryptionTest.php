<?php

namespace Tests\Feature\Web;

use App\Support\EncryptedSessionManager;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Session\EncryptedStore;
use Tests\TestCase;

class SessionPayloadEncryptionTest extends TestCase
{
    private string $originalKey = '';

    private mixed $originalEncrypt = null;

    private mixed $originalDriver = null;

    protected function setUp(): void
    {
        parent::setUp();

        $key = config('app.key');
        $this->originalKey = is_string($key) ? $key : '';
        $this->originalEncrypt = config('session.encrypt');
        $this->originalDriver = config('session.driver');
    }

    protected function tearDown(): void
    {
        config([
            'app.key' => $this->originalKey,
            'session.encrypt' => $this->originalEncrypt,
            'session.driver' => $this->originalDriver,
        ]);
        $this->forgetSession();

        parent::tearDown();
    }

    public function test_building_the_session_store_does_not_require_an_application_key(): void
    {
        config([
            'app.key' => '',
            'session.encrypt' => true,
            'session.driver' => 'array',
        ]);
        $this->forgetSession();

        $this->assertFalse($this->app->resolved('encrypter'));

        $manager = $this->app->make('session');
        $this->assertInstanceOf(EncryptedSessionManager::class, $manager);

        $store = $manager->driver();
        $this->assertInstanceOf(EncryptedStore::class, $store);
        $this->assertFalse($this->app->resolved('encrypter'));

        $store->put('email', 'person@example.com');
        $thrown = null;

        try {
            $store->save();
        } catch (MissingAppKeyException $exception) {
            $thrown = $exception;
        }

        $this->assertInstanceOf(MissingAppKeyException::class, $thrown);
        $this->assertFalse($this->app->resolved('encrypter'));
    }

    public function test_a_saved_payload_is_ciphertext_and_reads_back(): void
    {
        config([
            'session.encrypt' => true,
            'session.driver' => 'array',
        ]);
        $this->forgetSession();

        $store = $this->app->make('session')->driver();
        $this->assertInstanceOf(EncryptedStore::class, $store);
        $store->put('email', 'person@example.com');
        $store->save();

        $stored = $store->getHandler()->read($store->getId());
        $this->assertIsString($stored);
        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString('person@example.com', $stored);

        $cookie = config('session.cookie');
        $serialization = config('session.serialization', 'json');
        $this->assertIsString($cookie);
        $this->assertIsString($serialization);

        $again = new EncryptedStore(
            $cookie,
            $store->getHandler(),
            $this->app->make('encrypter'),
            $store->getId(),
            $serialization,
        );
        $again->start();

        $this->assertSame('person@example.com', $again->get('email'));
    }

    private function forgetSession(): void
    {
        $this->app->forgetInstance('encrypter');
        $this->app->forgetInstance('session');
        $this->app->forgetInstance('session.store');
    }
}

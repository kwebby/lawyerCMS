<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature\Business;

use App\Auth\CrmUser;
use App\Contracts\RecordStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class BusinessTestCase extends TestCase
{
    use RefreshDatabase;

    protected RecordStore $store;

    protected CrmUser $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['crm.require_mfa' => false, 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'crm.private_path' => storage_path('framework/testing/business-files-'.Str::uuid())]);
        $this->store = app(RecordStore::class);
        $this->owner = $this->user('owner');
        $this->store->create('settings', ['legal_name' => 'Example Legal LLP', 'address' => '10 Court Road', 'tax_id' => 'TEST-123', 'invoice_prefix' => 'INV', 'payment_instructions' => 'Bank transfer'], 'business');
        $this->actingAs($this->owner)->withSession(['auth.confirmed_at' => time()]);
    }

    protected function tearDown(): void
    {
        $path = config('crm.private_path');
        if (is_string($path) && str_contains($path, '/framework/testing/business-files-') && is_dir($path)) {
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($path);
        }
        parent::tearDown();
    }

    protected function user(string $role, array $extra = []): CrmUser
    {
        return new CrmUser($this->store->create('users', array_merge(['name' => ucfirst($role).' user', 'email' => Str::uuid().'@example.test', 'roles' => [$role], 'password' => Hash::make('test-password-123'), 'status' => 'active', 'email_verified_at' => now()->toIso8601String()], $extra)));
    }

    protected function invoiceInput(array $extra = []): array
    {
        return array_replace(['recipient' => ['name' => 'Client Limited', 'email' => 'client@example.test', 'address' => '8 Client Street'], 'currency' => 'USD', 'items' => [['description' => 'Legal advice', 'quantity' => '2', 'unit_minor' => '10000', 'tax_bps' => 1000]], 'discount_minor' => '0'], $extra);
    }
}

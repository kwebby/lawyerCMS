<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature;

use App\Support\HostingCapabilities;
use Tests\TestCase;

final class HostingCapabilitiesTest extends TestCase
{
    public function test_memory_requirements_use_actual_php_units(): void
    {
        $capabilities = app(HostingCapabilities::class);
        $this->assertSame(268435456, $capabilities->memoryBytes('256M'));
        $this->assertSame(1073741824, $capabilities->memoryBytes('1G'));
        $this->assertSame(268435456, $capabilities->memoryBytes('262144K'));
        $this->assertSame(PHP_INT_MAX, $capabilities->memoryBytes('-1'));
        $this->assertSame(0, $capabilities->memoryBytes('invalid'));
    }

    public function test_private_storage_rejects_public_root_and_symlink_escapes(): void
    {
        $check = app(HostingCapabilities::class);
        $this->assertFalse($check->privatePath(public_path(), public_path()));
        $this->assertFalse($check->privatePath(public_path('new-vault'), public_path()));
        $this->assertFalse($check->privatePath('relative/vault', public_path()));
        $this->assertTrue($check->privatePath(storage_path('app/private/new-vault'), public_path()));
        $link = storage_path('framework/public-link-'.bin2hex(random_bytes(4)));
        symlink(public_path(), $link);
        try {
            $this->assertFalse($check->privatePath($link.'/new-vault', public_path()));
        } finally {
            unlink($link);
        }
    }

    public function test_production_checks_do_not_accept_insecure_configuration(): void
    {
        $this->app->instance('env', 'production');
        config(['app.url' => 'http://example.test', 'app.debug' => true, 'crm.require_mfa' => false, 'session.secure' => false]);
        $checks = app(HostingCapabilities::class)->inspect('mysql');
        $this->assertFalse($checks['HTTPS canonical application URL']);
        $this->assertFalse($checks['Debug disabled']);
        $this->assertFalse($checks['Staff MFA required']);
        $this->assertFalse($checks['Secure session cookies']);
    }
}

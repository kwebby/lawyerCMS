<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature;

use Illuminate\Database\Connectors\PostgresConnector;
use ReflectionMethod;
use Tests\TestCase;

final class DatabaseConfigurationTest extends TestCase
{
    public function test_missing_or_empty_postgres_ca_does_not_append_an_empty_dsn_option(): void
    {
        foreach ([null, ''] as $certificate) {
            $configuration = $this->postgresConfiguration($certificate);
            $dsn = (new ReflectionMethod(PostgresConnector::class, 'getDsn'))->invoke(new PostgresConnector, $configuration);

            $this->assertNull($configuration['sslrootcert']);
            $this->assertStringNotContainsString('sslrootcert=', $dsn);
            $this->assertStringContainsString(';sslmode=verify-full', $dsn);
        }
    }

    public function test_configured_postgres_ca_and_certificate_verification_are_preserved(): void
    {
        $certificate = '/srv/lawyercms/shared/secrets/postgres-ca.pem';
        $configuration = $this->postgresConfiguration($certificate);
        $dsn = (new ReflectionMethod(PostgresConnector::class, 'getDsn'))->invoke(new PostgresConnector, $configuration);

        $this->assertSame($certificate, $configuration['sslrootcert']);
        $this->assertStringContainsString(';sslrootcert='.$certificate, $dsn);
        $this->assertStringContainsString(';sslmode=verify-full', $dsn);
    }

    private function postgresConfiguration(?string $certificate): array
    {
        $server = $_SERVER;
        $environment = $_ENV;
        try {
            $_SERVER['DB_SSLMODE'] = $_ENV['DB_SSLMODE'] = 'verify-full';
            $_SERVER['DB_SSLROOTCERT'] = $_ENV['DB_SSLROOTCERT'] = $certificate ?? '(null)';

            return (require config_path('database.php'))['connections']['pgsql'];
        } finally {
            $_SERVER = $server;
            $_ENV = $environment;
        }
    }
}

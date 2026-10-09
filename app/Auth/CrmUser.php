<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;

/**
 * @property-read string $id
 * @property-read string $name
 * @property-read string $email
 * @property-read array $roles
 * @property-read string $status
 * @property-read int $session_epoch
 * @property-read ?string $access_expires_at
 */
final class CrmUser implements Authenticatable, CanResetPassword
{
    public function __construct(private array $attributes) {}

    public function __get(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->attributes['id'];
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return $this->attributes['password'];
    }

    public function getRememberToken(): ?string
    {
        return $this->attributes['remember_token'] ?? null;
    }

    public function setRememberToken($value): void
    {
        $this->attributes['remember_token'] = $value;
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }

    public function getEmailForPasswordReset(): string
    {
        return $this->email;
    }

    public function sendPasswordResetNotification($token): void {}

    public function record(): array
    {
        return array_diff_key($this->attributes, array_flip(['password', 'remember_token', 'mfa_secret', 'mfa_pending', 'recovery_codes']));
    }
}

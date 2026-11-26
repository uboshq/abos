<?php

declare(strict_types=1);

namespace App\Modules\Mail\Services;

/**
 * ⓘ পরীক্ষার মেইল সার্ভার — প্রতিটা ডাক `calls`-এ জমা, ঠিকানাগুলো `boxes`-এ।
 */
final class FakeMailHost implements MailHost
{
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $calls = [];

    /** @var array<string, array{email: string, used_mb: float, quota_mb: ?int, login_off: bool, incoming_off: bool}> */
    public array $boxes = [];

    public bool $canWrite = true;

    public function configured(): bool
    {
        return true;
    }

    public function writable(): bool
    {
        return $this->canWrite;
    }

    public function domains(): array
    {
        return ['adi.test'];
    }

    public function accounts(): array
    {
        return array_values($this->boxes);
    }

    public function create(string $email, string $password, int $quotaMb): void
    {
        $this->calls[] = ['create', [$email, $quotaMb]];
        $this->boxes[$email] = ['email' => $email, 'used_mb' => 0.0, 'quota_mb' => $quotaMb, 'login_off' => false, 'incoming_off' => false];
    }

    public function quota(string $email, int $quotaMb): void
    {
        $this->calls[] = ['quota', [$email, $quotaMb]];
    }

    public function password(string $email, string $password): void
    {
        $this->calls[] = ['password', [$email]];
    }

    public function login(string $email, bool $on): void
    {
        $this->calls[] = ['login', [$email, $on]];
        if (isset($this->boxes[$email])) {
            $this->boxes[$email]['login_off'] = ! $on;
        }
    }

    public function incoming(string $email, bool $on): void
    {
        $this->calls[] = ['incoming', [$email, $on]];
        if (isset($this->boxes[$email])) {
            $this->boxes[$email]['incoming_off'] = ! $on;
        }
    }

    public function forward(string $email, string $to): void
    {
        $this->calls[] = ['forward', [$email, $to]];
    }

    public function unforward(string $email, string $to): void
    {
        $this->calls[] = ['unforward', [$email, $to]];
    }

    public function delete(string $email): void
    {
        $this->calls[] = ['delete', [$email]];
        unset($this->boxes[$email]);
    }

    public function dns(string $domain): array
    {
        return ['mx' => true, 'spf' => true, 'dkim' => true, 'dmarc' => false];
    }

    /** ⓘ পরীক্ষার সুবিধা — এই নামের ডাক কতবার হলো */
    public function count(string $name): int
    {
        return count(array_filter($this->calls, fn ($c) => $c[0] === $name));
    }
}

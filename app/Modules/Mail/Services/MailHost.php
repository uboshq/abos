<?php

declare(strict_types=1);

namespace App\Modules\Mail\Services;

/**
 * ⭐ নিজের ডোমেইনের মেইল সার্ভার (প্রশাসন) — ঠিকানা তৈরি, জায়গা, পাসওয়ার্ড, লগইন/আসা মেইল বন্ধ, ফরোয়ার্ড, মোছা।
 * ⓘ আসলটা [[CpanelMailHost]] (হোস্টিংয়ের cPanel UAPI); পরীক্ষায় [[FakeMailHost]]।
 * ⛔ `writable()` মিথ্যা হলে (ডেমো) সার্ভারে কিছু বদলায় না — খাতায় লেখা হয়, পর্দায় "ডেমো" বলে।
 */
interface MailHost
{
    public function configured(): bool;

    public function writable(): bool;

    /** @return list<string> */
    public function domains(): array;

    /** @return list<array{email: string, used_mb: float, quota_mb: ?int, login_off: bool, incoming_off: bool}> */
    public function accounts(): array;

    public function create(string $email, string $password, int $quotaMb): void;

    public function quota(string $email, int $quotaMb): void;

    public function password(string $email, string $password): void;

    public function login(string $email, bool $on): void;

    public function incoming(string $email, bool $on): void;

    public function forward(string $email, string $to): void;

    public function unforward(string $email, string $to): void;

    public function delete(string $email): void;

    /** @return array{mx: bool, spf: bool, dkim: bool, dmarc: bool} */
    public function dns(string $domain): array;
}

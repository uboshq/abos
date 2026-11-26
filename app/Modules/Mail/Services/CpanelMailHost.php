<?php

declare(strict_types=1);

namespace App\Modules\Mail\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * ⭐ হোস্টিংয়ের cPanel UAPI — টোকেন দিয়ে (`services.cpanel.*`, .env-এ; ⛔ কোডে বা খাতায় নয়)।
 * ⓘ লেখা কেবল `services.cpanel.write` সত্য হলে — লাইভে চালু, ডেমোতে বন্ধ (ডেমো থেকে আসল ঠিকানা তৈরি বা মোছা হয় না)।
 */
final class CpanelMailHost implements MailHost
{
    public function configured(): bool
    {
        return (string) config('services.cpanel.url') !== '' && (string) config('services.cpanel.user') !== '' && (string) config('services.cpanel.token') !== '';
    }

    public function writable(): bool
    {
        return $this->configured() && (bool) config('services.cpanel.write', false);
    }

    public function domains(): array
    {
        return Cache::remember('mail.host.domains', 600, function () {
            $d = (array) $this->call('DomainInfo', 'list_domains');

            return array_values(array_unique(array_filter(array_merge([(string) ($d['main_domain'] ?? '')], (array) ($d['addon_domains'] ?? []), (array) ($d['parked_domains'] ?? [])))));
        });
    }

    public function accounts(): array
    {
        $rows = (array) $this->call('Email', 'list_pops_with_disk');

        return array_values(array_map(fn ($r) => [
            'email' => mb_strtolower((string) ($r['email'] ?? '')),
            'used_mb' => (float) ($r['diskused'] ?? 0),
            'quota_mb' => is_numeric($r['diskquota'] ?? null) ? (int) $r['diskquota'] : null,
            'login_off' => (bool) ($r['suspended_login'] ?? false),
            'incoming_off' => (bool) ($r['suspended_incoming'] ?? false),
        ], array_filter($rows, fn ($r) => str_contains((string) ($r['email'] ?? ''), '@'))));
    }

    public function create(string $email, string $password, int $quotaMb): void
    {
        [$user, $domain] = $this->split($email);
        $this->write('Email', 'add_pop', ['email' => $user, 'domain' => $domain, 'password' => $password, 'quota' => $quotaMb, 'skip_update_db' => 1]);
    }

    public function quota(string $email, int $quotaMb): void
    {
        [$user, $domain] = $this->split($email);
        $this->write('Email', 'edit_pop_quota', ['email' => $user, 'domain' => $domain, 'quota' => $quotaMb]);
    }

    public function password(string $email, string $password): void
    {
        [$user, $domain] = $this->split($email);
        $this->write('Email', 'passwd_pop', ['email' => $user, 'domain' => $domain, 'password' => $password]);
    }

    public function login(string $email, bool $on): void
    {
        $this->write('Email', $on ? 'unsuspend_login' : 'suspend_login', ['email' => $email]);
    }

    public function incoming(string $email, bool $on): void
    {
        $this->write('Email', $on ? 'unsuspend_incoming' : 'suspend_incoming', ['email' => $email]);
    }

    public function forward(string $email, string $to): void
    {
        [, $domain] = $this->split($email);
        $this->write('Email', 'add_forwarder', ['domain' => $domain, 'email' => $email, 'fwdopt' => 'fwd', 'fwdemail' => $to]);
    }

    public function unforward(string $email, string $to): void
    {
        $this->write('Email', 'delete_forwarder', ['address' => $email, 'forwarder' => $to]);
    }

    public function delete(string $email): void
    {
        [$user, $domain] = $this->split($email);
        $this->write('Email', 'delete_pop', ['email' => $user, 'domain' => $domain]);
    }

    public function dns(string $domain): array
    {
        return Cache::remember('mail.host.dns.'.$domain, 3600, function () use ($domain) {
            $txt = fn (string $host) => array_map(fn ($r) => (string) ($r['txt'] ?? ''), @dns_get_record($host, DNS_TXT) ?: []);

            return [
                'mx' => (@dns_get_record($domain, DNS_MX) ?: []) !== [],
                'spf' => collect($txt($domain))->contains(fn ($t) => str_starts_with(strtolower($t), 'v=spf1')),
                'dkim' => collect($txt('default._domainkey.'.$domain))->contains(fn ($t) => str_contains(strtolower($t), 'v=dkim1') || str_contains($t, 'p=')),
                'dmarc' => collect($txt('_dmarc.'.$domain))->contains(fn ($t) => str_starts_with(strtolower($t), 'v=dmarc1')),
            ];
        });
    }

    // ── ভিতরের কাজ ─────────────────────────────────────────────────

    /** @param array<string, mixed> $params */
    private function write(string $module, string $fn, array $params): void
    {
        if (! $this->writable()) {
            return;
        }

        $this->call($module, $fn, $params);
        Cache::forget('mail.host.domains');
    }

    /** @param array<string, mixed> $params */
    private function call(string $module, string $fn, array $params = []): mixed
    {
        if (! $this->configured()) {
            throw new RuntimeException(__('mail::message.host_not_set'));
        }

        $res = Http::withHeaders(['Authorization' => 'cpanel '.config('services.cpanel.user').':'.config('services.cpanel.token')])
            ->withOptions(['verify' => (bool) config('services.cpanel.verify', false)])
            ->timeout(20)
            ->asForm()
            ->post(rtrim((string) config('services.cpanel.url'), '/').'/execute/'.$module.'/'.$fn, $params);

        $json = $res->json();

        if (! $res->ok() || ! is_array($json) || (int) ($json['status'] ?? 0) !== 1) {
            $why = is_array($json) ? implode(' ', (array) ($json['errors'] ?? [])) : 'HTTP '.$res->status();

            throw new RuntimeException(mb_substr($why !== '' ? $why : 'cPanel error', 0, 200));
        }

        return $json['data'] ?? null;
    }

    /** @return array{0: string, 1: string} */
    private function split(string $email): array
    {
        $at = strrpos($email, '@');

        if ($at === false) {
            throw new RuntimeException('bad address');
        }

        return [substr($email, 0, $at), substr($email, $at + 1)];
    }
}

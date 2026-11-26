<?php

declare(strict_types=1);

namespace App\Modules\Mail\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * ⭐ হোস্টিংয়ের cPanel UAPI — দুই পথ:
 *   · সার্ভারের নিজের `uapi` (ABOS একই হোস্টিং অ্যাকাউন্টে চলে) — ⭐ কোনো চাবি জমা থাকে না; এটাই প্রথম পছন্দ;
 *   · নয়তো টোকেন দিয়ে HTTPS (`services.cpanel.*`, .env-এ) — অন্য সার্ভারে চললে।
 * ⛔ পুরো অ্যাকাউন্টের টোকেন .env-এ রাখা ঝুঁকি (ফাঁস হলে গোটা হোস্টিং) — তাই `uapi` থাকলে টোকেন লাগে না।
 * ⓘ লেখা কেবল `services.cpanel.write` সত্য হলে — লাইভে চালু, ডেমোতে বন্ধ (ডেমো থেকে আসল ঠিকানা তৈরি বা মোছা হয় না)।
 */
final class CpanelMailHost implements MailHost
{
    public function configured(): bool
    {
        return $this->local() || ((string) config('services.cpanel.url') !== '' && (string) config('services.cpanel.user') !== '' && (string) config('services.cpanel.token') !== '');
    }

    /** ⓘ সার্ভারের নিজের uapi চালানো যায় কিনা — বাইনারি আছে আর proc_open বন্ধ নয় */
    private function local(): bool
    {
        $bin = (string) config('services.cpanel.uapi', '/usr/bin/uapi');
        $off = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return $bin !== '' && @is_executable($bin) && function_exists('proc_open') && ! in_array('proc_open', $off, true);
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
            // ⓘ মেইলবক্সে শেষ বদলের সময় — শেষ ব্যবহারের কাছাকাছি হিসাব
            'last' => isset($r['mtime']) && is_numeric($r['mtime']) ? (int) $r['mtime'] : null,
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

    public function autoReply(string $email, ?array $reply): void
    {
        [, $domain] = $this->split($email);

        if ($reply === null) {
            $this->write('Email', 'delete_auto_responder', ['email' => $email]);

            return;
        }

        $this->write('Email', 'add_auto_responder', [
            'email' => strstr($email, '@', true), 'domain' => $domain, 'from' => (string) ($reply['from'] ?? ''),
            'subject' => (string) $reply['subject'], 'body' => (string) $reply['body'], 'is_html' => 0, 'interval' => 24,
            'start' => isset($reply['start']) ? strtotime((string) $reply['start']) : time(),
            'stop' => isset($reply['stop']) && $reply['stop'] ? strtotime((string) $reply['stop'].' 23:59:59') : '',
        ]);
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

        if ($this->local()) {
            return $this->viaUapi($module, $fn, $params);
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

    /**
     * ⓘ `uapi --output=json Module func key=value …` — প্রতিটা মান আলাদা যুক্তি (shell নয়), তাই কোনো মানে কমান্ড ঢোকে না।
     *
     * @param  array<string, mixed>  $params
     */
    private function viaUapi(string $module, string $fn, array $params): mixed
    {
        $cmd = [(string) config('services.cpanel.uapi', '/usr/bin/uapi'), '--output=json', $module, $fn];

        foreach ($params as $k => $v) {
            $cmd[] = $k.'='.(string) $v;
        }

        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($proc)) {
            throw new RuntimeException('uapi did not start');
        }

        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $json = json_decode($out, true);
        $result = is_array($json) ? ($json['result'] ?? null) : null;

        if (! is_array($result) || (int) ($result['status'] ?? 0) !== 1) {
            $why = is_array($result) ? implode(' ', (array) ($result['errors'] ?? [])) : 'uapi error';

            throw new RuntimeException(mb_substr($why !== '' ? $why : 'uapi error', 0, 200));
        }

        return $result['data'] ?? null;
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

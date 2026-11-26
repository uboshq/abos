<?php

declare(strict_types=1);

namespace App\Modules\Mail\Services;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Services\PermissionSyncer;
use App\Models\User;
use App\Modules\Mail\Models\MailAccount;
use App\Modules\Mail\Models\MailAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ নিজের ডোমেইনের ঠিকানা — প্রশাসন (নমুনা v4 পর্দা ৭–১২, মালিকের "ok" ২৬ নভেম্বর ২০২৬)।
 *
 * ⛔ নিয়ম:
 *   · `system` ঠিকানা (no-reply@, postmaster@) কখনো মোছা যায় না, বন্ধও না — ABOS নিজে এগুলো দিয়ে বিল আর রিসেট পাঠায়;
 *   · চালু ঠিকানা সরাসরি মোছা যায় না — আগে বন্ধ;
 *   · মোছা = 30 দিন রাখা (নতুন মেইল ফেরত যায়), এর মধ্যে ফেরানো যায়; তারপর ব্যাকআপ কপি রেখে সার্ভার থেকে ([[TidyMail]]);
 *   · মুছতে পারেন কেবল মালিক বা `mail.addresses.delete`; নিজের পাসওয়ার্ড আর ঠিকানা লিখে নিশ্চিত।
 * ⓘ প্রতিটা কাজ অডিটে: ঠিকানার সারি নিজে (IsAudited) আর সার্ভারের কাজ নামসহ ([[AuditEngine::recordAction]])।
 * ⓘ পাসওয়ার্ড কোথাও খোলা লেখায় জমা হয় না — মেইলবক্সে তালাবদ্ধ, কর্মীকে ABOS বার্তায় একবার, নয়তো প্রশাসককে পর্দায় একবার।
 */
final class MailAddresses
{
    public const SYSTEM_LOCALS = ['no-reply', 'noreply', 'postmaster', 'mailer-daemon', 'abuse'];

    public function __construct(
        private readonly MailHost $host,
        private readonly AuditEngine $audit,
    ) {}

    /**
     * সার্ভারের ঠিকানা আর খাতার সারি মিলিয়ে — সার্ভারে আছে কিন্তু খাতায় নেই এমন ঠিকানা খাতায় ওঠে (একবার)।
     *
     * @return array<string, array{used_mb: float, quota_mb: ?int, login_off: bool, incoming_off: bool}>
     */
    public function sync(): array
    {
        if (! $this->host->configured()) {
            return [];
        }

        $server = [];

        try {
            foreach ($this->host->accounts() as $a) {
                $server[$a['email']] = $a;
            }
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $known = MailAddress::query()->pluck('email')->map(fn ($e) => mb_strtolower($e))->all();

        foreach ($server as $email => $a) {
            if (in_array($email, $known, true)) {
                continue;
            }

            $local = (string) strstr($email, '@', true);
            MailAddress::query()->create([
                'email' => $email, 'domain' => substr((string) strrchr($email, '@'), 1),
                'kind' => in_array($local, self::SYSTEM_LOCALS, true) ? MailAddress::SYSTEM : MailAddress::PERSON,
                'quota_mb' => $a['quota_mb'] ?? 0,
                'status' => $a['login_off'] ? MailAddress::PAUSED : MailAddress::ACTIVE,
            ]);
        }

        return $server;
    }

    /**
     * @param  array{kind: string, user_id?: ?int, local: string, domain: string, display_name?: ?string, quota_mb: int,
     *               deliver: string, members?: array<int, array{user_id: int, can_send: bool}>, forward_to?: ?string}  $data
     * @return array{address: MailAddress, password: ?string}  password কেবল `deliver = screen` হলে, একবার
     */
    public function create(User $by, array $data): array
    {
        $local = mb_strtolower(trim($data['local']));
        $domain = mb_strtolower(trim($data['domain']));
        $email = $local.'@'.$domain;

        if (! in_array($domain, array_map('mb_strtolower', $this->host->domains()), true)) {
            throw ValidationException::withMessages(['domain' => __('mail::message.domain_unknown')]);
        }

        if (MailAddress::query()->where('email', $email)->exists() || $this->onServer($email)) {
            throw ValidationException::withMessages(['local' => __('mail::message.address_taken')]);
        }

        $kind = $data['kind'] === MailAddress::SHARED ? MailAddress::SHARED : MailAddress::PERSON;
        $password = $this->newPassword();
        $this->host->create($email, $password, (int) $data['quota_mb']);

        $address = DB::transaction(function () use ($by, $data, $email, $domain, $kind, $password) {
            $account = MailAccount::query()->create([
                'kind' => $kind === MailAddress::SHARED ? MailAccount::SHARED : MailAccount::OWN,
                'user_id' => $kind === MailAddress::PERSON ? ($data['user_id'] ?? null) : null,
                'provider' => 'company', 'email' => $email, 'label' => $data['display_name'] ?? null,
                'imap_host' => 'mail.'.$domain, 'imap_port' => 993, 'imap_secure' => 'ssl',
                'smtp_host' => 'mail.'.$domain, 'smtp_port' => 465, 'smtp_secure' => 'ssl',
                'secret' => $password, 'status' => MailAccount::ACTIVE, 'created_by' => $by->id,
                'sender_name' => $data['display_name'] ?? null,
            ]);

            if ($kind === MailAddress::SHARED) {
                $this->syncMembers($account, (array) ($data['members'] ?? []));
            }

            return MailAddress::query()->create([
                'email' => $email, 'domain' => $domain, 'kind' => $kind,
                'user_id' => $kind === MailAddress::PERSON ? ($data['user_id'] ?? null) : null,
                'display_name' => $data['display_name'] ?? null, 'quota_mb' => (int) $data['quota_mb'],
                'status' => MailAddress::ACTIVE, 'mail_account_id' => $account->id, 'created_by' => $by->id,
                'forward_to' => $data['forward_to'] ?? null,
            ]);
        });

        if (! empty($data['forward_to'])) {
            $this->host->forward($email, (string) $data['forward_to']);
        }

        $shown = $this->deliver($by, $address, $password, (string) $data['deliver']);

        return ['address' => $address, 'password' => $shown];
    }

    /** @param array{display_name?: ?string, quota_mb?: int, sender_with_person?: bool} $data */
    public function edit(User $by, MailAddress $address, array $data): void
    {
        if (isset($data['quota_mb']) && (int) $data['quota_mb'] !== (int) $address->quota_mb) {
            $this->host->quota($address->email, (int) $data['quota_mb']);
        }

        DB::transaction(function () use ($address, $data) {
            $address->fill(array_intersect_key($data, array_flip(['display_name', 'quota_mb'])))->save();

            if ($address->account) {
                $address->account->fill(array_filter([
                    'label' => $data['display_name'] ?? null,
                    'sender_name' => $data['display_name'] ?? null,
                    'sender_with_person' => $data['sender_with_person'] ?? null,
                ], fn ($v) => $v !== null))->save();
            }
        });
    }

    /** @param array<int, array{user_id: int, can_send: bool}> $members */
    public function members(User $by, MailAddress $address, array $members): void
    {
        abort_unless($address->kind === MailAddress::SHARED && $address->account, 422);
        $before = $address->account->members()->pluck('users.name')->all();
        $this->syncMembers($address->account, $members);
        $after = $address->account->fresh()->members()->pluck('users.name')->all();
        $this->audit->recordAction($address, 'mail_members_changed', implode(', ', $before).' → '.implode(', ', $after));
    }

    public function forward(User $by, MailAddress $address, ?string $to): void
    {
        if ($address->forward_to && $address->forward_to !== $to) {
            $this->host->unforward($address->email, $address->forward_to);
        }

        if ($to) {
            $this->host->forward($address->email, $to);
        }

        $address->forceFill(['forward_to' => $to])->save();
    }

    public function resetPassword(User $by, MailAddress $address, string $deliver): ?string
    {
        abort_if($address->status === MailAddress::DELETED, 409);
        $password = $this->newPassword();
        $this->host->password($address->email, $password);
        $address->account?->forceFill(['secret' => $password])->save();
        $this->audit->recordAction($address, 'mail_password_reset', __('mail::field.admin.deliver_'.$deliver));

        return $this->deliver($by, $address, $password, $deliver);
    }

    /**
     * বন্ধ — লগইন এখনই বন্ধ; নতুন মেইল হয় আসতে থাকে আর একজন দেখেন (forward), নয়তো ফেরত যায় (bounce)।
     */
    public function pause(User $by, MailAddress $address, string $mode, ?string $forwardTo, ?string $reason, ?string $resumeOn): void
    {
        abort_if($address->isSystem(), 422, __('mail::message.system_address'));
        abort_unless($address->status === MailAddress::ACTIVE, 409);

        $this->host->login($address->email, false);

        if ($mode === 'bounce') {
            $this->host->incoming($address->email, false);
        } elseif ($forwardTo) {
            $this->host->forward($address->email, $forwardTo);
        }

        DB::transaction(function () use ($address, $mode, $forwardTo, $reason, $resumeOn) {
            $address->forceFill(['status' => MailAddress::PAUSED, 'pause_mode' => $mode, 'forward_to' => $mode === 'forward' ? $forwardTo : $address->forward_to,
                'pause_reason' => $reason, 'resume_on' => $resumeOn])->save();
            $address->account?->forceFill(['status' => MailAccount::OFF])->save();
        });
    }

    public function resume(User $by, MailAddress $address): void
    {
        abort_unless($address->status === MailAddress::PAUSED, 409);

        $this->host->login($address->email, true);
        $this->host->incoming($address->email, true);

        if ($address->pause_mode === 'forward' && $address->forward_to) {
            $this->host->unforward($address->email, $address->forward_to);
        }

        DB::transaction(function () use ($address) {
            $address->forceFill(['status' => MailAddress::ACTIVE, 'pause_mode' => null, 'pause_reason' => null, 'resume_on' => null,
                'forward_to' => $address->pause_mode === 'forward' ? null : $address->forward_to])->save();
            $address->account?->forceFill(['status' => MailAccount::ACTIVE])->save();
        });
    }

    /**
     * মোছার অনুরোধ — 30 দিন রাখা; নতুন মেইল ফেরত যায়; পুরনো মেইল একজনকে দেখতে দেওয়া যায় (কেবল পড়া)।
     */
    public function delete(User $by, MailAddress $address, string $typed, string $password, ?int $handTo): void
    {
        abort_unless($this->canDelete($by), 403);

        if ($address->isSystem()) {
            throw ValidationException::withMessages(['email' => __('mail::message.system_address')]);
        }

        if ($address->status === MailAddress::ACTIVE) {
            throw ValidationException::withMessages(['email' => __('mail::message.pause_first')]);
        }

        abort_unless($address->status === MailAddress::PAUSED, 409);

        if (mb_strtolower(trim($typed)) !== mb_strtolower($address->email)) {
            throw ValidationException::withMessages(['typed' => __('mail::message.type_to_confirm')]);
        }

        if (! Hash::check($password, (string) $by->getAuthPassword())) {
            throw ValidationException::withMessages(['password' => __('mail::message.wrong_password')]);
        }

        $this->host->incoming($address->email, false);
        $handed = $handTo ? User::query()->find($handTo) : null;

        DB::transaction(function () use ($address, $handed) {
            $address->forceFill(['status' => MailAddress::DELETING, 'delete_on' => now()->addDays(MailAddress::KEEP_DAYS)->toDateString(),
                'handed_to' => $handed?->email])->save();

            // ⓘ পুরনো মেইল একজনকে কেবল পড়তে দেওয়া — দলের মেইলবক্স হয়ে, পাঠানোর অধিকার ছাড়া
            if ($handed && $address->account) {
                $address->account->forceFill(['kind' => MailAccount::SHARED, 'user_id' => null, 'status' => MailAccount::ACTIVE])->save();
                $address->account->members()->sync([$handed->id => ['can_send' => false]]);
            }
        });
    }

    public function restore(User $by, MailAddress $address): void
    {
        abort_unless($this->canDelete($by), 403);
        abort_unless($address->status === MailAddress::DELETING, 409);

        $this->host->incoming($address->email, true);
        $address->forceFill(['status' => MailAddress::PAUSED, 'delete_on' => null, 'handed_to' => null])->save();
        $address->account?->forceFill(['status' => MailAccount::OFF])->save();
    }

    /**
     * ⭐ স্বয়ংক্রিয় উত্তর (নমুনা v4 পর্দা ৯, ট্যাব) — সার্ভারে বসে; বন্ধ করলে সরে।
     *
     * @param  array{on: bool, subject?: ?string, body?: ?string, start?: ?string, stop?: ?string}  $reply
     */
    public function autoReply(User $by, MailAddress $address, array $reply): void
    {
        abort_if($address->status === MailAddress::DELETED, 409);
        $on = (bool) $reply['on'];
        $this->host->autoReply($address->email, $on ? ['from' => (string) ($address->display_name ?: ''), 'subject' => (string) ($reply['subject'] ?? ''),
            'body' => (string) ($reply['body'] ?? ''), 'start' => $reply['start'] ?? null, 'stop' => $reply['stop'] ?? null] : null);
        $address->forceFill(['auto_reply' => $on ? array_intersect_key($reply, array_flip(['on', 'subject', 'body', 'start', 'stop'])) : null])->save();
    }

    /** ⓘ দিনের কাজ — নিজে আবার চালু, আর 30 দিন পেরোনো মোছা (ব্যাকআপ কপি আগে) */
    public function tidy(?callable $backup = null): array
    {
        $done = ['resumed' => 0, 'deleted' => 0];

        foreach (MailAddress::query()->withoutGlobalScopes()->where('status', MailAddress::PAUSED)->whereNotNull('resume_on')
            ->whereDate('resume_on', '<=', now()->toDateString())->get() as $a) {
            \App\Core\Support\CompanyContext::forCompany((int) $a->company_id, fn () => $this->resume(new User, $a));
            $done['resumed']++;
        }

        foreach (MailAddress::query()->withoutGlobalScopes()->where('status', MailAddress::DELETING)
            ->whereDate('delete_on', '<=', now()->toDateString())->get() as $a) {
            \App\Core\Support\CompanyContext::forCompany((int) $a->company_id, function () use ($a, $backup) {
                if ($backup !== null) {
                    $backup($a);
                }
                $this->host->delete($a->email);
                $a->forceFill(['status' => MailAddress::DELETED])->save();
                $a->account?->forceFill(['status' => MailAccount::OFF])->save();
            });
            $done['deleted']++;
        }

        return $done;
    }

    public function canDelete(User $by): bool
    {
        return $by->can('mail.addresses.delete') || $by->roles->contains('name', PermissionSyncer::SUPER_ADMIN_ROLE);
    }

    // ── ভিতরের কাজ ─────────────────────────────────────────────────

    /** @param array<int, array{user_id: int, can_send: bool}> $members */
    private function syncMembers(MailAccount $account, array $members): void
    {
        $sync = [];

        foreach ($members as $m) {
            $sync[(int) $m['user_id']] = ['can_send' => (bool) ($m['can_send'] ?? false)];
        }

        $account->members()->sync($sync);
    }

    private function deliver(User $by, MailAddress $address, string $password, string $deliver): ?string
    {
        if ($deliver === 'messenger' && $address->user_id && class_exists(\App\Modules\Messaging\Services\MessengerService::class)) {
            $to = User::query()->find($address->user_id);

            if ($to && $to->id !== $by->id) {
                try {
                    $svc = app(\App\Modules\Messaging\Services\MessengerService::class);
                    $svc->send($svc->directWith($by, $to), $by, ['kind' => 'text',
                        'text' => __('mail::message.password_message', ['email' => $address->email, 'password' => $password])]);

                    return null;
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        // ⓘ বার্তা না গেলে প্রশাসককে পর্দায় একবার দেখানো — কোথাও জমা নয়
        return $password;
    }

    private function newPassword(): string
    {
        return Str::password(16, symbols: false);
    }

    private function onServer(string $email): bool
    {
        try {
            return collect($this->host->accounts())->contains(fn ($a) => $a['email'] === $email);
        } catch (\Throwable) {
            return false;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Mail\Services;

use App\Core\Services\SettingsService;
use App\Models\User;
use App\Modules\Mail\Models\MailAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ মেইলবক্স যোগ, অনুমতি, বন্ধ — নিয়ম এক জায়গায় (মালিক, ২৬ নভেম্বর ২০২৬, নমুনা v3):
 *
 * - সেবাদাতা বাছলে সার্ভারের ঠিকানা নিজে বসে ([[MailAccount::PROVIDERS]]); "যেকোনো IMAP"-এ হাতে।
 * - যোগের আগে সংযোগ যাচাই — ভুল পাসওয়ার্ডে মেইলবক্স জমা হয় না, কারণসহ ফেরে।
 * - অফিসের মেইল (`own`, `shared`) সাথে সাথে চালু; ⭐ ব্যক্তিগত (`personal`) অপেক্ষায় — কারণ আর মেয়াদসহ, অনুমোদনকারী
 *   (`mail.personal.approve`) হ্যাঁ বললে চালু, মেয়াদ শেষে আপনা থেকে বন্ধ ([[expire()]])। ব্যাংকের মতো।
 * - ⛔ দলের মেইলবক্স আর কোম্পানির মেইল যোগ কেবল `mail.manage`।
 */
final class MailAccounts
{
    public function __construct(private readonly MailServer $server, private readonly SettingsService $settings) {}

    /**
     * @param  array{kind: string, provider: string, email: string, label?: ?string, secret: string, username?: ?string,
     *               imap_host?: ?string, imap_port?: ?int, imap_secure?: ?string, smtp_host?: ?string, smtp_port?: ?int, smtp_secure?: ?string,
     *               reason?: ?string, months?: ?int, members?: list<int>}  $data
     */
    public function add(User $by, array $data): MailAccount
    {
        $kind = $data['kind'];
        abort_unless(in_array($kind, MailAccount::KINDS, true), 422);

        if ($kind === MailAccount::SHARED || ($kind === MailAccount::OWN && ($data['user_id'] ?? $by->id) !== $by->id)) {
            abort_unless($by->can('mail.manage'), 403);
        }

        if ($kind === MailAccount::PERSONAL && ! $this->settings->get('mail.personal_allowed', true)) {
            throw ValidationException::withMessages(['kind' => __('mail::message.personal_off')]);
        }

        $account = new MailAccount($this->servers($data) + [
            'kind' => $kind,
            'provider' => $data['provider'],
            'email' => mb_strtolower(trim($data['email'])),
            'label' => $data['label'] ?? null,
            'colour' => $data['colour'] ?? null,
            'username' => $data['username'] ?? null,
            'secret' => $data['secret'],
            'user_id' => $kind === MailAccount::SHARED ? null : ($data['user_id'] ?? $by->id),
            'status' => $kind === MailAccount::PERSONAL ? MailAccount::PENDING : MailAccount::ACTIVE,
            'reason' => $kind === MailAccount::PERSONAL ? (string) ($data['reason'] ?? '') : null,
            'expires_on' => $kind === MailAccount::PERSONAL
                ? now()->addMonths(max(1, (int) ($data['months'] ?? $this->settings->get('mail.personal_months', 6))))->toDateString() : null,
            'created_by' => $by->id,
        ]);

        // ⛔ সংযোগ না হলে জমা নয় — কারণটা ব্যবহারকারী দেখেন, পাসওয়ার্ড নয়
        try {
            $this->server->check($account);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['secret' => __('mail::message.cannot_connect', ['why' => mb_substr($e->getMessage(), 0, 160)])]);
        }

        $account->checked_at = now();

        DB::transaction(function () use ($account, $data) {
            $account->save();

            if ($account->kind === MailAccount::SHARED) {
                $account->members()->sync(array_map('intval', $data['members'] ?? []));
            }
        });

        return $account;
    }

    /** ⭐ ব্যক্তিগত মেইলের অনুরোধে হ্যাঁ/না — কেবল অনুমোদনকারী; ⛔ নিজের অনুরোধ নিজে নয় (মালিক ছাড়া — মালিক নিজে অনুমোদন দেন) */
    public function decide(User $by, MailAccount $account, bool $yes, ?string $note = null): MailAccount
    {
        abort_unless($by->can('mail.personal.approve'), 403);
        abort_unless($account->isPersonal() && $account->status === MailAccount::PENDING, 409);
        abort_if((int) $account->user_id === (int) $by->id
            && ! $by->roles->contains('name', \App\Core\Services\PermissionSyncer::SUPER_ADMIN_ROLE), 403);

        $account->forceFill([
            'status' => $yes ? MailAccount::ACTIVE : MailAccount::REJECTED,
            'decided_by' => $by->id,
            'decided_at' => now(),
            'decision_note' => $note,
        ])->save();

        return $account;
    }

    /** ⓘ বন্ধ — মালিকের নিজের মেইলবক্স সে নিজে, কোম্পানিরগুলো `mail.manage`; কিছু মোছে না */
    public function turnOff(User $by, MailAccount $account): void
    {
        abort_unless((int) $account->user_id === (int) $by->id || $by->can('mail.manage') || $by->can('mail.personal.approve'), 403);
        $account->forceFill(['status' => MailAccount::OFF])->save();
    }

    /** ⓘ মেয়াদ পেরোনো ব্যক্তিগত মেইল — রোজ একবার (সময়সূচি) */
    public function expire(): int
    {
        return MailAccount::query()->withoutGlobalScopes()
            ->where('kind', MailAccount::PERSONAL)->where('status', MailAccount::ACTIVE)
            ->whereDate('expires_on', '<', now()->toDateString())
            ->update(['status' => MailAccount::EXPIRED]);
    }

    /** @return array<string, mixed> */
    private function servers(array $data): array
    {
        $known = MailAccount::PROVIDERS[$data['provider']] ?? null;

        if ($data['provider'] === 'company') {
            $domain = substr((string) strrchr(mb_strtolower(trim($data['email'])), '@'), 1);
            $known = ['imap' => ['mail.'.$domain, 993, 'ssl'], 'smtp' => ['mail.'.$domain, 465, 'ssl']];
        }

        if ($known === null) {
            foreach (['imap_host', 'smtp_host'] as $need) {
                if (empty($data[$need])) {
                    throw ValidationException::withMessages([$need => __('validation.required', ['attribute' => $need])]);
                }
            }

            return [
                'imap_host' => $data['imap_host'], 'imap_port' => (int) ($data['imap_port'] ?? 993), 'imap_secure' => $data['imap_secure'] ?? 'ssl',
                'smtp_host' => $data['smtp_host'], 'smtp_port' => (int) ($data['smtp_port'] ?? 465), 'smtp_secure' => $data['smtp_secure'] ?? 'ssl',
            ];
        }

        return [
            'imap_host' => $known['imap'][0], 'imap_port' => $known['imap'][1], 'imap_secure' => $known['imap'][2],
            'smtp_host' => $known['smtp'][0], 'smtp_port' => $known['smtp'][1], 'smtp_secure' => $known['smtp'][2],
        ];
    }
}

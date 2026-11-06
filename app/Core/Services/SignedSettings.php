<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Support\CompanyContext;
use App\Models\SettingChange;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ জরুরি সেটিং বদলালে দ্বিতীয় হাত — কন্ট্রোল প্যানেলের পরিকল্পনা, দফা ৭: "অনুমোদন, বাকির সীমা আর বেতনের সেটিং বদলালে দ্বিতীয়
 * সই লাগে (আপনার আগের সিদ্ধান্ত)"; মালিক, ৭ নভেম্বর ২০২৬: "baki kaj gulo kore rako"।
 *
 * ⓘ পর্দা বদলটা সরাসরি বসায় না — এখানে একটা চাওয়া বদল লেখে ([[SettingChange]]); এই কোম্পানির অন্য কেউ, যাঁর সেটিংসের চাবি আছে,
 * অনুমোদন দিলে তবেই সেটিংয়ে বসে। ⛔ যিনি চাইলেন তিনি কখনো নন — মালিকও নন। ⛔ অনুমোদন দেওয়ার মতো আর কেউ না থাকলে বদলটা আটকে
 * যায়, কারণসহ ("fail-closed" — মালিকের ২৪ অক্টোবরের নিয়ম: দ্বিতীয় জন বসানো না থাকলে কাজ হয় না, নীরবে এক হাতে হয় না)।
 *
 * ⓘ কোন সেটিং: অনুমোদনের সব (`approval.*`, প্রচারের অনুমোদন, নোটিশের লেখক নিজে অনুমোদন নয়, বাজেটের বেশি খরচে সই), বাকির সীমার সব
 * (`credit_control.*`, গ্রাহকের সীমা চালু, শূন্য সীমায় আটকানো, সীমা পেরোলে সতর্কতা, মাঠের বাকি), আর বেতনের (হাজিরায় বেতন কাটা)।
 */
final class SignedSettings
{
    public const KEY = 'system_admin.settings.manage';

    public const WAITING = 'system_admin.setting_change_waiting';

    /** @var list<string> */
    private const PREFIXES = ['approval.', 'credit_control.'];

    /** @var list<string> */
    private const KEYS = [
        'customer.credit_limit_enabled', 'customer.zero_limit_blocks', 'customer.alert_over_limit', 'sales.field_credit_limit',
        'hr.attendance_affects_salary',
        'promotion.needs_approval', 'notice.creator_cannot_approve', 'accounts.expense.budget_sign_over_pct',
    ];

    public function __construct(private readonly SettingsService $settings) {}

    public function guards(string $key): bool
    {
        if (in_array($key, self::KEYS, true)) {
            return true;
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * চাওয়া বদল লেখা — `true`: অপেক্ষায় গেল; `false`: অনুমোদন দেওয়ার মতো কেউ নেই, কিছুই লেখা হয়নি (আটকে গেল)।
     */
    public function propose(string $key, mixed $value, User $by): bool
    {
        if ($this->signersExcept($by)->isEmpty()) {
            return false;
        }

        DB::transaction(function () use ($key, $value, $by): void {
            // ⓘ একই সেটিংয়ের পুরনো চাওয়া থাকলে সেটা ছাপিয়ে যায় — শেষ চাওয়াটাই অপেক্ষায়
            SettingChange::query()->where('key', $key)->where('status', SettingChange::PENDING)->lockForUpdate()->get()
                ->each(fn (SettingChange $old) => $old->forceFill(['status' => SettingChange::SUPERSEDED, 'decided_at' => now()])->save());

            $change = SettingChange::create([
                'company_id' => CompanyContext::id(),
                'key' => $key,
                'old_value' => self::stored($this->settings->get($key)),
                'new_value' => self::stored($value),
                'status' => SettingChange::PENDING,
                'requested_by' => $by->id,
            ]);

            app(NotificationService::class)->sendMany(
                $this->signersExcept($by),
                self::WAITING,
                __('system_admin::settings.change_waiting_title'),
                $this->summaryOf($change),
                route('system_admin.control-panel').'#setting-changes',
                key: 'setting:change:'.$change->id,
            );
        });

        return true;
    }

    /**
     * দ্বিতীয় হাত — অন্য একজন; অনুমোদনে সেটিংয়ে বসে।
     *
     * @throws ValidationException
     */
    public function sign(SettingChange $change, User $by): void
    {
        DB::transaction(function () use ($change, $by): void {
            $locked = $this->lockPending($change);
            $this->assertSecondHand($locked, $by);

            $this->settings->set($locked->key, $this->decoded($locked->key, (string) $locked->new_value));
            $locked->forceFill(['status' => SettingChange::SIGNED, 'decided_by' => $by->id, 'decided_at' => now()])->save();
        });
    }

    /** @throws ValidationException */
    public function reject(SettingChange $change, User $by, ?string $note): void
    {
        DB::transaction(function () use ($change, $by, $note): void {
            $locked = $this->lockPending($change);
            $this->assertSecondHand($locked, $by);

            $locked->forceFill(['status' => SettingChange::REJECTED, 'decided_by' => $by->id, 'decided_at' => now(),
                'decision_note' => $note !== null ? mb_substr(trim($note), 0, 500) : null])->save();
        });
    }

    /** @return Collection<int, SettingChange> */
    public function pending(): Collection
    {
        return SettingChange::query()->where('status', SettingChange::PENDING)->with('requester')->orderBy('id')->get();
    }

    public function canSign(SettingChange $change, User $by): bool
    {
        return $change->isPending() && (int) $change->requested_by !== (int) $by->id && $by->can(self::KEY);
    }

    /** সেটিংয়ের নাম: আগে → পরে */
    public function summaryOf(SettingChange $change): string
    {
        return $this->labelOf($change->key).': '.$this->shown($change->key, $change->old_value).' → '.$this->shown($change->key, $change->new_value);
    }

    public function labelOf(string $key): string
    {
        $label = $this->settings->definitions()[$key]['label'] ?? null;

        return is_string($label) ? (string) __($label) : $key;
    }

    public function shown(string $key, ?string $value): string
    {
        if (($this->settings->definitions()[$key]['type'] ?? null) === 'boolean') {
            return $value === '1' ? __('system_admin::settings.change_on') : __('system_admin::settings.change_off');
        }

        return $value === null || $value === '' ? '—' : $value;
    }

    /** ডাটাবেসে যে চেহারায় বসে — [[SettingsController::asStored()]]-এর একই নিয়ম */
    public static function stored(mixed $value): string
    {
        return is_bool($value) ? ($value ? '1' : '0') : (string) (is_scalar($value) ? $value : json_encode($value));
    }

    /** @return Collection<int, User> */
    private function signersExcept(User $by): Collection
    {
        $company = CompanyContext::id();

        return User::query()
            ->where('is_active', true)
            ->whereKeyNot((int) $by->id)
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $company))
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user) => $user->can(self::KEY))
            ->values();
    }

    private function decoded(string $key, string $stored): mixed
    {
        return match ($this->settings->definitions()[$key]['type'] ?? null) {
            'boolean' => $stored === '1',
            'integer' => (int) $stored,
            default => $stored,
        };
    }

    private function lockPending(SettingChange $change): SettingChange
    {
        $locked = SettingChange::query()->whereKey($change->getKey())->lockForUpdate()->first();

        if ($locked === null || ! $locked->isPending()) {
            throw ValidationException::withMessages(['change' => __('system_admin::settings.change_not_pending')]);
        }

        return $locked;
    }

    private function assertSecondHand(SettingChange $change, User $by): void
    {
        // ⛔ যিনি চেয়েছেন তিনি কখনো নন — মালিকও নন
        if ((int) $change->requested_by === (int) $by->id) {
            throw ValidationException::withMessages(['change' => __('system_admin::settings.change_maker_cannot_sign')]);
        }

        if (! $by->can(self::KEY)) {
            throw ValidationException::withMessages(['change' => __('system_admin::settings.change_no_key')]);
        }
    }
}

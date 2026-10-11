<?php

declare(strict_types=1);

namespace App\Core\Notifications;

use App\Core\Support\CompanyContext;
use App\Core\Support\NotificationKinds;
use App\Models\NotificationChannel;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ নিজের পছন্দ লেখা — মাধ্যম, চুপ করা শ্রেণি, ঘনত্ব, নীরব সময়, ছুটির দায়িত্ব; পর্দা আর API দুইটাই এটাই ডাকে
 * (মালিকের স্পেক §৪ "Preferences", §১২ `notification-preferences`, §১৪)।
 *
 * ⛔ ছুটির দায়িত্ব কেবল এই কোম্পানির সক্রিয় সহকর্মীকে, নিজেকে নয়।
 */
final class PreferenceWriter
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(int $userId, array $input, string $prefix = ''): array
    {
        $rules = [
            'channels' => ['nullable', 'array'], 'channels.*' => [Rule::in(NotificationChannel::ALL)],
            'muted' => ['nullable', 'array'], 'muted.*' => [Rule::in(NotificationKinds::CATEGORIES)],
            'frequency' => ['nullable', Rule::in(NotificationPreference::FREQUENCIES)],
            'digest_hour' => ['nullable', 'integer', 'min:0', 'max:23'],
            'digest_day' => ['nullable', 'integer', 'min:0', 'max:6'],
            'quiet_enabled' => ['nullable', 'boolean'],
            'quiet_start' => ['nullable', 'date_format:H:i', 'required_if:quiet_enabled,1,true'],
            'quiet_end' => ['nullable', 'date_format:H:i', 'required_if:quiet_enabled,1,true', 'different:quiet_start'],
            'timezone' => ['nullable', Rule::in(NotificationPreference::ZONES)],
            'delegate_user_id' => ['nullable', 'integer', Rule::in(array_keys(self::colleagues($userId)))],
            'delegate_from' => ['nullable', 'date', 'required_with:delegate_user_id'],
            'delegate_until' => ['nullable', 'date', 'after_or_equal:delegate_from', 'required_with:delegate_user_id'],
        ];

        $validator = Validator::make($input, $rules);

        if ($validator->fails()) {
            // ⓘ ভুলের নাম ফর্মের নামে (`pref.frequency`), যাতে পর্দা ঠিক ঘরের পাশে দেখায়
            $errors = [];

            foreach ($validator->errors()->messages() as $key => $messages) {
                $errors[$prefix.$key] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }

        return $validator->validated();
    }

    /**
     * ⭐ সংরক্ষণ — না-টিক মাধ্যম মানে বন্ধ।
     *
     * @param  array<string, mixed>  $pref  [[validate()]]-এর ফল
     */
    public function save(int $userId, array $pref): NotificationPreference
    {
        $on = (array) ($pref['channels'] ?? []);
        $channels = [];

        foreach (NotificationChannel::ALL as $channel) {
            $channels[$channel] = in_array($channel, $on, true);
        }

        $delegate = ($pref['delegate_user_id'] ?? null) ?: null;

        return NotificationPreference::query()->updateOrCreate(
            ['company_id' => CompanyContext::id(), 'user_id' => $userId],
            [
                'channels' => $channels,
                'muted_categories' => array_values(array_unique((array) ($pref['muted'] ?? []))) ?: null,
                'frequency' => $pref['frequency'] ?? 'instant',
                'digest_hour' => (int) ($pref['digest_hour'] ?? 9),
                'digest_day' => (int) ($pref['digest_day'] ?? 0),
                'quiet_enabled' => (bool) ($pref['quiet_enabled'] ?? false),
                'quiet_start' => ($pref['quiet_start'] ?? null) ?: null,
                'quiet_end' => ($pref['quiet_end'] ?? null) ?: null,
                'timezone' => ($pref['timezone'] ?? null) ?: null,
                'delegate_user_id' => $delegate,
                'delegate_from' => $delegate ? ($pref['delegate_from'] ?? null) : null,
                'delegate_until' => $delegate ? ($pref['delegate_until'] ?? null) : null,
            ],
        );
    }

    /** @return array<int, string> এই কোম্পানির সক্রিয় সহকর্মী — নিজে বাদ */
    public static function colleagues(int $self): array
    {
        return User::query()->withoutGlobalScope('company')
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->where('users.is_active', true)->where('users.id', '!=', $self)
            ->orderBy('name')->pluck('name', 'users.id')->all();
    }
}

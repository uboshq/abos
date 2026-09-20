<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Core\Services\SettingsService;
use App\Modules\Accounts\Events\AccountSaved;
use App\Modules\Accounts\Models\Account;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InstitutionAccount;

/**
 * খাতের ফর্মে বাছা প্রতিষ্ঠান — জোড়াটা বসানো, বদলানো বা খোলা।
 *
 * ⓘ ঘরটা ফর্মে না থাকলে (অর্থ বন্ধ, বা খাতটা ব্যাংকের নয়) পেলোডে
 * `institution_id` আসেই না — তখন কিছু ছোঁয়া হয় না। ফাঁকা এলে জোড়া খোলে।
 *
 * ⚠️ ইভেন্টের শ্রোতা "না" বলে না ([[App\Core\Events\DomainEvent]]): খাতটা
 * ব্যাংক/MFS নয়, বা প্রতিষ্ঠানের ধরন মেলে না — তাহলে চুপচাপ কিছু করে না।
 * ফর্মের ঘরে কেবল মানানসই প্রতিষ্ঠানই ওঠে, তাই সাধারণ পথে এটা ঘটে না।
 */
final class InstitutionFromAccountForm
{
    public function __construct(private readonly SettingsService $settings) {}

    public function handle(AccountSaved $event): void
    {
        if (! array_key_exists('institution_id', $event->payload) || ! $this->settings->get('finance.enabled', true)) {
            return;
        }

        $account = Account::query()->find($event->payload['account_id'] ?? 0);

        if ($account === null) {
            return;
        }

        $id = (int) $event->payload['institution_id'];

        if ($id <= 0) {
            InstitutionAccount::query()->where('account_id', $account->id)->delete();

            return;
        }

        $institution = Institution::query()->find($id);

        $fits = $institution !== null && match ($account->money_kind) {
            Account::BANK => in_array($institution->kind, [Institution::BANK, Institution::NBFI], true),
            Account::MFS => $institution->kind === Institution::MFS,
            default => false,
        };

        if (! $fits) {
            return;
        }

        InstitutionAccount::query()->updateOrCreate(
            ['account_id' => $account->id],
            ['company_id' => $account->company_id, 'institution_id' => $institution->id, 'created_by' => $event->actorId],
        );
    }
}

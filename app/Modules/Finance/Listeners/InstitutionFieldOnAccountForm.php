<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Core\Services\SettingsService;
use App\Modules\Accounts\Events\AccountFormOpened;
use App\Modules\Accounts\Models\Account;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InstitutionAccount;

/**
 * খাতের ফর্মে "কোন প্রতিষ্ঠান" ঘর — কেবল অর্থ চালু থাকলে।
 *
 * ⓘ ঘরটা ঐচ্ছিক। নতুন খাতে সবসময় দেখায় (মা বাছার আগে জানা যায় না খাতটা
 * ব্যাংকের কি না); পুরনো খাতে কেবল ব্যাংক/MFS হলে। জমায় কী হয়, তা
 * [[InstitutionFromAccountForm]]-এ।
 */
final class InstitutionFieldOnAccountForm
{
    public function __construct(private readonly SettingsService $settings) {}

    public function handle(AccountFormOpened $event): void
    {
        if (! $this->settings->get('finance.enabled', true)) {
            return;
        }

        $accountId = (int) ($event->payload['account_id'] ?? 0);
        $moneyKind = $event->payload['money_kind'] ?? null;

        if ($accountId > 0 && ! in_array($moneyKind, [Account::BANK, Account::MFS], true)) {
            return;
        }

        $options = Institution::query()
            ->whereIn('kind', [Institution::BANK, Institution::NBFI, Institution::MFS])
            ->active()
            ->orderBy('name_en')
            ->get()
            ->mapWithKeys(fn (Institution $i) => [$i->id => $i->label()])
            ->all();

        $event->add('finance::coa.institution-field', [
            'options' => $options,
            'selected' => $accountId > 0
                ? InstitutionAccount::query()->where('account_id', $accountId)->value('institution_id')
                : null,
        ]);
    }
}

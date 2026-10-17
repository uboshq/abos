<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Reports\Filters;

use App\Core\Engines\Report\ModelFilterSource;
use App\Modules\Accounts\Models\Account;
use Illuminate\Database\Eloquent\Builder;

/**
 * ⭐ রিপোর্টের "খাত" ছাঁকনির বাছাই — ফোনের রিপোর্ট-পর্দা (১৭ অক্টোবর ২০২৬)। ⓘ ওয়েবের রিপোর্টের পাতার একই তালিকা: পোস্ট করা যায়,
 * চালু খাত ([[ReportController::show()]])। ⓘ নিবন্ধন `account_id` নামে — ইঞ্জিন যে নামে পড়ে; ওয়েবের পাতা নিজের বাছাই-ঘরই আঁকে।
 */
final class AccountFilter extends ModelFilterSource
{
    public function label(): string
    {
        return 'accounts::field.account';
    }

    protected function query(): Builder
    {
        return Account::query()->postable()->active()->orderBy('code');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Reports\Filters;

use App\Core\Engines\Report\ModelFilterSource;
use App\Modules\Accounts\Models\Account;
use Illuminate\Database\Eloquent\Builder;

/**
 * ⭐ মাসওয়ারি নগদের "খাত" — কেবল টাকার খাত (নগদ, ব্যাংক, MFS)। ⓘ একটাই তালিকা দুই দরজার: ওয়েবের পাতা [[accounts()]] ডাকে,
 * ফোন এই উৎস ([[MonthlyCashReport]]-এর `sources`) — তাই দুইটায় একই খাত, একই ক্রম (fe, ১৭ অক্টোবর ২০২৬; a5-এর পর্যালোচনা)।
 */
final class MoneyAccountFilter extends ModelFilterSource
{
    /** ওয়েবের বাছাই-ঘর আর ফোনের তালিকা — একই কোয়েরি */
    public static function accounts(): Builder
    {
        return Account::query()->postable()->active()->money()->orderBy('code');
    }

    public function label(): string
    {
        return 'accounts::field.account';
    }

    protected function query(): Builder
    {
        return self::accounts();
    }
}

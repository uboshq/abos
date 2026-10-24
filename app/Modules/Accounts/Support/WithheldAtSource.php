<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Support;

use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ পক্ষ যা উৎসে কেটে রাখলেন — VDS (১১৩৮ উৎসে কাটা ভ্যাট পাওনা) আর AIT (১১৩৫ অগ্রিম আয়কর) খাতায় ডেবিট (মালিকের নকশা ১০০,
 * দফা ৫; fe-র D2; ২৪ অক্টোবর ২০২৬: রসিদ ভাউচারেও)।
 *
 * ⓘ আদায়ের একই নিয়ম (a6-এর `CollectionService::ledgerLines()` দফা ৫) — টাকার খাতে আসে পাওয়া টাকা − চার্জ, এই দুই সারি কাটা
 * অংশ, আর পক্ষের খাত কমে সবগুলোর যোগে। ⓘ একটা জায়গা, যাতে রসিদ আর আদায় কখনো আলাদা খাতে না বসায় — আদায় এই সাহায্যকারীকে
 * ডাকবে a6-এর মেশানোয়।
 * ⛔ কোডের খাতটা সত্যিই সেই খাত না হলে থামা, বাংলায় কারণসহ ([[StandardChart::holdsItsMeaning()]]) — UB-তে কোড ধরে অন্য খাত
 * খোলা থাকার ঘটনা আগে ঘটেছে, আর ১১৩৫-এ ব্যাংক বসে থাকলে কাটা কর "ব্যাংকে টাকা" হয়ে দেখা দিত।
 */
final class WithheldAtSource
{
    /**
     * @return list<array{account_id: int, debit: string, credit: string, narration: string}>
     */
    public static function debits(string $vds, string $ait, string $narration): array
    {
        $lines = [];

        foreach (['vds' => [$vds, StandardChart::VDS_RECEIVABLE], 'ait' => [$ait, StandardChart::ADVANCE_INCOME_TAX]] as $kind => [$value, $code]) {
            $value = self::money($value);

            if (bccomp($value, '0', 4) <= 0) {
                continue;
            }

            $account = self::account($kind, $code);
            $lines[] = [
                'account_id' => (int) $account->id,
                'debit' => $value,
                'credit' => '0',
                'narration' => trim(__('accounts::field.withheld_'.$kind).' — '.$narration, ' —'),
            ];
        }

        return $lines;
    }

    private static function account(string $kind, string $code): Account
    {
        $account = StandardChart::find($code) ?? throw ValidationException::withMessages([
            $kind.'_amount' => __('accounts::validation.missing_account', ['code' => $code]),
        ]);

        if (! app(StandardChart::class)->holdsItsMeaning($account)) {
            throw ValidationException::withMessages([
                $kind.'_amount' => __('accounts::validation.withheld_by_party_not_its_meaning', ['code' => $code, 'account' => $account->label()]),
            ]);
        }

        return $account;
    }

    /** ⓘ খালি বা অ-সংখ্যা → ০; ঋণাত্মক দরজার নিয়মে আগেই থামে (`min:0`) */
    private static function money(string $value): string
    {
        $value = trim($value);

        return preg_match('/^\d+(\.\d+)?$/', $value) === 1 ? bcadd($value, '0', 4) : '0.0000';
    }
}

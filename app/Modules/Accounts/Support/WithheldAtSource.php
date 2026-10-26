<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Support;

use App\Modules\Accounts\Models\Account;
use Closure;
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

    /**
     * ⭐ কম-বেশির সারি — ছাড় বা রাউন্ডিং (মালিকের নকশা ১০০, দফা ৪; cb-ma-5, ২৫ অক্টোবর ২০২৬)। আদায় ([[CollectionService::ledgerLines()]])
     * আর রসিদ-পরিশোধ ভাউচার ([[\App\Modules\Accounts\Services\WithheldTax::difference()]]) দুজনেই এটা ডাকে — একই খাত, একই পাশ।
     *
     * ⓘ টাকা নেওয়ায় (রসিদ, আদায়): Dr ৪১২০ আদায়ে ছাড় (বিক্রয়ের বিপরীত-আয়, IFRS 15 ¶৫১) বা Dr ৫৩০৫ রাউন্ডিং; পক্ষের পাওনা পুরোটায় কমে।
     * ⓘ টাকা দেওয়ায় (পরিশোধ): Cr ৫১৫০ ক্রয়মূল্যের পার্থক্য (সরবরাহকারীর ছাড় কেনার খরচ কমায় — IAS 2 ¶১১; মজুদের পুরনো স্তরে
     * ঢোকানো যায় না, বিক্রি হয়ে যাওয়া মালে তো নয়ই, তাই কেনা-খরচের পার্থক্যের খাতে) বা Cr ৫৩০৫ রাউন্ডিং; পক্ষের দেনা পুরোটায় কমে।
     * ⓘ খাত খোঁজা ডাকার জায়গার — আদায় নিজের পাহারা আর নিজের কথায় (`status`), ভাউচার নিজের ([[differenceAccount()]])।
     *
     * @param  (Closure(string): Account)|null  $resolve
     * @return list<array{account_id: int, debit: string, credit: string, narration: string}>
     */
    public static function difference(?string $kind, string $amount, string $narration, ?Closure $resolve = null, bool $paying = false): array
    {
        $amount = self::money($amount);

        if (bccomp($amount, '0', 4) <= 0) {
            return [];
        }

        $code = self::differenceCode($kind, $paying);
        $account = $resolve !== null ? $resolve($code) : self::differenceAccount($code);

        return [[
            'account_id' => (int) $account->id,
            'debit' => $paying ? '0' : $amount,
            'credit' => $paying ? $amount : '0',
            'narration' => $narration,
        ]];
    }

    /** ⓘ ছাড় — নেওয়ায় ৪১২০, দেওয়ায় ৫১৫০; বাকি সব রাউন্ডিং ৫৩০৫ (আদায়ের আগের নিয়ম: "ছাড় নয় তো রাউন্ডিং") */
    public static function differenceCode(?string $kind, bool $paying = false): string
    {
        if ($kind === 'discount') {
            return $paying ? StandardChart::PURCHASE_PRICE_VARIANCE : StandardChart::COLLECTION_DISCOUNT;
        }

        return StandardChart::ROUNDING_DIFFERENCE;
    }

    /** ⛔ ভাউচারের খাত — কোডে সেই খাত, পোস্ট করার মতো; নাহলে `difference.amount`-এ বাংলায় কারণ */
    private static function differenceAccount(string $code): Account
    {
        $account = StandardChart::find($code);

        if ($account === null || ! app(StandardChart::class)->holdsItsMeaning($account) || ! Account::query()->postable()->whereKey($account->id)->exists()) {
            throw ValidationException::withMessages([
                'difference.amount' => __('accounts::validation.difference_head_missing', ['code' => $code]),
            ]);
        }

        return $account;
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

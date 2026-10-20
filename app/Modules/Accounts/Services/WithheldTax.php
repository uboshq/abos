<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use Illuminate\Validation\ValidationException;

/**
 * ⭐ উৎসে কর্তিত কর (AIT আর VDS) খাতায় — আন্তর্জাতিক মানের ফাঁক #১ (হিসাব, গুরুতর), ২০ অক্টোবর ২০২৬ (fe; gaps-accounts.md সারি ১)।
 *
 * ── আইন ────────────────────────────────────────────────────────────
 * · আয়কর আইন, ২০২৩ — সপ্তম অংশ (উৎসে কর্তন): সরবরাহ বা ঠিকাদারির পরিশোধে কর্তন (ধারা ৮৯, হার পরিশিষ্ট ১ আর উৎসে কর
 *   বিধিমালা ২০২৪-এ), কর্তনকারী "নির্দিষ্ট ব্যক্তি" ধারা ১৪০-এ (কোম্পানি তার একটা)।
 * · মূল্য সংযোজন কর ও সম্পূরক শুল্ক আইন, ২০১২ — ধারা ৪৯ (উৎসে মূসক কর্তন, VDS); বিধিমালা ২০১৬ আর বছরের SRO-তে হার।
 * · দুই ক্ষেত্রেই কাটা টাকা কোম্পানির নয় — সরকারকে জমা না দেওয়া পর্যন্ত দায় (IAS 37 নয়, নিশ্চিত দায়: IAS 1 ¶৫৪(k)/(n)-এর
 *   "অন্যান্য প্রদেয়")। খরচ হয় পুরো বিলের অঙ্কে (IAS 1 ¶৩২ — খরচ আর দায় একে অপরের সাথে কাটাকাটি হয় না)।
 *
 * ⛔ আগে খরচের ভাউচারে `gross_amount`, `ait_amount`, `vds_amount` কেবল লেখা থাকত, খাতায় যেত না: খাতায় বসত শুধু হাতে দেওয়া টাকা
 * (Dr খরচ নিট / Cr নগদ নিট) — খরচ কম, আর সরকারের পাওনা কোথাও নেই। ফর্মের মন্তব্য আর মাইগ্রেশন বলত "পার্থক্যটা ২১২১-এ যায়",
 * কোড যেত না।
 *
 * ⓘ এখন: Dr খরচ = মোট (হাতে + AIT + VDS) · Cr নগদ/ব্যাংক = হাতে দেওয়া · Cr ২১২১ = AIT · Cr ২১২১ = VDS (আলাদা সারি, আলাদা লেখা —
 * জমা দেওয়ার সময় কোনটা আয়কর আর কোনটা মূসক তা দেখা যায়)। সরকারকে জমা দেওয়া একটা সাধারণ খরচ/পরিশোধ ভাউচার: Dr ২১২১ /
 * Cr ব্যাংক — দায় কমে।
 */
final class WithheldTax
{
    /**
     * @param  list<array<string, mixed>>  $lines  দুই (চার্জসহ তিন) সারির ভাউচার — [[VoucherService::twoLineEntry()]]
     * @param  array<string, mixed>  $input  ফর্ম বা ফোনের মান: `amount`, `gross_amount`, `ait_amount`, `vds_amount`
     * @return list<array<string, mixed>>
     */
    public function onExpense(array $lines, int $expenseAccountId, array $input): array
    {
        $ait = self::money($input['ait_amount'] ?? null);
        $vds = self::money($input['vds_amount'] ?? null);
        $withheld = bcadd($ait, $vds, 4);

        if (bccomp($withheld, '0', 4) <= 0) {
            return $lines;
        }

        $paid = self::money($input['amount'] ?? null);
        $gross = bcadd($paid, $withheld, 4);

        // ⛔ বিলের মোট লিখলে তা মিলতেই হবে — নাহলে কোন অঙ্কটা ভুল তা খাতা বলতে পারত না
        if (filled($input['gross_amount'] ?? null) && bccomp(self::money($input['gross_amount']), $gross, 2) !== 0) {
            throw ValidationException::withMessages([
                'gross_amount' => __('accounts::validation.gross_is_paid_plus_withheld', [
                    'gross' => self::money($input['gross_amount']), 'paid' => $paid, 'ait' => $ait, 'vds' => $vds,
                ]),
            ]);
        }

        $account = StandardChart::find(StandardChart::TAX_DEDUCTED_AT_SOURCE);

        if ($account === null) {
            throw ValidationException::withMessages([
                'ait_amount' => __('accounts::validation.missing_account', ['code' => StandardChart::TAX_DEDUCTED_AT_SOURCE]),
            ]);
        }

        // ⓘ খরচের সারি মোটে — হাতে দেওয়া অঙ্কে কর্তনটা যোগ
        $grown = false;

        foreach ($lines as $i => $line) {
            if (! $grown && (int) $line['account_id'] === $expenseAccountId && bccomp((string) $line['debit'], '0', 4) > 0) {
                $lines[$i]['debit'] = bcadd((string) $line['debit'], $withheld, 4);
                $grown = true;
            }
        }

        if (! $grown) {
            throw ValidationException::withMessages(['to_account_id' => __('accounts::validation.withheld_needs_expense_head')]);
        }

        $narration = $input['narration'] ?? null;

        foreach (['ait' => $ait, 'vds' => $vds] as $kind => $value) {
            if (bccomp($value, '0', 4) > 0) {
                $lines[] = [
                    'account_id' => (int) $account->id,
                    'debit' => '0',
                    'credit' => $value,
                    'narration' => trim(__('accounts::field.withheld_'.$kind).' — '.($narration ?? ''), ' —'),
                ];
            }
        }

        return $lines;
    }

    /** ⓘ খালি বা অ-সংখ্যা → ০; ঋণাত্মক দরজার নিয়মে আগেই থামে (`min:0`) */
    private static function money(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        return preg_match('/^\d+(\.\d+)?$/', $value) === 1 ? bcadd($value, '0', 4) : '0.0000';
    }
}

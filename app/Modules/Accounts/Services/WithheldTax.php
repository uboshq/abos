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
        return $this->withhold($lines, $expenseAccountId, $input, 'accounts::validation.withheld_needs_expense_head');
    }

    /**
     * ⭐ পরিশোধ ভাউচারে উৎসে কর্তন — খরচের একই পথ, পক্ষের খাতে (fe, ২৪ অক্টোবর ২০২৬: রসিদ-পরিশোধে নকশার VDS আর AIT)।
     *
     * ⓘ Dr পক্ষের খাত = মোট (দেওয়া + AIT + VDS) · Cr টাকার খাত = দেওয়া · Cr ২১২১ = AIT · Cr ২১২২ = VDS — [[onExpense()]]-এর হুবহু,
     * কেবল মোটে বাড়ে পক্ষের সারি (সরবরাহকারীর দেনা ২১১১ বা বাছা "যে খাতে বসবে"), খরচের সারি নয়।
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     */
    public function onPayment(array $lines, int $payeeAccountId, array $input): array
    {
        return $this->withhold($lines, $payeeAccountId, $input, 'accounts::validation.withheld_needs_payee_account');
    }

    /**
     * ⭐ রসিদ ভাউচারে — পক্ষ যা কেটে রাখলেন (fe, ২৪ অক্টোবর ২০২৬; আদায়ের দফা ৫-এর একই নিয়ম, [[WithheldAtSource]])।
     *
     * ⓘ লেখা টাকা = হাতে পাওয়া। Dr টাকার খাত = পাওয়া − চার্জ (আগের মতোই) · Dr ১১৩৮ = VDS · Dr ১১৩৫ = AIT · Cr পক্ষের খাত = পাওয়া +
     * VDS + AIT — পক্ষের পাওনা কাটা অংশসহ পুরোটা কমে, কারণ তিনি সেটা সরকারকে দিয়েছেন।
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     */
    public function onReceipt(array $lines, int $payerAccountId, array $input): array
    {
        $debits = \App\Modules\Accounts\Support\WithheldAtSource::debits(
            (string) ($input['vds_amount'] ?? ''), (string) ($input['ait_amount'] ?? ''), (string) ($input['narration'] ?? ''),
        );

        if ($debits === []) {
            return $lines;
        }

        $withheld = array_reduce($debits, fn (string $sum, array $line) => bcadd($sum, $line['debit'], 4), '0');
        $grown = false;

        foreach ($lines as $i => $line) {
            if (! $grown && (int) $line['account_id'] === $payerAccountId && bccomp((string) $line['credit'], '0', 4) > 0) {
                $lines[$i]['credit'] = bcadd((string) $line['credit'], $withheld, 4);
                $grown = true;
            }
        }

        if (! $grown) {
            throw ValidationException::withMessages(['from_account_id' => __('accounts::validation.withheld_needs_payer_account')]);
        }

        return [...$lines, ...$debits];
    }

    /**
     * ⭐ কম-বেশি — ছাড় বা রাউন্ডিং (মালিকের নকশা ১০০, দফা ৪; cb-ma-5, ২৫ অক্টোবর ২০২৬)। সারির নিয়ম আদায়ের সাথে ভাগ করা
     * ([[\App\Modules\Accounts\Support\WithheldAtSource::difference()]]); এখানে কেবল পক্ষের সারি পুরোটায় বাড়ে।
     *
     * ⓘ রসিদ: Dr ৪১২০/৫৩০৫ = পার্থক্য, Cr পক্ষ = পাওয়া + VDS + AIT + পার্থক্য — গ্রাহকের পাওনা পুরো বিলে কমে।
     * ⓘ পরিশোধ: Dr পক্ষ = মোট + পার্থক্য, Cr ৫১৫০/৫৩০৫ = পার্থক্য — সরবরাহকারীর দেনা পুরো বিলে কমে; ছাড় কেনার খরচ কমায় (IAS 2 ¶১১)।
     * ⛔ পক্ষের সারি না পেলে থামা — পার্থক্য কার খাতে বসবে বলা যায় না।
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     */
    public function difference(array $lines, string $type, array $input): array
    {
        $paying = $type === \App\Modules\Accounts\Models\Voucher::PAYMENT;
        $kind = isset($input['difference_kind']) ? (string) $input['difference_kind'] : null;
        $narration = (string) ($input['narration'] ?? '');
        $label = __('accounts::field.difference_'.($kind === 'discount' ? 'discount' : 'rounding'));
        $extra = \App\Modules\Accounts\Support\WithheldAtSource::difference(
            $kind, (string) ($input['difference_amount'] ?? ''), trim($label.' — '.$narration, ' —'), paying: $paying,
        );

        if ($extra === []) {
            return $lines;
        }

        $amount = $paying ? $extra[0]['credit'] : $extra[0]['debit'];
        $partyAccountId = (int) ($paying ? ($input['to_account_id'] ?? 0) : ($input['from_account_id'] ?? 0));
        $side = $paying ? 'debit' : 'credit';
        $grown = false;

        foreach ($lines as $i => $line) {
            if (! $grown && (int) $line['account_id'] === $partyAccountId && bccomp((string) ($line[$side] ?? '0'), '0', 4) > 0) {
                $lines[$i][$side] = bcadd((string) $line[$side], $amount, 4);
                $grown = true;
            }
        }

        if (! $grown) {
            throw ValidationException::withMessages(['difference.amount' => __('accounts::validation.difference_needs_party_account')]);
        }

        return [...$lines, ...$extra];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     */
    private function withhold(array $lines, int $debitAccountId, array $input, string $noLineMessage): array
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

        // ⭐ AIT ২১২১-এ, VDS ২১২২-এ — আলাদা রিটার্ন, আলাদা খাত (fe, ২১ অক্টোবর ২০২৬)
        $accounts = [];
        foreach (['ait' => StandardChart::TAX_DEDUCTED_AT_SOURCE, 'vds' => StandardChart::VDS_PAYABLE] as $kind => $code) {
            // ⓘ চার্টের find() মানে-হারানো খাতে null দেয় (main, ২৫ অক্টোবর ২০২৬) — তখনো "নেই" নয়, কোডে কী বসে আছে তা বলা (fe, ২৬ অক্টোবর)
            $account = StandardChart::find($code) ?? \App\Modules\Accounts\Models\Account::query()->postable()->where('code', $code)->first();
            $accounts[$kind] = $account ?? throw ValidationException::withMessages([
                $kind.'_amount' => __('accounts::validation.missing_account', ['code' => $code]),
            ]);

            // ⛔ কোডে অন্য খাত বসে থাকলে (UB-তে কোড ধরে অন্য খাত খোলা ছিল) সরকারের দায় সেখানে নয় — থামা, বাংলায় কারণসহ ([[StandardChart::holdsItsMeaning()]])
            if (! app(StandardChart::class)->holdsItsMeaning($accounts[$kind])) {
                throw ValidationException::withMessages([
                    $kind.'_amount' => __('accounts::validation.withheld_account_not_its_meaning', ['code' => $code, 'account' => $accounts[$kind]->label()]),
                ]);
            }
        }

        // ⓘ খরচের সারি মোটে — হাতে দেওয়া অঙ্কে কর্তনটা যোগ
        $grown = false;

        foreach ($lines as $i => $line) {
            if (! $grown && (int) $line['account_id'] === $debitAccountId && bccomp((string) $line['debit'], '0', 4) > 0) {
                $lines[$i]['debit'] = bcadd((string) $line['debit'], $withheld, 4);
                $grown = true;
            }
        }

        if (! $grown) {
            throw ValidationException::withMessages(['to_account_id' => __($noLineMessage)]);
        }

        $narration = $input['narration'] ?? null;

        foreach (['ait' => $ait, 'vds' => $vds] as $kind => $value) {
            if (bccomp($value, '0', 4) > 0) {
                $lines[] = [
                    'account_id' => (int) $accounts[$kind]->id,
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

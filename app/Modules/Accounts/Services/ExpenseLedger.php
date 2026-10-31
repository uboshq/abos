<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Services\PartyRegistry;
use App\Core\Support\CompanyContext;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ খরচের লাইন থেকে জাবেদা — খরচের ভাউচার ১০০%, ভাগ C (fe অনুমোদিত §১/§২, ২৪ অক্টোবর ২০২৬; expense-100-workplan.md §২.২)।
 *
 * প্রতিটা ব্যবসার লাইন ([[VoucherExpenseLine]]) থেকে:
 *   · Dr খরচের খাত — মূল্য + অসমন্বয়যোগ্য ভ্যাট, লাইনের খরচ-কেন্দ্রসহ (⛔ আগে খরচ-কেন্দ্র মাথায় থাকত, খাতায় নামত না — ফাঁক ১)
 *   · Dr 1139 সমন্বয়যোগ্য ক্রয়-ভ্যাট — ভ্যাট দাবি করা গেলে, আলাদা সারি
 * অন্য পাশ, পদ্ধতি ধরে:
 *   · টাকার পদ্ধতি — Cr টাকার খাত = মোট − AIT − VDS (চার্জ থাকলে Dr চার্জের খাত, Cr টাকা + চার্জ — [[VoucherService::paidWithCharge()]]-এর
 *     একই নিয়ম)
 *   · বাকিতে — Cr পক্ষের দেনার খাত (সরবরাহকারী: যে দেনার খাত বাছা; কর্মী: 1131) — টাকা নড়ে না
 *   · কর্মী দিলেন — Cr 1131 কর্মীর অগ্রিম, পক্ষ কর্মী (fe-র সিদ্ধান্ত ৩: SAP-এর employee-vendor, নিট অবস্থান)
 *   · Cr 2121 AIT, Cr 2122 VDS — [[WithheldTax::onExpense()]]-এর একই খাত
 *
 * ⓘ ওয়েবের জমা, প্রিভিউ (simulate) আর ফোন — তিনজনই [[VoucherWriter::linesFor()]] ডাকে, যা এটাকে ডাকে; তাই প্রিভিউ যা দেখায়, পোস্ট
 * ঠিক তা-ই লেখে। ⓘ খাতার প্রতিটা সারির বিবরণ "পক্ষ · বিল নম্বর · লাইনের বিবরণ" — বাইরের রেফারেন্স কেবল, নিজের কাগজের নম্বর কখনো নয়।
 *
 * ⛔ যা নেওয়া হয় না, নিজের বার্তায় ফেরে (fe-র সিদ্ধান্ত, ২৪ অক্টোবর ২০২৬; পর্দায় এ দুটোর ঘরই থাকবে না — ভাগ F):
 *   · আগাম লাইন — আগামের খাত আর মাসিক সরানোর সূচি ec-এর ভাগ H-এ; তার আগে পুরো খরচ হয়ে বসত
 *   · অন্য শাখার লাইন — এক ভাউচারে এক শাখা, শাখার মধ্যে দেনা-পাওনা (due-to/due-from) আসার আগে; লাইনের `branch_id` ঘর থাকে, খালি বা মাথার শাখা
 */
final class ExpenseLedger
{
    public function __construct(
        private readonly VoucherService $vouchers,
        private readonly StandardChart $chart,
        private readonly PartyRegistry $parties,
    ) {}

    /** অনুরোধে খরচের লাইন আছে কি না — না থাকলে পুরনো এক-লাইনের পথ (পুরনো অ্যাপ, পুরনো খসড়া) */
    public static function hasLines(array $input): bool
    {
        return collect((array) ($input['expense_lines'] ?? []))
            ->contains(fn ($l) => is_array($l) && (int) ($l['expense_account_id'] ?? 0) > 0);
    }

    /**
     * @param  array<string, mixed>  $input  ফর্ম বা ফোনের মান (`expense_lines`, `instrument`, `from_account_id`, পক্ষ, `employee_id`,
     *                                      `bill_no`, `narration`, `charge_amount`, `ait_amount`, `vds_amount`, `branch_id`)
     * @return list<array<string, mixed>>
     */
    public function lines(array $input): array
    {
        $rows = collect((array) ($input['expense_lines'] ?? []))
            ->filter(fn ($l) => is_array($l) && (int) ($l['expense_account_id'] ?? 0) > 0)
            ->values();

        $instrument = (string) ($input['instrument'] ?? '');
        $byEmployee = $instrument === Voucher::BY_EMPLOYEE
            || ($instrument === Voucher::ON_CREDIT && ($input['party_type'] ?? null) === 'employee');
        $headBranch = filled($input['branch_id'] ?? null) ? (int) $input['branch_id'] : CompanyContext::branchId();
        $say = $this->narrator($input);

        $out = [];
        $gross = '0';

        foreach ($rows as $index => $row) {
            if (filter_var($row['is_prepaid'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                throw ValidationException::withMessages([
                    "expense_lines.{$index}.is_prepaid" => __('accounts::validation.expense_prepaid_not_taken'),
                ]);
            }

            if (filled($row['branch_id'] ?? null) && $headBranch !== null && (int) $row['branch_id'] !== $headBranch) {
                throw ValidationException::withMessages([
                    "expense_lines.{$index}.branch_id" => __('accounts::validation.expense_one_branch_per_voucher'),
                ]);
            }

            $amount = self::money($row['amount'] ?? null);
            $vat = self::money($row['vat_amount'] ?? null);
            $recoverable = filter_var($row['vat_recoverable'] ?? false, FILTER_VALIDATE_BOOLEAN) && bccomp($vat, '0', 4) > 0;

            $out[] = [
                'account_id' => (int) $row['expense_account_id'],
                'debit' => $recoverable ? $amount : bcadd($amount, $vat, 4),
                'credit' => '0',
                'cost_center_id' => filled($row['cost_center_id'] ?? null) ? (int) $row['cost_center_id'] : null,
                'narration' => $say($row['description'] ?? null),
            ];

            if ($recoverable) {
                $out[] = [
                    'account_id' => (int) $this->meaningful(StandardChart::INPUT_VAT_RECOVERABLE, "expense_lines.{$index}.vat_recoverable")->id,
                    'debit' => $vat,
                    'credit' => '0',
                    'narration' => $say(__('accounts::field.input_vat_recoverable')),
                ];
            }

            $gross = bcadd($gross, bcadd($amount, $vat, 4), 4);
        }

        $ait = self::money($input['ait_amount'] ?? null);
        $vds = self::money($input['vds_amount'] ?? null);
        $paid = bcsub(bcsub($gross, $ait, 4), $vds, 4);

        if (bccomp($paid, '0', 4) <= 0) {
            throw ValidationException::withMessages(['amount' => __('accounts::validation.amount_must_be_positive')]);
        }

        // ── অন্য পাশ ──
        if ($byEmployee) {
            $employee = (int) ($instrument === Voucher::BY_EMPLOYEE ? ($input['employee_id'] ?? 0) : ($input['party_id'] ?? 0));
            $out[] = [
                'account_id' => (int) $this->meaningful(StandardChart::EMPLOYEE_ADVANCE, 'employee_id')->id,
                'debit' => '0',
                'credit' => $paid,
                'party_type' => 'employee',
                'party_id' => $employee > 0 ? $employee : null,
                'narration' => $say($input['narration'] ?? null),
            ];
        } else {
            $from = (int) ($input['from_account_id'] ?? 0);
            $charge = self::money($input['charge_amount'] ?? null);

            // ⓘ চার্জ কেবল টাকা বেরোলে — বাকিতে কোনো ব্যাংক কাটে না
            if (bccomp($charge, '0', 4) > 0 && $instrument !== Voucher::ON_CREDIT) {
                $out[] = [
                    'account_id' => (int) $this->vouchers->chargeAccountFor(Account::query()->find($from))->id,
                    'debit' => $charge,
                    'credit' => '0',
                    'narration' => $say($input['narration'] ?? null),
                ];
                $paid = bcadd($paid, $charge, 4);
            }

            $out[] = ['account_id' => $from, 'debit' => '0', 'credit' => $paid, 'narration' => $say($input['narration'] ?? null)];
        }

        // ── উৎসে কর্তন — [[WithheldTax::onExpense()]]-এর একই খাত, আলাদা সারি ──
        foreach (['ait' => [$ait, StandardChart::TAX_DEDUCTED_AT_SOURCE], 'vds' => [$vds, StandardChart::VDS_PAYABLE]] as $kind => [$value, $code]) {
            if (bccomp($value, '0', 4) > 0) {
                $account = StandardChart::find($code) ?? throw ValidationException::withMessages([
                    $kind.'_amount' => __('accounts::validation.missing_account', ['code' => $code]),
                ]);
                $out[] = ['account_id' => (int) $account->id, 'debit' => '0', 'credit' => $value, 'narration' => $say(__('accounts::field.withheld_'.$kind))];
            }
        }

        return $out;
    }

    /**
     * খাতার বিবরণ — "পক্ষ · বিল নম্বর · লাইনের বিবরণ"; ফাঁকা অংশ বাদ, " · " একবারই। ⛔ নিজের কাগজের নম্বর কখনো নয় — খাতায় সেটা
     * আলাদা ঘরে (`document_no`) থাকেই, বিবরণে বসালে দুবার।
     *
     * @return \Closure(?string): ?string
     */
    private function narrator(array $input): \Closure
    {
        $type = (string) ($input['party_type'] ?? '');
        $id = (int) ($input['party_id'] ?? 0);
        $party = $type !== '' && $id > 0 ? ($this->parties->labelsOf([[$type, $id]])[$type.':'.$id] ?? null) : null;
        $bill = trim((string) ($input['bill_no'] ?? ''));

        return function (?string $what) use ($party, $bill): ?string {
            $text = implode(' · ', array_filter([$party, $bill, trim((string) $what)], fn ($p) => $p !== null && $p !== ''));

            return $text === '' ? null : mb_substr($text, 0, 500);
        };
    }

    /** কোডের খাত মান ছকের সেই খাতই কি না ([[StandardChart::holdsItsMeaning()]]) — না হলে বাংলায় কারণ দিয়ে থামা */
    private function meaningful(string $code, string $field): Account
    {
        $account = StandardChart::find($code);

        if ($account === null || ! $this->chart->holdsItsMeaning($account)) {
            // ⓘ বার্তায় কোডে আসলে যে খাত বসে আছে তার নাম — main-এ [[StandardChart::find()]] মানে-হারানো খাতে null ফেরায়, তাই সেখান
            //   থেকে নাম নিলে বার্তা কেবল কোড বলত (fe, ৩১ অক্টোবর ২০২৬, main মেশানোর পরে Mac-এ ধরা)
            // ⓘ postable() — দল-খাত কখনো খোঁজায় নয় ([[MoneyNeverLandsOnAGroupAccountTest]]); দল বসে থাকলে বার্তা কোড বলে
            $sitting = Account::query()->postable()->where('code', $code)->first();

            throw ValidationException::withMessages([
                $field => __('accounts::validation.account_lost_its_meaning', ['code' => $code, 'account' => $sitting?->label() ?? $code]),
            ]);
        }

        return $account;
    }

    private static function money(mixed $value): string
    {
        $value = str_replace(',', '', trim((string) ($value ?? '')));

        return preg_match('/^\d+(\.\d+)?$/', $value) === 1 ? bcadd($value, '0', 4) : '0.0000';
    }
}

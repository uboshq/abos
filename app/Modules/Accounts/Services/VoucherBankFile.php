<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Contracts\PayeeBankAccount;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Models\VoucherLine;

/**
 * ⭐ ব্যাংক ফাইল (BEFTN) — এক ব্যাংক খাত থেকে এক দিনের ট্রান্সফারে দেওয়া পরিশোধ, প্রাপকের সই-পাওয়া হিসাবে (নকশার "যা পরের ধাপে":
 * "ব্যাংক ফাইল", আর নিয়ন্ত্রণের লেখা "ব্যাংক ফাইলে (BEFTN) যাবে"; cb-ma-4, ২৫ অক্টোবর ২০২৬; SAP F110-এর payment medium)।
 *
 * ⓘ কেবল পাকা পরিশোধ ভাউচার, পদ্ধতি ট্রান্সফার, এই খাত থেকে (টাকার খাতের ক্রেডিট সারি), এই তারিখে। ⓘ অঙ্ক নিট — প্রাপকের হাতে যা যায়
 * (খাত থেকে বেরোনো − চার্জ; চার্জ কোম্পানির খরচ, ব্যাংককে পাঠানো নয়)। ⛔ প্রাপকের হিসাব কেবল দুজনের সইয়ে যাচাই-করা ([[PayeeBankAccount]]) —
 * না থাকলে সারিটা যায় না, গোনা হয় (`skipped`), যাতে কেউ ভাবে না ফাইলে সব আছে।
 * ⓘ বেতনের ব্যাংক ফাইলের একই কলাম আর একই সূত্র-পাহারা ([[\App\Modules\Hr\Services\PayrollService::bankFile()]]): ব্যাংক একই আকার চেনে।
 */
final class VoucherBankFile
{
    public function __construct(private readonly PayeeBankAccount $banks) {}

    /**
     * @return array{name: string, content: string, rows: int, skipped: int}
     */
    public function of(Account $from, string $date): array
    {
        $vouchers = Voucher::query()
            ->posted()
            ->where('type', Voucher::PAYMENT)
            ->where('instrument', 'transfer')
            ->where('trx_date', $date)
            ->whereHas('lines', fn ($q) => $q->where('account_id', $from->id)->where('credit', '>', 0))
            ->with('lines')
            ->orderBy('id')
            ->get();

        $rows = [['Account No', 'Account Name', 'Amount', 'Routing No', 'Bank', 'Payee Code', 'Reference']];
        $skipped = 0;

        foreach ($vouchers as $voucher) {
            $bank = $voucher->party_type === null || $voucher->party_id === null
                ? null
                : $this->banks->accountOf((string) $voucher->party_type, (int) $voucher->party_id);

            if ($bank === null) {
                $skipped++;

                continue;
            }

            $out = $voucher->lines
                ->filter(fn (VoucherLine $l) => (int) $l->account_id === (int) $from->id)
                ->reduce(fn (string $sum, VoucherLine $l) => bcadd($sum, (string) $l->credit, 4), '0');
            $net = bcsub($out, (string) ($voucher->charge_amount ?? '0'), 4);

            if (bccomp($net, '0', 4) <= 0) {
                continue;
            }

            $rows[] = [
                $bank['account_no'],
                $bank['account_name'],
                Money::round($net, 2),
                $bank['routing_no'],
                trim($bank['bank'].' '.$bank['branch']),
                (string) $voucher->party_id,
                trim($voucher->document_no.' '.($voucher->instrument_no ?? '')),
            ];
        }

        $csv = '';

        foreach ($rows as $row) {
            $csv .= implode(',', array_map(self::field(...), $row))."\r\n";
        }

        return [
            'name' => 'beftn-'.$from->code.'-'.$date.'.csv',
            'content' => $csv,
            'rows' => count($rows) - 1,
            'skipped' => $skipped,
        ];
    }

    /** ⓘ সূত্রের মতো শুরু হলে আগে ' (এক্সেলে খুলে সূত্র চলবে না), কমা বা উদ্ধৃতি থাকলে উদ্ধৃতিতে — বেতনের ফাইলের একই নিয়ম */
    private static function field(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $value = "'".$value;
        }

        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}

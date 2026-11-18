<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ ব্যাংকের নিজের স্টেটমেন্ট ফাইল পড়া — CSV বা XLSX, ব্যাংক যেমন দেয় তেমন (জমার খবর ধাপ গ, ১৬ নভেম্বর ২০২৬)।
 *
 * ⓘ সাধারণ ইমপোর্ট ([[BankStatementImporter]]) আমাদের ছকের মাথা চায় (`account_code`, `trx_date` …); ব্যাংকের ফাইলে মাথা থাকে
 * "Txn Date", "Particulars", "Withdrawal", "Deposit" — আর উপরে কয়েক সারি ব্যাংকের নাম-ঠিকানা। এখানে মাথার সারিটা খুঁজে নেওয়া হয়
 * (প্রথম ২০ সারির মধ্যে যেখানে তারিখ আর জমা/উত্তোলনের ঘর আছে), আর নামগুলো চেনা তালিকা থেকে মেলে। খাত আসে পর্দা থেকে।
 * ⓘ টাকা আর তারিখের নোংরা কাজ [[BankStatementService]]-এর একই নিয়মে — d/m/Y আগে, m/d/Y কখনো নয়; এক্সেলের দিন-সংখ্যাও চেনা।
 * ⛔ XLSX পড়া হয় কেবল প্রথম পাতা, কোনো বাইরের লাইব্রেরি ছাড়া (zip + XML), বাইরের entity ছাড়া।
 */
final class BankStatementFile
{
    public const MAX_ROWS = 5000;

    /** @var array<string, list<string>> ঘরের নাম → চেনা মাথা (ছোট হাত, অক্ষর-অঙ্ক ছাড়া সব বাদ) */
    // ⓘ তারিখের ঘর ভেতরে `on` — বাইরে `trx_date` ([[rows()]]); চেনা নামের তালিকা কোনো যাচাইয়ের নিয়ম নয়
    private const HEADS = [
        'on' => ['date', 'trxdate', 'txndate', 'transactiondate', 'trandate', 'valuedate', 'postingdate', 'postdate', 'তারিখ'],
        'description' => ['description', 'narration', 'particulars', 'details', 'remarks', 'transactiondetails', 'বিবরণ'],
        'reference' => ['reference', 'ref', 'refno', 'referenceno', 'chequeno', 'chqno', 'chequenumber', 'instrumentno', 'trxid', 'transactionid', 'রেফারেন্স'],
        'debit' => ['debit', 'withdrawal', 'withdrawals', 'withdrawn', 'dr', 'debitamount', 'উত্তোলন', 'ডেবিট'],
        'credit' => ['credit', 'deposit', 'deposits', 'deposited', 'cr', 'creditamount', 'জমা', 'ক্রেডিট'],
        'balance' => ['balance', 'runningbalance', 'closingbalance', 'জের', 'ব্যালেন্স'],
    ];

    /**
     * ফাইলের সারিগুলো, আমাদের নামে — `[সারি নম্বর => [trx_date, description, reference, debit, credit, balance]]`।
     *
     * @return array<int, array<string, string>>
     */
    public function rows(UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $table = $extension === 'xlsx' ? $this->xlsx((string) $file->getRealPath()) : $this->csv((string) $file->getRealPath());

        [$at, $map] = $this->header($table);

        $rows = [];
        foreach (array_slice($table, $at + 1, null, true) as $n => $cells) {
            if (count(array_filter($cells, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw ValidationException::withMessages(['file' => __('core.import.too_many_rows', ['max' => number_format(self::MAX_ROWS)])]);
            }

            $row = [];
            foreach (array_keys(self::HEADS) as $name) {
                $row[$name === 'on' ? 'trx_date' : $name] = isset($map[$name]) ? trim((string) ($cells[$map[$name]] ?? '')) : '';
            }
            $rows[$n + 1] = $row;
        }

        return $rows;
    }

    /**
     * ⓘ এক্সেলের তারিখ কখনো দিন-সংখ্যা (১৯০০ থেকে) — "46342" মানে ২০২৬-এর নভেম্বর। বাকিটা [[BankStatementService::dateOf()]]।
     */
    public function dateOf(BankStatementService $lines, string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^\d{5}(\.\d+)?$/', $value) === 1) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value)->toDateString();
        }

        // ⓘ "16/11/2026 10:02" — সময় বাদ
        $value = (string) preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?(\s?[AaPp][Mm])?$/', '', $value);

        return $lines->dateOf($value);
    }

    /**
     * @param  array<int, list<string>>  $table
     * @return array{0: int, 1: array<string, int>}
     */
    private function header(array $table): array
    {
        foreach (array_slice($table, 0, 20, true) as $at => $cells) {
            $map = [];
            foreach ($cells as $i => $cell) {
                $plain = mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]/u', '', (string) $cell));
                foreach (self::HEADS as $name => $known) {
                    if (! isset($map[$name]) && in_array($plain, $known, true)) {
                        $map[$name] = $i;
                    }
                }
            }

            if (isset($map['on']) &&(isset($map['credit']) || isset($map['debit']))) {
                return [$at, $map];
            }
        }

        throw ValidationException::withMessages(['file' => __('accounts::import.file_no_header')]);
    }

    /** @return array<int, list<string>> */
    private function csv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => __('core.import.empty_file')]);
        }

        $table = [];
        try {
            while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $table[] = array_map(fn ($v) => str_replace("\u{FEFF}", '', (string) $v), $values);
                if (count($table) > self::MAX_ROWS + 25) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        return $table;
    }

    /** @return array<int, list<string>> */
    private function xlsx(string $path): array
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => __('accounts::import.file_unreadable')]);
        }

        try {
            $strings = [];
            $shared = $zip->getFromName('xl/sharedStrings.xml');
            if (is_string($shared) && $shared !== '') {
                $xml = simplexml_load_string($shared, \SimpleXMLElement::class, LIBXML_NONET);
                foreach ($xml === false ? [] : $xml->si as $si) {
                    $strings[] = isset($si->t) ? (string) $si->t : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($si->r, false)));
                }
            }

            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            $xml = is_string($sheet) ? simplexml_load_string($sheet, \SimpleXMLElement::class, LIBXML_NONET) : false;
            if ($xml === false) {
                throw ValidationException::withMessages(['file' => __('accounts::import.file_unreadable')]);
            }

            $table = [];
            foreach ($xml->sheetData->row as $row) {
                $cells = [];
                foreach ($row->c as $c) {
                    $index = $this->columnIndex((string) $c['r']);
                    $type = (string) $c['t'];
                    $value = match ($type) {
                        's' => $strings[(int) $c->v] ?? '',
                        'inlineStr' => (string) $c->is->t,
                        default => (string) $c->v,
                    };
                    $cells[$index ?? count($cells)] = $value;
                }
                if ($cells === []) {
                    $table[] = [];

                    continue;
                }
                $line = array_fill(0, max(array_keys($cells)) + 1, '');
                foreach ($cells as $i => $v) {
                    $line[$i] = $v;
                }
                $table[] = $line;
                if (count($table) > self::MAX_ROWS + 25) {
                    break;
                }
            }

            return $table;
        } finally {
            $zip->close();
        }
    }

    /** "C12" → 2 */
    private function columnIndex(string $ref): ?int
    {
        if (preg_match('/^([A-Z]+)\d+$/', $ref, $m) !== 1) {
            return null;
        }

        $n = 0;
        foreach (str_split($m[1]) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }
}

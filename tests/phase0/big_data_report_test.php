<?php

/**
 * Phase 0 — ঝুঁকি পরীক্ষা ২: শেয়ার্ড cPanel-এ বড় ডাটায় রিপোর্ট টাইমআউট করে কি না।
 *
 * প্ল্যানের সেকশন ৯ ও ১২ বলে: শেয়ার্ড হোস্টে execution time ও memory কম, তাই
 * বড় রিপোর্ট আগেই যাচাই করতে হবে। এখানে ledger_entries-এর মতো একটা টেবিলে
 * ১ লাখ রো বসিয়ে তিনটা আসল রিপোর্ট-কোয়েরি চালানো হয়।
 *
 * চালানো:  php tests/phase0/big_data_report_test.php
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const ROWS = 100_000;
const BUDGET_SECONDS = 2.0;   // শেয়ার্ড হোস্টে এর বেশি লাগলে ঝুঁকি

function ms(callable $fn): array
{
    $t = microtime(true);
    $result = $fn();
    return [round((microtime(true) - $t) * 1000, 1), $result];
}

echo "Phase 0 — বড় ডাটায় রিপোর্ট পরীক্ষা\n";
echo str_repeat('-', 62), "\n";

DB::statement('DROP TABLE IF EXISTS phase0_ledger');
DB::statement("
    CREATE TABLE phase0_ledger (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        company_id   BIGINT UNSIGNED NOT NULL,
        branch_id    BIGINT UNSIGNED NOT NULL,
        account_id   BIGINT UNSIGNED NOT NULL,
        trx_date     DATE NOT NULL,
        debit        DECIMAL(18,4) NOT NULL DEFAULT 0,
        credit       DECIMAL(18,4) NOT NULL DEFAULT 0,
        source_type  VARCHAR(64) NOT NULL,
        source_id    BIGINT UNSIGNED NOT NULL,
        narration    VARCHAR(255) NULL,
        created_at   TIMESTAMP NULL,
        INDEX idx_company_date   (company_id, trx_date),
        INDEX idx_company_acct   (company_id, account_id, trx_date),
        INDEX idx_source         (source_type, source_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ---------- ১. ডাটা বসানো ----------
$types = ['sales_invoice', 'purchase_invoice', 'receipt_voucher', 'payment_voucher', 'journal_voucher'];
[$insertMs] = ms(function () use ($types) {
    $chunk = 2000;
    for ($i = 0; $i < ROWS; $i += $chunk) {
        $rows = [];
        for ($j = 0; $j < $chunk; $j++) {
            $n = $i + $j;
            $debit = $n % 2 === 0 ? round(mt_rand(100, 500000) / 100, 4) : 0;
            $credit = $debit > 0 ? 0 : round(mt_rand(100, 500000) / 100, 4);
            $rows[] = [
                'company_id'  => ($n % 3) + 1,
                'branch_id'   => ($n % 7) + 1,
                'account_id'  => ($n % 120) + 1,
                'trx_date'    => date('Y-m-d', strtotime('2025-01-01 +' . ($n % 400) . ' days')),
                'debit'       => $debit,
                'credit'      => $credit,
                'source_type' => $types[$n % 5],
                'source_id'   => intdiv($n, 2) + 1,
                'narration'   => 'বিবরণ ' . $n,
                'created_at'  => date('Y-m-d H:i:s'),
            ];
        }
        DB::table('phase0_ledger')->insert($rows);
    }
});
$count = DB::table('phase0_ledger')->count();
printf("বসানো হলো      %s রো  —  %.1f ms  (%.0f রো/সেকেন্ড)\n", number_format($count), $insertMs, $count / ($insertMs / 1000));
echo str_repeat('-', 62), "\n";

$results = [];

// ---------- ২. Trial Balance ----------
[$t, $rows] = ms(fn () => DB::table('phase0_ledger')
    ->where('company_id', 1)
    ->whereBetween('trx_date', ['2025-01-01', '2025-12-31'])
    ->groupBy('account_id')
    ->selectRaw('account_id, SUM(debit) AS dr, SUM(credit) AS cr')
    ->get());
$results['Trial Balance (এক বছর, ১২০ হিসাব)'] = [$t, $rows->count() . ' রো'];

// ---------- ৩. এক হিসাবের লেজার + চলমান ব্যালেন্স ----------
[$t, $rows] = ms(fn () => DB::select("
    SELECT id, trx_date, debit, credit, source_type, source_id,
           SUM(debit - credit) OVER (ORDER BY trx_date, id) AS running_balance
    FROM phase0_ledger
    WHERE company_id = 1 AND account_id = 7
      AND trx_date BETWEEN '2025-01-01' AND '2025-12-31'
    ORDER BY trx_date, id
"));
$results['Ledger + চলমান ব্যালেন্স (এক হিসাব)'] = [$t, count($rows) . ' রো'];

// ---------- ৪. পেজিনেটেড লিস্ট (Day Book-এর মতো) ----------
[$t, $rows] = ms(fn () => DB::table('phase0_ledger')
    ->where('company_id', 1)
    ->whereBetween('trx_date', ['2025-06-01', '2025-06-30'])
    ->orderBy('trx_date')->orderBy('id')
    ->limit(50)->offset(0)->get());
$results['Day Book — প্রথম পাতা (৫০ রো)'] = [$t, $rows->count() . ' রো'];

// ---------- ৫. গভীর পেজ — শেয়ার্ড হোস্টের আসল ফাঁদ ----------
// offset কোম্পানির নিজের রো-সংখ্যার ভেতরে হতে হবে, নাহলে কোয়েরি ফাঁকা ফেরত দেয় ও কিছুই মাপে না
$companyRows = DB::table('phase0_ledger')->where('company_id', 1)->count();
$deepOffset = intdiv($companyRows, 2);
[$t, $rows] = ms(fn () => DB::table('phase0_ledger')
    ->where('company_id', 1)
    ->orderBy('trx_date')->orderBy('id')
    ->limit(50)->offset($deepOffset)->get());
$results["গভীর পেজ — offset " . number_format($deepOffset)] = [$t, $rows->count() . ' রো'];

// ---------- ৬. ড্রিল-ডাউন (source_type + source_id) ----------
// ডাটায় সত্যিই আছে এমন একটা জোড়া বেছে নেওয়া — নাহলে ফাঁকা লুকআপ মাপা হয়
$sample = DB::table('phase0_ledger')
    ->where('source_type', 'sales_invoice')
    ->orderBy('id')->offset(500)->limit(1)->first();
[$t, $rows] = ms(fn () => DB::table('phase0_ledger')
    ->where('source_type', $sample->source_type)
    ->where('source_id', $sample->source_id)
    ->get());
$results['ড্রিল-ডাউন — এক ডকুমেন্টের এন্ট্রি'] = [$t, $rows->count() . ' রো'];
if ($rows->count() === 0) {
    echo "সতর্কতা: ড্রিল-ডাউন কোয়েরি ফাঁকা — পরীক্ষাটা অর্থহীন\n";
    $rowsEmpty = true;
}

// ---------- ফলাফল ----------
$fail = 0;
foreach ($results as $name => [$t, $info]) {
    $status = $t <= BUDGET_SECONDS * 1000 ? 'OK  ' : 'SLOW';
    if ($status === 'SLOW') {
        $fail++;
    }
    printf("%s  %-42s %8.1f ms   %s\n", $status, $name, $t, $info);
}
echo str_repeat('-', 62), "\n";
printf("সর্বোচ্চ অনুমোদিত: %.0f ms প্রতি কোয়েরি | পিক মেমরি: %.1f MB\n",
    BUDGET_SECONDS * 1000, memory_get_peak_usage(true) / 1048576);

DB::statement('DROP TABLE IF EXISTS phase0_ledger');
echo $fail === 0 ? "\nফল: পাশ — সব কোয়েরি বাজেটের ভেতরে\n" : "\nফল: {$fail}টা কোয়েরি ধীর\n";

exit($fail === 0 ? 0 : 1);

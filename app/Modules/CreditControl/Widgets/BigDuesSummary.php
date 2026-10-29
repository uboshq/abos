<?php

declare(strict_types=1);

namespace App\Modules\CreditControl\Widgets;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Widgets\WidgetSummary;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Services\CreditExposure;
use LogicException;

/**
 * ⭐ ফোনের "বড় বকেয়া" উইজেট (৪×২; চাপলে সেই গ্রাহকের খাতা) — সার্ভার-অংশ (মালিকের আদেশ, ২০ অক্টোবর ২০২৬; উইজেট-চুক্তি ৪)।
 *
 * ⭐ নিজে কিছু গোনে না — ওয়েবের "বকেয়ার তালিকা"-ই ([[PartyReports::dueList()]], `customer.due_list`, পাতা
 * /customers/reports/due-list) রিপোর্ট-ইঞ্জিন দিয়ে চালানো, পর্দার একই দরজায়:
 *  - সারির জের = খাতার ডেবিট − ক্রেডিট, গ্রাহক ধরে, আজ পর্যন্ত (তালিকার `outstanding − advance`; একটা সবসময় শূন্য);
 *  - ক্রম = তালিকার নিজের ক্রম, `SUM(debit) − SUM(credit) DESC` — চিহ্নসহ জের, বড়টা আগে। ⓘ অগ্রিম (ঋণাত্মক) তাই সবার
 *    শেষে; ৮টার ঘরে আসে কেবল যখন বকেয়ার গ্রাহক ৮-এর কম। ⛔ পরম মানে সাজানো নয় — ওয়েব তা করে না, দ্বিতীয় নিয়ম নয়;
 *  - `total` = তালিকার নিচের মোট "বকেয়া" ঘর (GREATEST(জের, 0)-র যোগ) — কেবল ধনাত্মক জের, অগ্রিম কাটাকাটি নয়
 *    ([[TheDueListNettedTheAdvancesTest]]);
 *  - শাখা: ইঞ্জিনের দেয়াল — ব্যবহারকারীর শাখা-নাগাল, আর হেডারে এক শাখা বাছা থাকলে কেবল সেটা ([[ReportEngine::branchWall()]]);
 *  - ⛔ বিক্রয়কর্মী (দেয়ালে) কেবল নিজের ডিলার ([[ReportEngine::dealerWall()]] → [[DealerScope::restrict()]])।
 *
 * ⭐ `overdue_days` — বাকি নিয়ন্ত্রণের ড্যাশবোর্ডের একই উৎস ([[CreditExposure::openBills()]], [[CreditRisk::factsFor()]]-এর
 * `oldest`): মেয়াদ পেরোনো খোলা বিলের মধ্যে সবচেয়ে বেশি দিন; কিছু না পেরোলে null। ⓘ বিলের বাকি গ্রাহকের, শাখার নয় —
 * ওই হিসাবের নিজের নিয়ম (D2, ১৮ অক্টোবর ২০২৬)।
 *
 * ⓘ উত্তরে কোনো সময় নেই — দরজা নিজেই ওপরে `asOf` পাঠায়, আর ভেতরে সময় থাকলে etag কখনো মিলত না (304 হত না)।
 *   জের আজকের দিন পর্যন্ত।
 * ⓘ `link` ওয়েবের পথ; ফোন নিজের উপসর্গ বসায়।
 *
 * ⛔ রিপোর্ট-ইঞ্জিন দেয়াল বসায় সাইন-ইন করা মানুষের নামে (`auth()->user()`)। অন্য কারো জন্য ডাকলে তাঁর দেয়াল খাটত না —
 * তাই সে ক্ষেত্রে উত্তর নয়, ব্যতিক্রম।
 */
final class BigDuesSummary implements WidgetSummary
{
    public const KEY = 'big_dues';

    public const ROWS = 8;

    /** ওয়েবের বকেয়ার তালিকা — চাবি আর পাতা দুটোই এই রিপোর্টের */
    public const REPORT = 'customer.due_list';

    public function key(): string
    {
        return self::KEY;
    }

    /** গ্রাহকের ফোন-মডিউল — ফোনের হোমের "বকেয়া"-র একই সুইচ ([[DashboardTodayController]]: `customer`) */
    public function module(): string
    {
        return 'customer';
    }

    /** ওয়েবের বকেয়ার তালিকার চাবি ([[PartyReports::dueList()]], [[CustomerReportController]]) */
    public function permission(): ?string
    {
        return 'customer.report';
    }

    public function data(User $user): array
    {
        if ((int) auth()->id() !== (int) $user->id) {
            throw new LogicException('BigDuesSummary answers only for the signed-in person: the report walls read auth()->user().');
        }

        $asOf = now()->toDateString();
        $result = app(ReportEngine::class)->run(self::REPORT, ['to' => $asOf], 1, self::ROWS);

        $rows = array_slice($result->rows, 0, self::ROWS);
        $ids = array_map(fn (array $r) => (int) $r['party_id'], $rows);

        /** @var array<int, Customer> $customers */
        $customers = $ids === [] ? [] : Customer::query()->withTrashed()
            ->where('company_id', CompanyContext::id())
            ->whereIn('id', $ids)
            ->with(Customer::PLACE)
            ->get()
            ->keyBy('id')
            ->all();

        $bills = $customers === [] ? [] : app(CreditExposure::class)->openBills($customers);

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['party_id'];
            $customer = $customers[$id] ?? null;
            if ($customer === null) {
                continue;
            }

            $overdue = array_filter(
                array_map(fn (array $b) => (int) $b['days_overdue'], $bills[$id] ?? []),
                fn (int $days) => $days > 0,
            );

            $out[] = [
                'customer' => [
                    'label' => $customer->nameWithPoint(),
                    'name' => $customer->name(),
                    'point' => $customer->pointName(),
                    'branch' => $customer->branchName(),
                ],
                'balance' => bcsub((string) ($row['outstanding'] ?? '0'), (string) ($row['advance'] ?? '0'), 4),
                'overdue_days' => $overdue === [] ? null : max($overdue),
                'link' => route('customer.show', ['customer' => $id], false),
            ];
        }

        return [
            'total' => bcadd((string) ($result->totals['outstanding'] ?? '0'), '0', 4),
            'rows' => $out,
            'link' => route('customer.report.show', ['slug' => 'due-list'], false),
        ];
    }
}

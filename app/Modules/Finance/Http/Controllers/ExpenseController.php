<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Services\HeadTotals;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * খরচ — কোন খাতে কত গেল, আর সেটা কোন কাগজে।
 *
 * ── কেন খরচ অর্থে, হিসাবে নয় ─────────────────────────────────────────
 * ২৯ আগস্ট ২০২৬-এ দুই মডিউলের সীমা টানার সময় প্রথমে বলেছিলাম "ভাউচার
 * হিসাবে, ব্যবস্থাপনা অর্থে"। ওটা ভুল ছিল — ব্যবহারকারীকে দুই দরজায়
 * পাঠাত, ঠিক যে বিভ্রান্তি এড়ানোর জন্য মডিউল দুইটা করা হচ্ছে।
 *
 * আসল প্রশ্ন **কে করে**: ডিপো ম্যানেজার রোজ খরচ লেখেন — ভাড়া, হাম্মালি,
 * জ্বালানি, নাশতা। হিসাবরক্ষক জাবেদা লেখেন। দুইটা আলাদা মানুষ।
 *
 * ── কেন এটা আরেকটা ভাউচার তালিকা নয় ─────────────────────────────────
 * তালিকাটা হিসাবে আছেই (`accounts/vouchers/expense`), আর ওখানেই থাকবে —
 * খরচ সত্যিই একটা ভাউচার। এখানকার প্রশ্ন আলাদা: **কোন খাতে কত গেল**।
 * ম্যানেজার তালিকা পড়েন না, তিনি জানতে চান এই মাসে জ্বালানিতে কত গেল
 * আর গত মাসের চেয়ে বেশি না কম।
 *
 * একটাই সত্য, দুই দিক থেকে দেখা — আর প্রতিটা সংখ্যা ক্লিক করলে ওই
 * খাতের খতিয়ানে নিয়ে যায় (নিয়ম ১)।
 *
 * ── কেন শ্রেণির আলাদা টেবিল নেই ──────────────────────────────────────
 * খরচের শ্রেণিগুলো ছকেই আছে — `5200 পরিচালন ব্যয়`-এর নিচে ষোলোটা খাত:
 * বেতন, ভাড়া, বিদ্যুৎ, জ্বালানি ও পরিবহন, মেরামত, আপ্যায়ন, বিপণন…।
 * আলাদা টেবিল বানালে দুই জায়গায় দুই তালিকা থাকত, আর একদিন একটায়
 * নতুন শ্রেণি যোগ হত অন্যটায় না — তখন খরচ লেখা যেত এমন শ্রেণিতে যা
 * খাতায় নেই।
 *
 * কোম্পানি নতুন শ্রেণি চাইলে ছকে একটা খাত যোগ করে — খোলা তালিকার
 * নিয়ম, আর ওটা এখানে বিনা খরচেই মেনে চলা হয়।
 */
class ExpenseController extends Controller implements HasMiddleware
{
    public function __construct(private readonly MenuBuilder $menu) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('can:finance.expense.view')];
    }

    public function index(Request $request): View
    {
        [$from, $to] = $this->range($request);

        return view('finance::expense.index', [
            'menu' => $this->menu->forUser($request->user()),
            'from' => $from,
            'to' => $to,
            'heads' => $this->heads($from, $to),
            'recent' => Voucher::query()
                ->ofType(Voucher::EXPENSE)
                ->orderByDesc('trx_date')->orderByDesc('id')
                ->limit(20)->get(),
        ]);
    }

    /**
     * চলতি মাস, যদি না অন্য কিছু চাওয়া হয়।
     *
     * ── কেন মাস, আর কেন আজ নয় ───────────────────────────────────────
     * খরচের প্রশ্নটা কখনোই "আজ কত গেল" নয় — আজ হয়তো কিছুই যায়নি।
     * প্রশ্নটা "এই মাসে কত গেল", কারণ ভাড়া, বেতন আর বিদ্যুৎ মাসের
     * হিসাব।
     *
     * @return array{0: string, 1: string}
     */
    private function range(Request $request): array
    {
        return [
            (string) $request->query('from', now()->startOfMonth()->toDateString()),
            (string) $request->query('to', now()->endOfMonth()->toDateString()),
        ];
    }

    /**
     * খাত ধরে খরচ — এই সময়ে কত, আর তার আগের সমান সময়ে কত।
     *
     * ── কেন হিসাবটা এখানে নয়, ভাগ করা সেবায় ────────────────────────
     * আয়ের পর্দা বানাতে গিয়ে দেখা গেল প্রশ্নটা হুবহু এক — কেবল মাথা
     * আর চিহ্ন আলাদা। কপি করে বসালে একদিন একটায় সংশোধন হত অন্যটায়
     * নয়, আর তখন একই ব্যবসার আয় ও খরচ দুই নিয়মে গোনা হত।
     *
     * @return list<array{account: Account, now: string, before: string}>
     */
    private function heads(string $from, string $to): array
    {
        return app(HeadTotals::class)->forParent(
            StandardChart::OPERATING_EXPENSES,
            $from,
            $to,
            /* খরচ ডেবিটে বাড়ে */
            debitPositive: true,
        );
    }
}

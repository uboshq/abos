<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Services\HeadTotals;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * আয় — কোন খাতে কত এল, আর কতটা বিক্রয় ছাড়া।
 *
 * ── কেন এই পর্দাটা লাগল, ৩০ আগস্ট ২০২৬ ───────────────────────────────
 * খরচের পর্দা ছিল, আয়ের ছিল না। অথচ প্রশ্নটা একই রকম দরকারি, আর
 * ডিপোতে একটা বাড়তি প্রশ্ন আছে যা খরচে নেই: **কতটা টাকা বিক্রয় ছাড়া
 * এল?**
 *
 * ৪% মার্জিনের ব্যবসায় ভাড়া, কমিশন আর বাতিল মাল বিক্রির টাকা ছোট মনে
 * হয়, কিন্তু ওগুলোর কোনো ক্রয়মূল্য নেই — অর্থাৎ পুরোটাই মুনাফা।
 * বিক্রয়ের সাথে মিশিয়ে রাখলে সেটা কেউ কোনোদিন দেখত না।
 *
 * ── কেন লাভ-ক্ষতির রিপোর্ট যথেষ্ট নয় ─────────────────────────────────
 * ওটা একটা বিবৃতি — আয় বিয়োগ ব্যয় সমান মুনাফা। এখানকার প্রশ্ন
 * ব্যবস্থাপনার: কোন খাত বাড়ছে, কোনটা কমছে, আর আগের সমান সময়ের তুলনায়
 * কতটা।
 */
class IncomeController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly HeadTotals $heads,
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('can:finance.income.view')];
    }

    public function index(Request $request): View
    {
        $from = (string) $request->query('from', now()->startOfMonth()->toDateString());
        $to = (string) $request->query('to', now()->endOfMonth()->toDateString());

        $rows = $this->heads->forParent(
            StandardChart::INCOME,
            $from,
            $to,
            /* আয় ক্রেডিটে বাড়ে — খরচের ঠিক উল্টো */
            debitPositive: false,
        );

        return view('finance::income.index', [
            'menu' => $this->menu->forUser($request->user()),
            'from' => $from,
            'to' => $to,
            'heads' => $rows,
            'totals' => $this->split($rows),
        ]);
    }

    /**
     * বিক্রয় কত, বিক্রয় ছাড়া কত।
     *
     * ── কেন বিক্রয় ফেরত বিক্রয়ের সাথেই ─────────────────────────────
     * ফেরত বিক্রয়ের উল্টো দিক, আলাদা কোনো আয় নয়। "বিক্রয় ছাড়া কত এল"
     * সংখ্যাটায় ফেরত ঢুকলে ওটা ঋণাত্মক টেনে নামাত, আর প্রশ্নটার
     * উত্তরই মিথ্যা হত।
     *
     * @param  list<array{account: Account, now: string, before: string}>  $rows
     * @return array{sales: string, other: string, all: string}
     */
    private function split(array $rows): array
    {
        $sales = '0';
        $other = '0';

        foreach ($rows as $row) {
            $code = $row['account']->code;

            if ($code === StandardChart::SALES || $code === StandardChart::SALES_RETURN) {
                $sales = bcadd($sales, $row['now'], 4);

                continue;
            }

            $other = bcadd($other, $row['now'], 4);
        }

        return ['sales' => $sales, 'other' => $other, 'all' => bcadd($sales, $other, 4)];
    }
}

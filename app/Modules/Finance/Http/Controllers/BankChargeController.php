<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Services\BankCharges;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * ব্যাংক চার্জ — মানচিত্র §৯। মূলধনের পাতার ধাঁচ: শিরোনাম · বর্ণনা →
 * সময়ের ট্যাব → ব্যাংক ধরে মোট → প্রতিটা কাটা।
 *
 * ⓘ লেখার পথ নেই: চার্জ বসে রসিদের "চার্জ" ঘরে বা ভাউচারে। এখানে কেবল
 * দেখা — আর প্রতিটা সংখ্যা তার ভাউচারে নামে (নিয়ম ১)।
 * ⓘ অনুমতি খরচের পাতার (`finance.expense.view`): চার্জ একটা খরচ, আর
 * আলাদা ক্ষমতা বানালে একই মানুষকে দুইবার দিতে হত।
 */
class BankChargeController extends Controller implements HasMiddleware
{
    /** @var list<string> */
    public const PERIODS = ['this_month', 'last_month', 'this_year', 'custom'];

    /**
     * ⓘ সেবাটা কনস্ট্রাক্টরে, মেথডের ঘরে নয় — আর সেটা কেবল অভ্যাস নয়:
     * [[Tests\Feature\Architecture\EveryListScreenPaginatesTest]] পাতা ভাগ
     * খোঁজে `$this->সেবা->মেথড()` ধরে। ⚠️ মেথডের প্যারামিটারে নিলে সে
     * ভেতরে তাকাতে পারে না, আর পাতা ভাগ থাকা সত্ত্বেও পর্দাটা
     * "পুরো টেবিল আনে" বলে ধরা পড়ে।
     */
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly BankCharges $charges,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:finance.expense.view')];
    }

    public function index(Request $request): View
    {
        $period = in_array($request->query('period'), self::PERIODS, true) ? (string) $request->query('period') : 'this_month';

        [$from, $to] = $this->range($period, $request);

        /*
         * ⓘ তালিকা আর যোগফল আলাদা দুইটা প্রশ্ন: সারি পাতা ভাগ করে আসে,
         * "ব্যাংক ধরে" যোগফল আসে পুরো সময়ের উপর
         * ([[Tests\Feature\Architecture\EveryListScreenPaginatesTest]])।
         */
        return view('finance::bank-charge.index', [
            'menu' => $this->menu->forUser($request->user()),
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'byBank' => $this->charges->byBank($from, $to),
            'total' => $this->charges->total($from, $to),
            'rows' => $this->charges->rows($from, $to),
        ]);
    }

    /** @return array{0: string, 1: string} */
    private function range(string $period, Request $request): array
    {
        $today = Carbon::today();

        return match ($period) {
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'this_year' => [$today->copy()->startOfYear()->toDateString(), $today->toDateString()],
            'custom' => [
                $this->date($request->query('from')) ?? $today->copy()->startOfMonth()->toDateString(),
                $this->date($request->query('to')) ?? $today->toDateString(),
            ],
            default => [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()],
        };
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }
}

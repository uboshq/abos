<?php

declare(strict_types=1);

namespace App\Modules\Executive\Services;

use App\Core\Engines\Dashboard\DashboardEngine;
use App\Core\Engines\Report\Trend;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Approval\Dashboard\ApprovalDashboard;
use App\Modules\Inventory\Services\StockFacts;
use App\Modules\Sales\Metrics\SalesMetrics;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * মালিকের আটটা প্রধান সংখ্যা — প্রতিটা কোন মডিউলের কোন সংজ্ঞা থেকে আসে।
 *
 * ── ⛔ এখানে কোনো SUM নেই ──────────────────────────────────────────────
 * প্রতিটা সংখ্যা মডিউলের **নিজের** পদ্ধতি ডাকে — যেটা ঐ মডিউলের ড্যাশবোর্ড
 * নিজেও ডাকে। ⓘ নিজে গুনলে একদিন দুই পর্দা দুই সংখ্যা বলত, আর মালিক কোনটা
 * বিশ্বাস করবেন জানতেন না (OneFigureOneDefinitionTest-এর একই কারণ)।
 *
 * ── ⓘ কে কোন সংখ্যা দেখেন ─────────────────────────────────────────────
 * ঐ মডিউলের ড্যাশবোর্ডের চাবি ([[DashboardEngine::permissionFor()]]) আর সংখ্যাটার
 * নিজের চাবি — দুইটাই, ঐ কোম্পানিতে বসে। ⚠️ না থাকলে ঘরটা ঢাকা (••••), শূন্য নয়:
 * শূন্য একটা মিথ্যা সংখ্যা, আর যোগফলে সেটা নীরবে কম দেখাত।
 */
final class Figures
{
    public const SALES = 'sales';

    public const COLLECTIONS = 'collections';

    public const RECEIVABLE = 'receivable';

    public const PAYABLE = 'payable';

    public const FUND = 'fund';

    public const STOCK = 'stock';

    public const PROFIT = 'profit';

    public const SIGNATURES = 'signatures';

    /** তুলনার পাতার বাড়তি দুইটা — খরচ আর টাকার নিট প্রবাহ */
    public const EXPENSES = 'expenses';

    public const CASH_FLOW = 'cash_flow';

    /** @var list<string> তুলনার কলাম — নকশার ক্রমে */
    public const COMPARED = [self::SALES, self::COLLECTIONS, self::RECEIVABLE, self::PROFIT, self::EXPENSES, self::CASH_FLOW];

    /** @var list<string> পর্দার ক্রম — নকশার ক্রম */
    public const KEYS = [
        self::SALES, self::COLLECTIONS, self::RECEIVABLE, self::PAYABLE,
        self::FUND, self::STOCK, self::PROFIT, self::SIGNATURES,
    ];

    /** ঢাকা ঘর — [[Stat::HIDDEN]]-এর একই চিহ্ন */
    public const HIDDEN = '••••';

    public const TODAY = 'today';

    public const WEEK = 'week';

    public const MONTH = 'month';

    /** @var list<string> */
    public const PERIODS = [self::TODAY, self::WEEK, self::MONTH];

    public function __construct(private readonly DashboardEngine $dashboards) {}

    /**
     * একটা সংখ্যার পরিচয়।
     *
     * - `module`: যে ড্যাশবোর্ডে এই সংখ্যাটা আছে — নিচে নামার প্রথম ধাপ
     * - `money`: টাকা, নাকি গোনা
     * - `byBranch`: শাখা ধরে ভাগ হয় কি না (অনুমোদনের সারিতে শাখা নেই)
     * - `period`: সময়ের ছাঁকনি মানে কি না (জের "এখন"-এর, বিক্রি "এই সময়ের")
     * - `permission`: সংখ্যাটার নিজের চাবি, ড্যাশবোর্ডের চাবির উপরে
     *
     * @return array{module: string, money: bool, byBranch: bool, period: bool, permission: ?string}
     */
    public static function definition(string $key): array
    {
        return match ($key) {
            self::SALES => ['module' => 'sales', 'money' => true, 'byBranch' => true, 'period' => true, 'permission' => 'sales.invoice.view'],
            self::COLLECTIONS => ['module' => 'sales', 'money' => true, 'byBranch' => true, 'period' => true, 'permission' => 'sales.collection.view'],
            self::RECEIVABLE => ['module' => 'accounts', 'money' => true, 'byBranch' => true, 'period' => false, 'permission' => null],
            self::PAYABLE => ['module' => 'accounts', 'money' => true, 'byBranch' => true, 'period' => false, 'permission' => null],
            // ⓘ অর্থের ড্যাশবোর্ডে "মোট তহবিল" হিসাবের চাবিও চায় ([[FinanceDashboard::fundAndDues()]])
            self::FUND => ['module' => 'finance', 'money' => true, 'byBranch' => true, 'period' => false, 'permission' => 'accounts.view'],
            self::STOCK => ['module' => 'inventory', 'money' => true, 'byBranch' => true, 'period' => false, 'permission' => null],
            self::PROFIT => ['module' => 'accounts', 'money' => true, 'byBranch' => true, 'period' => true, 'permission' => null],
            self::SIGNATURES => ['module' => 'approval', 'money' => false, 'byBranch' => false, 'period' => false, 'permission' => null],
            self::EXPENSES => ['module' => 'accounts', 'money' => true, 'byBranch' => true, 'period' => true, 'permission' => null],
            // ⓘ অর্থের ড্যাশবোর্ডের "এ মাসের নিট নগদ প্রবাহ" — হিসাবের চাবিও চায়, তহবিলের মতোই
            self::CASH_FLOW => ['module' => 'finance', 'money' => true, 'byBranch' => true, 'period' => true, 'permission' => 'accounts.view'],
            default => throw new InvalidArgumentException("Unknown executive figure '{$key}'."),
        };
    }

    /**
     * সময়ের ছাঁকনি → তারিখের পরিসর। সপ্তাহ শনিবারে শুরু ([[Trend::WEEK_STARTS]])।
     *
     * @return array{0: string, 1: string}
     */
    public static function window(string $period, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();

        return match ($period) {
            self::WEEK => [$today->copy()->startOfWeek(Trend::WEEK_STARTS)->toDateString(), $today->toDateString()],
            self::MONTH => [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()],
            default => [$today->toDateString(), $today->toDateString()],
        };
    }

    /**
     * এই মানুষটা, এই প্রসঙ্গে (কোম্পানি + শাখা), সংখ্যাটা দেখতে পান কি না।
     *
     * ⚠️ [[CompanyLens::within()]]-এর ভিতরে ডাকতে হয় — চাবি ঐ কোম্পানির।
     */
    public function visible(User $user, string $key): bool
    {
        $definition = self::definition($key);
        $gate = $this->dashboards->has($definition['module']) ? $this->dashboards->permissionFor($definition['module']) : null;

        if ($gate !== null && ! $user->can($gate)) {
            return false;
        }

        return $definition['permission'] === null || $user->can($definition['permission']);
    }

    /**
     * সংখ্যাটা — মডিউলের নিজের সংজ্ঞায়, চলতি প্রসঙ্গে। `null` = দেখার অনুমতি নেই।
     *
     * ⚠️ [[CompanyLens::within()]]-এর ভিতরে ডাকতে হয়।
     */
    public function value(User $user, string $key, string $from, string $to): ?string
    {
        if (! $this->visible($user, $key)) {
            return null;
        }

        $accounts = app(AccountsFacts::class);

        $value = match ($key) {
            self::SALES => SalesMetrics::invoiceTotal($from, $to),
            self::COLLECTIONS => SalesMetrics::collectionTotal($from, $to),
            self::RECEIVABLE => $accounts->receivable(),
            self::PAYABLE => $accounts->payable(),
            self::FUND => $accounts->fund(),
            // ⓘ খরচের সংখ্যা — চাবি না থাকলে মডিউল নিজেই null দেয় ([[FieldSecurity]])
            self::STOCK => app(StockFacts::class)->value(),
            self::PROFIT => $accounts->netProfit(
                $accounts->netOfType(Account::INCOME, Carbon::parse($from), Carbon::parse($to)),
                $accounts->netOfType(Account::EXPENSE, Carbon::parse($from), Carbon::parse($to)),
            ),
            self::SIGNATURES => (string) ApprovalDashboard::pendingCount(),
            self::EXPENSES => $accounts->netOfType(Account::EXPENSE, Carbon::parse($from), Carbon::parse($to)),
            self::CASH_FLOW => (function () use ($accounts, $from, $to): string {
                $flow = $accounts->moneyFlowBetween($from, $to);

                return bcsub($flow['in'], $flow['out'], 4);
            })(),
        };

        return $value === null ? null : bcadd((string) $value, '0', 4);
    }

    /** নিচে নামার প্রথম ধাপ — ঐ মডিউলের ড্যাশবোর্ড */
    public static function dashboardOf(string $key): string
    {
        return self::definition($key)['module'];
    }
}

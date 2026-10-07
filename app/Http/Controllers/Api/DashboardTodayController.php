<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\DataScope;
use App\Core\Services\PhoneModules;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Dashboard\AccountsWidgets;
use App\Modules\Accounts\Services\AccountsFacts;
use App\Modules\Customer\Services\CustomerMetrics;
use App\Modules\Finance\Services\HandLoanService;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Supplier\Reports\PrincipalCommissionReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * "আজ কেমন গেল" — এক পাতায়, ফোনে। চুক্তি §৮।
 *
 * ⭐ প্রতিটা টাকার সংখ্যা ওয়েবের হোম পর্দার একই উৎস থেকে — [[SalesMetrics]],
 * [[AccountsWidgets::cashInHand()]], [[CustomerMetrics]], [[ApprovalEngine]]।
 * ⛔ নিজে গুনলে একদিন ফোন আর ওয়েব একই মানুষকে দুই অঙ্ক বলত।
 *
 * ── ⛔ চাবি নেই মানে ঘরটাই নেই (চুক্তির নিয়ম ক) ──────────────────────
 * `null` বা `"0"` নয় — "আজ নগদ শূন্য" আর "আপনি নগদ দেখতে পারেন না" দুই কথা।
 * রুটে কোনো `can:` নেই; প্রতিটা ঘর নিজের চাবি দেখে।
 *
 * ── চাবিগুলো, আর চুক্তি থেকে যেখানে সরে আসা ─────────────────────────
 *   sales        sales.invoice.view  ⚠️ চুক্তিতে sales.order.view; কিন্তু সংখ্যাটা
 *                                    বিলের, আর ওয়েবের কার্ডও এই চাবি চায় —
 *                                    ফোন ওয়েবের চেয়ে বেশি দেখাবে না
 *   collections  sales.collection.view
 *   cashInHand   accounts.till.view  ⚠️ কেবল নগদ টিল; ওয়েবের কার্ড নগদ+ব্যাংক
 *                                    মিলিয়ে দেখায়, চুক্তির নাম "হাতে নগদ"
 *   dues         customer.report     ⚠️ চুক্তিতে customer.view; সমন্বয়কের
 *                                    সিদ্ধান্ত ২৬ সেপ্টেম্বর — বকেয়ার তালিকার
 *                                    সমান চাবি, বিক্রয়কর্মীর দোকান-বাঁধন হলে আবার দেখা
 *   approvals    approval.decide
 *   money        accounts.till.view  ⭐ ওয়েবের "হাতে ও ব্যাংকে মোট" — নগদ · MFS · ব্যাংক · পথে (৬ অক্টোবর ২০২৬)
 *   inflow       accounts.view       ⭐ আজ যত টাকা ঢুকল, স্থানান্তর বাদ
 *   payable      accounts.view       ⭐ সব দায় + হাতধারে আমাদের দেনা (হাতধারের ভাগ কেবল finance.hand_loan.view-এ)
 *   principals   supplier.report     ⭐ প্রিন্সিপালের কমিশন — শতাংশ, দেওয়া, বাকি
 *
 * ── শাখা ─────────────────────────────────────────────────────────────
 * ⓘ বাছাই-করা শাখা নয় — ব্যবহারকারীর শাখা-সীমা ([[ScopedToUserBranch]]),
 * ওয়েবের মতোই। `branch` null মানে সীমা নেই, সব শাখা মিলিয়ে।
 */
class DashboardTodayController extends Controller
{
    public function __construct(
        private readonly DataScope $scope,
        private readonly CustomerMetrics $customers,
        private readonly ApprovalEngine $approvals,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // ⚠️ "আজ" সার্ভারের দিন, ফোনের নয় (চুক্তির নিয়ম গ)
        $today = Carbon::today()->toDateString();

        $body = [
            'date' => $today,
            'company' => Company::query()->find(CompanyContext::id())?->name(),
            'branch' => $this->branchLabel($user),
            'asOf' => now()->toIso8601String(),
        ];

        /*
         * ⛔ প্রতিটা ভাগ তার মডিউলের ফোন-সুইচও মানে (পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, ফোন ⚠️১০: আজকের পাতা সুইচই দেখত না —
         * কোম্পানি ফোনে হিসাব বন্ধ রাখলেও হাতের নগদ আর দেনা ফোনে যেত)। ⓘ চাবি আগের মতোই দেখা হয়।
         */
        $phone = app(PhoneModules::class);
        $on = fn (string $module): bool => $phone->isOn($module);

        if ($on('sales') && $user->can('sales.invoice.view')) {
            $body['sales'] = [
                'count' => SalesInvoice::query()->posted()->whereBetween('trx_date', [$today, $today])->count(),
                'amount' => self::money(SalesMetrics::salesToday()->value()),
            ];
        }

        if ($on('sales') && $user->can('sales.collection.view')) {
            /* ⓘ গোনা আর টাকা একই দুই উৎসে — আদায়ের কাগজ আর গ্রাহকের রসিদ ভাউচার
               ([[SalesMetrics::collectionTotal()]]) */
            $body['collections'] = [
                // ⛔ ডিলার-দেয়ালসহ, অঙ্কের একই জায়গা থেকে ([[SalesMetrics::collectionCount()]]; অডিট ফোন ⚠️৯)
                'count' => SalesMetrics::collectionCount($today, $today),
                'amount' => self::money(SalesMetrics::collectionTotal($today, $today)),
            ];
        }

        if ($on('accounts') && $user->can('accounts.till.view')) {
            $body['cashInHand'] = ['amount' => self::money(AccountsWidgets::cashInHand())];

            /*
             * ⭐ হাতে ও ব্যাংকে মোট — ওয়েবের হোমের ডান-উপরের ঘরটাই, হুবহু একই সংখ্যা (মালিক, ৬ অক্টোবর ২০২৬, ফোনের
             * ছবিতে: "হাতে ও ব্যাংকে মোট · নগদ · MFS · BANK · পথে")। মোট = নগদ + MFS + ব্যাংক, যেমন ওয়েবে; পথে আলাদা ভাগ।
             */
            $cash = self::money(AccountsWidgets::cashInHand());
            $mfs = self::money(AccountsWidgets::mfsBalance());
            $bank = self::money(AccountsWidgets::bankBalance());
            $body['money'] = [
                'amount' => bcadd(bcadd($cash, $mfs, 4), $bank, 4),
                'cash' => $cash,
                'mfs' => $mfs,
                'bank' => $bank,
                'transit' => self::money(AccountsWidgets::inTransit()),
            ];
        }

        if ($on('accounts') && $user->can('accounts.view')) {
            $facts = app(AccountsFacts::class);

            // ⭐ আজকের ইনফ্লো — আজ যত টাকা ঢুকল, নগদ-ব্যাংক-MFS মিলিয়ে, নিজের মধ্যে স্থানান্তর বাদ (মালিক, ৬ অক্টোবর ২০২৬)
            $body['inflow'] = ['amount' => self::money($facts->moneyFlowBetween($today, $today)['in'])];

            /*
             * ⭐ Payable — "যাকেই আমার পেমেন্ট করতে হবে" (মালিক, ৬ অক্টোবর ২০২৬): দায়ের গোটা দল (সরবরাহকারী, ভাড়া, ভ্যাট,
             * বেতন, ঋণ — [[AccountsFacts::liabilities()]]) আর হাতধারে আমাদের দেনা (হাতধারের পাতার "আমরা দেব")।
             */
            $owed = self::money($facts->liabilities());
            $handLoans = $user->can('finance.hand_loan.view') && class_exists(HandLoanService::class)
                ? self::money(app(HandLoanService::class)->standing()['we_owe'])
                : '0.0000';
            $body['payable'] = ['amount' => bcadd($owed, $handLoans, 4), 'books' => $owed, 'handLoans' => $handLoans];
        }

        /*
         * ⭐ প্রিন্সিপালের কমিশন — রিপোর্টের পুরো ফল (মালিক, ৬ অক্টোবর ২০২৬: "ডিলাররা যে টাকা দেয়, এখান থেকে আমি কত
         * পার্সেন্টেজ পাব, কত দেওয়া হয়েছে, কত ব্যালেন্স")। নিজে গোনা নয় — [[PrincipalCommissionReport]], শাখার দেয়ালসহ।
         */
        if ($on('purchase') && $user->can('supplier.report') && class_exists(PrincipalCommissionReport::class)) {
            $body['principals'] = array_map(fn (array $r) => [
                // ⭐ সংক্ষিপ্ত নাম, না থাকলে নাম — কোড নয় (মালিক, ৬ অক্টোবর ২০২৬; রিপোর্টের সারিতেই)
                'name' => (string) $r['supplier_name'],
                'period' => (string) $r['period'],
                // ⭐ ওয়েবের বাক্সের "সময়কাল: 26/09/2026 – আজ পর্যন্ত" — অঙ্কগুলো চক্রের শুরু থেকে আজ পর্যন্ত
                'periodFrom' => (string) $r['period_from'],
                'periodTo' => (string) $r['period_to'],
                'periodSoFar' => \App\Modules\Supplier\Reports\PrincipalCommission::soFar((string) $r['period_from'], (string) $r['period_to']),
                'basisRate' => (string) $r['basis_rate'],
                'inflow' => self::money((string) $r['inflow']),
                'commission' => self::money((string) $r['commission']),
                // ⭐ ওয়েবের বাক্সের লেখাই — ঋণাত্মক হলে "লোকসান ৳… — কেনা দামের নিচে বিক্রি", খালি বিয়োগ নয় (৬ অক্টোবর ২০২৬)
                'commissionLabel' => \App\Modules\Supplier\Dashboard\SupplierDashboard::earned((string) $r['commission']),
                'share' => self::money((string) $r['share']),
                'paid' => self::money((string) $r['paid']),
                'balance' => self::money((string) $r['balance']),
            ], app(ReportEngine::class)->run(PrincipalCommissionReport::KEY, [], 1, 50)->rows);
        }

        if ($on('customer') && $user->can('customer.report')) {
            $body['dues'] = $this->customers->dues($user, $today);
        }

        if ($on('approval') && $user->can('approval.decide')) {
            /* ⛔ ফোনের ইনবক্সের একই ছাঁকনি — বেতন ডেস্কে সই হয়, ফোনে আসে না;
               গুনলে পাতা "৩টা অপেক্ষায়" বলত আর ইনবক্সে থাকত ২টা (§৫-এর এজেন্টের ধরা) */
            $body['approvals'] = ['pending' => ApprovalApiController::forThePhone(
                $this->approvals->pendingQueryFor($user),
            )->count()];
        }

        return response()->json($body);
    }

    /** সীমা নেই → null; এক বা একাধিক শাখা → নামগুলো ", " দিয়ে। */
    private function branchLabel(User $user): ?string
    {
        $ids = $this->scope->idsFor($user, UserDataScope::BRANCH);

        if ($ids === null) {
            return null;
        }

        return Branch::query()->withoutGlobalScopes()->whereIn('id', $ids)->orderBy('id')->get()
            ->map(fn (Branch $branch): string => $branch->name())
            ->implode(', ');
    }

    /** টাকা স্ট্রিং, চার ঘর — দশমিক হারানো চলে না। */
    private static function money(string $value): string
    {
        return bcadd($value === '' ? '0' : $value, '0', 4);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Accounts\Dashboard\AccountsWidgets;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Customer\Services\CustomerMetrics;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
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

        if ($user->can('sales.invoice.view')) {
            $body['sales'] = [
                'count' => SalesInvoice::query()->posted()->whereBetween('trx_date', [$today, $today])->count(),
                'amount' => self::money(SalesMetrics::salesToday()->value()),
            ];
        }

        if ($user->can('sales.collection.view')) {
            /* ⓘ গোনা আর টাকা একই দুই উৎসে — আদায়ের কাগজ আর গ্রাহকের রসিদ ভাউচার
               ([[SalesMetrics::collectionTotal()]]) */
            $body['collections'] = [
                'count' => Collection::query()->posted()->whereBetween('trx_date', [$today, $today])->count()
                    + Voucher::query()->where('type', Voucher::RECEIPT)->where('party_type', 'customer')
                        ->posted()->whereBetween('trx_date', [$today, $today])->count(),
                'amount' => self::money(SalesMetrics::collectionTotal($today, $today)),
            ];
        }

        if ($user->can('accounts.till.view')) {
            $body['cashInHand'] = ['amount' => self::money(AccountsWidgets::cashInHand())];
        }

        if ($user->can('customer.report')) {
            $body['dues'] = $this->customers->dues($user, $today);
        }

        if ($user->can('approval.decide')) {
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

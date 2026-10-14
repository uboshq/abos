<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Core\Support\PhoneInput;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositMovement;
use App\Modules\Finance\Models\HandLoanAccount;
use App\Modules\Finance\Models\HandLoanMovement;
use App\Modules\Finance\Models\RentalContract;
use App\Modules\Finance\Models\Tenancy;
use App\Modules\Finance\Services\BankFacilityService;
use App\Modules\Finance\Services\HandLoanService;
use App\Modules\Finance\Services\RentalDues;
use App\Modules\Finance\Support\ActingBranches;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;

/**
 * ⭐ ফোনে অর্থ — জমা, ব্যাংকের ঋণ ও কিস্তি, ভাড়া, হাতধার; কেবল পড়া (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬: বাকি মডিউল অ্যাপে)।
 *
 * ⓘ প্রতিটা তালিকা ওয়েবের তালিকার একই কোয়েরি — একই ট্যাব, একই ক্রম, দেখার শাখা ([[ListedInViewedBranch]]) — আর প্রতিটা অঙ্ক
 * মডিউলের নিজের সেবা থেকে: ঋণের বকেয়া [[BankFacilityService::standing()]], কিস্তির দিন [[BankFacilityService::datedSchedule()]],
 * ভাড়ার বকেয়া মাস [[RentalDues::overdueMonths()]], ভাড়াটের বাকি [[Tenancy::outstanding()]], হাতধারের জের
 * [[HandLoanService::balanceOf()]]। ⛔ দ্বিতীয় হিসাব নেই — ফোন আর ওয়েব একই সংখ্যা বলে।
 *
 * ⛔ কেবল public_id; একটা কাগজ খোলে দেখার শাখায় আর কাজের শাখার নাগালে ([[ActingBranches::allows()]] — ওয়েবের
 * [[OpensOnlyInReach]]-এর একই প্রশ্ন, কারণ এখানে রুট-বাইন্ডিং নেই); বাইরের কাগজ ৪০৪। অন্য কোম্পানির সারি কোম্পানির স্কোপেই ঢাকা।
 */
final class FinanceApiController extends Controller implements HasMiddleware
{
    /** ফোনের এক পাতায় কয়টা — ওয়েবের ৫০-এর চেয়ে ছোট, কারণ ফোনে প্রতিটা সারি একটা কার্ড */
    private const PER_PAGE = 30;

    /** একটা কাগজের পাতায় কয়টা চলাচল — নতুনগুলো; পুরনোটা ওয়েবে */
    private const MOVES = 30;

    /** সামনের কয়টা কিস্তি দেখানো হয় — পুরো সূচি ওয়েবে */
    private const AHEAD = 12;

    public function __construct(
        private readonly BankFacilityService $facilities,
        private readonly RentalDues $rentDues,
        private readonly HandLoanService $handLoans,
    ) {}

    public static function middleware(): array
    {
        // ⓘ ওয়েবের পর্দার হুবহু চাবি — [[DepositController]], [[BankFacilityController]], [[RentalContractController]] ও
        // [[TenancyController]] (দুইটাই ভাড়ার চাবি), [[HandLoanController]]
        return [
            new Middleware('can:finance.deposit.view', only: ['deposits', 'deposit']),
            new Middleware('can:finance.bank_facility.view', only: ['loans', 'loan']),
            new Middleware('can:finance.rental.view', only: ['rents', 'rent']),
            new Middleware('can:finance.hand_loan.view', only: ['handLoans', 'handLoan']),
        ];
    }

    /* ─────────────────────────── জমা ─────────────────────────── */

    /**
     * `GET /api/v1/finance/deposits?tab=active|maturing|closed&page=`
     *
     * ⓘ [[DepositController::all()]]-এর ক্রম: চালুগুলো আগে, তারপর যার মেয়াদ আগে; "মেয়াদ আসছে" ৯০ দিনের ভিতরে
     * (ওয়েবের সবচেয়ে বড় জানালা)।
     */
    public function deposits(Request $request): JsonResponse
    {
        $tab = $this->tab($request, ['active', 'maturing', 'closed']);

        $page = Deposit::query()->inViewedBranch()
            ->with('kind')
            ->when($tab === 'closed',
                fn ($q) => $q->where('status', '!=', Deposit::ACTIVE),
                fn ($q) => $q->open())
            ->when($tab === 'maturing', fn ($q) => $q
                ->whereNotNull('matures_on')
                ->where('matures_on', '<=', now()->addDays(90)->toDateString()))
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [Deposit::ACTIVE])
            ->orderByRaw('matures_on IS NULL')
            ->orderBy('matures_on')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return $this->page($page, fn (Deposit $d) => $this->depositFacts($d));
    }

    public function deposit(string $id): JsonResponse
    {
        /** @var Deposit $deposit */
        $deposit = $this->open(Deposit::query()->with('kind', 'payoutAccount'), $id);

        return response()->json($this->depositFacts($deposit) + [
            'reference_no' => (string) ($deposit->reference_no ?? ''),
            'branch_name' => (string) ($deposit->branch_name ?? ''),
            'person' => $deposit->held_by === Deposit::OWNER ? (string) ($deposit->person?->name() ?? '') : '',
            'tax_rate' => $deposit->tax_rate === null ? null : (string) $deposit->tax_rate,
            'on_maturity' => (string) ($deposit->on_maturity ?? ''),
            'instalment_amount' => $deposit->instalment_amount === null ? null : Money::round((string) $deposit->instalment_amount, 2),
            'instalment_day' => $deposit->instalment_day === null ? null : (int) $deposit->instalment_day,
            'closed_on' => $deposit->closed_on?->toDateString(),
            'pledged' => $deposit->isPledged(),
            'note' => (string) ($deposit->note ?? ''),
            // ⓘ নতুনটা উপরে — [[DepositController::show()]]-এর ক্রম
            'movements' => $deposit->movements()
                ->orderByDesc('moved_on')->orderByDesc('id')
                ->limit(self::MOVES)->get()
                ->map(fn (DepositMovement $m) => [
                    'date' => $m->moved_on?->toDateString(),
                    'kind' => (string) $m->kind,
                    'amount' => Money::round((string) $m->amount, 2),
                ])->values(),
        ]);
    }

    /** @return array<string, mixed> */
    private function depositFacts(Deposit $d): array
    {
        return [
            'id' => (string) $d->public_id,
            'no' => (string) $d->document_no,
            'kind' => (string) ($d->kind?->name() ?? ''),
            'issuer' => (string) ($d->kind?->issuer ?? ''),
            'institution' => (string) ($d->institution ?? ''),
            'held_by' => (string) $d->held_by,
            'principal' => Money::round((string) $d->principal, 2),
            'profit_rate' => $d->profit_rate === null ? null : rtrim(rtrim((string) $d->profit_rate, '0'), '.'),
            'opened_on' => $d->opened_on?->toDateString(),
            'matures_on' => $d->matures_on?->toDateString(),
            // ⓘ মডেলের নিজের গোনা ([[Deposit::daysToMaturity()]]) — ঋণাত্মক মানে মেয়াদ পেরিয়েছে
            'days_to_maturity' => $d->status === Deposit::ACTIVE ? $d->daysToMaturity() : null,
            'status' => (string) $d->status,
        ];
    }

    /* ─────────────────────── ব্যাংকের ঋণ ─────────────────────── */

    /**
     * `GET /api/v1/finance/loans?tab=open|closed&page=`
     *
     * ⓘ [[BankFacilityController::index()]]-এর কোয়েরি: "শেষ" মানে বন্ধ, বাকি সব খোলা; নতুনটা আগে। বকেয়া এক ডাকে গোটা পাতার
     * ([[BankFacilityService::standing()]]), আর পরের কিস্তি সূচির প্রথম না-দেওয়া সারি ([[BankFacilityService::datedSchedule()]])।
     */
    public function loans(Request $request): JsonResponse
    {
        $tab = $this->tab($request, ['open', 'closed']);

        $page = BankFacility::query()->inViewedBranch()
            ->with('institution')
            ->when($tab === 'closed',
                fn ($q) => $q->where('status', DocumentStatus::CLOSED),
                fn ($q) => $q->where('status', '!=', DocumentStatus::CLOSED))
            ->latest('id')
            ->paginate(self::PER_PAGE);

        $standing = $this->facilities->standing(collect($page->items()));

        return $this->page($page, fn (BankFacility $f) => $this->loanFacts($f, $standing[(int) $f->id] ?? null)
            + ['next' => $this->nextInstalment($f)]);
    }

    public function loan(string $id): JsonResponse
    {
        /** @var BankFacility $facility */
        $facility = $this->open(BankFacility::query()->with('institution'), $id);

        $standing = $this->facilities->standing(collect([$facility]))[(int) $facility->id] ?? null;
        $schedule = $this->facilities->datedSchedule($facility);
        $count = $this->facilities->instalmentStanding($facility);
        $ahead = collect($schedule['rows'] ?? [])->reject(fn (array $r) => $r['state'] === BankFacilityService::PAID)->values();

        return response()->json($this->loanFacts($facility, $standing) + [
            'branch_name' => (string) ($facility->branch_name ?? ''),
            'sanction_no' => (string) ($facility->sanction_no ?? ''),
            'term_months' => $facility->term_months === null ? null : (int) $facility->term_months,
            'instalments' => $facility->instalments === null ? null : (int) $facility->instalments,
            'instalment_amount' => $facility->instalment_amount === null ? null : Money::round((string) $facility->instalment_amount, 2),
            'instalments_paid' => (int) $count['paid'],
            'instalments_left' => (int) $count['left'],
            'repaid' => Money::round((string) $count['repaid'], 2),
            'closed_on' => $facility->closed_on?->toDateString(),
            'next' => $this->nextInstalment($facility, $schedule),
            'overdue' => $ahead->where('state', BankFacilityService::OVERDUE)->count(),
            // ⓘ সামনের কিস্তিগুলো — দিন পার হওয়া আগে, তারপর আজ আর সামনে; পুরো সূচি ওয়েবে
            'schedule' => $ahead->take(self::AHEAD)->map(fn (array $r) => [
                'month' => (int) $r['month'],
                'due_on' => $r['due_on'],
                'principal' => Money::round((string) $r['principal'], 2),
                'interest' => Money::round((string) $r['interest'], 2),
                'amount' => Money::round((string) $r['amount'], 2),
                'state' => (string) $r['state'],
            ])->values(),
        ]);
    }

    /**
     * @param  array{used: string, left: string}|null  $standing
     * @return array<string, mixed>
     */
    private function loanFacts(BankFacility $f, ?array $standing): array
    {
        return [
            'id' => (string) $f->public_id,
            'no' => (string) $f->document_no,
            'kind' => (string) $f->kind,
            'bank' => (string) ($f->institution?->name() ?? $f->bank ?? ''),
            'limit' => Money::round((string) $f->limit_amount, 2),
            // ⓘ ব্যবহৃত = এখনকার দেনা; বাকি = সীমার যা খালি — ওয়েবের তালিকার একই দুই ঘর
            'outstanding' => Money::round((string) ($standing['used'] ?? '0'), 2),
            'available' => Money::round((string) ($standing['left'] ?? '0'), 2),
            'interest_rate' => $f->interest_rate === null ? null : rtrim(rtrim((string) $f->interest_rate, '0'), '.'),
            'sanctioned_on' => $f->sanctioned_on?->toDateString(),
            'renews_on' => $f->renews_on?->toDateString(),
            'status' => (string) $f->status,
        ];
    }

    /**
     * পরের কিস্তি — সূচির প্রথম না-দেওয়া সারি (দিন পার হলে সেটাই); সূচি নেই (CC, BG) তো null।
     *
     * @param  array<string, mixed>|null  $schedule
     * @return array{due_on: ?string, amount: string, state: string}|null
     */
    private function nextInstalment(BankFacility $facility, ?array $schedule = null): ?array
    {
        if ($facility->instalments === null && $schedule === null) {
            return null;
        }

        $schedule ??= $this->facilities->datedSchedule($facility);

        foreach ($schedule['rows'] ?? [] as $row) {
            if ($row['state'] !== BankFacilityService::PAID) {
                return [
                    'due_on' => $row['due_on'],
                    'amount' => Money::round((string) $row['amount'], 2),
                    'state' => (string) $row['state'],
                ];
            }
        }

        return null;
    }

    /* ─────────────────────────── ভাড়া ─────────────────────────── */

    /**
     * `GET /api/v1/finance/rents?side=pay|let&tab=running|closed&page=`
     *
     * ⓘ দুই দিক দুই পর্দার কোয়েরি: আমরা ভাড়া দিই — [[RentalContractController::index()]] (মেয়াদের শেষ ধরে), আমরা ভাড়া দিয়েছি —
     * [[TenancyController::index()]] (মেয়াদের শেষ, তারপর id)।
     */
    public function rents(Request $request): JsonResponse
    {
        $side = $this->tab($request, ['pay', 'let'], 'side');
        $tab = $this->tab($request, ['running', 'closed']);

        if ($side === 'let') {
            $page = Tenancy::query()->inViewedBranch()
                ->where('status', $tab === 'closed' ? Tenancy::CLOSED : Tenancy::ACTIVE)
                ->orderBy('ends_on')->orderBy('id')
                ->paginate(self::PER_PAGE);

            return $this->page($page, fn (Tenancy $t) => $this->tenancyFacts($t));
        }

        $page = RentalContract::query()->inViewedBranch()
            ->when($tab === 'closed',
                fn ($q) => $q->where('status', RentalContract::CLOSED),
                fn ($q) => $q->active())
            ->orderBy('ends_on')
            ->paginate(self::PER_PAGE);

        return $this->page($page, fn (RentalContract $c) => $this->contractFacts($c));
    }

    public function rent(string $side, string $id): JsonResponse
    {
        if ($side === 'let') {
            /** @var Tenancy $tenancy */
            $tenancy = $this->open(Tenancy::query(), $id);

            return response()->json($this->tenancyFacts($tenancy) + [
                'phone' => (string) ($tenancy->tenant_phone ?? ''),
                'starts_on' => $tenancy->starts_on?->toDateString(),
                'term_months' => $tenancy->term_months,
                'deposit' => Money::round((string) ($tenancy->deposit_amount ?? '0'), 2),
                'deposit_held' => Money::round($tenancy->depositHeld(), 2),
                'charged' => Money::round($tenancy->charged(), 2),
                'collected' => Money::round($tenancy->collected(), 2),
                'note' => (string) ($tenancy->note ?? ''),
            ]);
        }

        /** @var RentalContract $contract */
        $contract = $this->open(RentalContract::query(), $id);
        $months = $this->rentDues->overdueMonths($contract);

        return response()->json($this->contractFacts($contract, $months) + [
            'phone' => (string) ($contract->counterparty_phone ?? ''),
            'starts_on' => $contract->starts_on?->toDateString(),
            'term_months' => $contract->term_months,
            'deposit' => Money::round((string) ($contract->deposit_amount ?? '0'), 2),
            'deposit_left' => Money::round($contract->depositLeft(), 2),
            'increase_percent' => $contract->increase_percent === null ? null : (string) $contract->increase_percent,
            'next_anniversary' => $contract->isActive() ? $contract->nextAnniversary()?->toDateString() : null,
            // ⓘ কোন কোন মাস বাকি — সময়ের ক্রমে, প্রতিটার সেই মাসের ভাড়াসহ ([[RentalContract::rentFor()]])
            'overdue_months' => collect($months)->map(fn (Carbon $m) => [
                'month' => $m->toDateString(),
                'amount' => Money::round($contract->rentFor($m), 2),
            ])->values(),
            'note' => (string) ($contract->note ?? ''),
        ]);
    }

    /**
     * @param  list<Carbon>|null  $months
     * @return array<string, mixed>
     */
    private function contractFacts(RentalContract $c, ?array $months = null): array
    {
        $months ??= $this->rentDues->overdueMonths($c);
        $today = Carbon::today();

        return [
            'id' => (string) $c->public_id,
            'side' => 'pay',
            'no' => (string) $c->document_no,
            'party' => (string) ($c->counterparty ?? ''),
            'place' => (string) ($c->subject ?? ''),
            'monthly_rent' => Money::round($c->rentFor($today), 2),
            'rent_day' => $c->rent_day === null ? null : (int) $c->rent_day,
            'ends_on' => $c->ends_on?->toDateString(),
            'days_left' => $c->isActive() && $c->ends_on !== null ? $c->daysLeft() : null,
            'next_due_on' => $c->isActive() ? $this->nextRentDay($c->starts_on, $this->lastRentMonth($c), $c->rent_day)?->toDateString() : null,
            // ⓘ বকেয়া — [[RentalDues::overdue()]]-এর একই যোগ: প্রতিটা বাকি মাসের সেই মাসের ভাড়া
            'overdue_count' => count($months),
            'overdue_amount' => Money::round(array_reduce($months, fn (string $sum, Carbon $m) => bcadd($sum, $c->rentFor($m), 4), '0'), 2),
            'status' => (string) $c->status,
        ];
    }

    /** @return array<string, mixed> */
    private function tenancyFacts(Tenancy $t): array
    {
        return [
            'id' => (string) $t->public_id,
            'side' => 'let',
            'no' => (string) $t->document_no,
            'party' => (string) ($t->tenant ?? ''),
            'place' => (string) ($t->premises ?? ''),
            'monthly_rent' => Money::round((string) $t->monthly_rent, 2),
            'rent_day' => $t->rent_day === null ? null : (int) $t->rent_day,
            'ends_on' => $t->ends_on?->toDateString(),
            'days_left' => $t->isActive() && $t->ends_on !== null
                ? (int) now()->startOfDay()->diffInDays($t->ends_on, false) : null,
            'next_due_on' => $t->isActive() ? $this->nextRentDay($t->starts_on, $t->ends_on, $t->rent_day)?->toDateString() : null,
            // ⓘ ভাড়াটের কাছে পাওনা — খাতায় বসা ভাড়া − আদায় ([[Tenancy::outstanding()]]), আর সবচেয়ে পুরনো না-মেটা মাস
            'outstanding' => Money::round($t->outstanding(), 2),
            'oldest_unpaid' => $t->oldestUnpaidMonth()?->toDateString(),
            'status' => (string) $t->status,
        ];
    }

    /** চুক্তির শেষ মাস — আগে বন্ধ হলে বন্ধের দিন ([[RentalDues::overdueMonths()]]-এর একই সীমা) */
    private function lastRentMonth(RentalContract $c): ?Carbon
    {
        $last = $c->ends_on?->copy();

        return $c->closed_on !== null && ($last === null || $c->closed_on->lt($last)) ? $c->closed_on->copy() : $last;
    }

    /**
     * পরের ভাড়ার দিন — মাসের `rent_day`-তে (১–২৮-এ বাঁধা), আজ বা পরে; চুক্তির শেষের পরে হলে null।
     *
     * ⓘ [[RentalDues::overdueMonths()]]-এর একই দিনের নিয়ম — "মাসের শুরু + দিন − ১" আজকের আগে পড়লে মাসটা বকেয়ায়, নইলে সামনে।
     */
    private function nextRentDay(?Carbon $starts, ?Carbon $last, mixed $rentDay): ?Carbon
    {
        if ($starts === null) {
            return null;
        }

        $today = Carbon::today();
        $day = max(1, min(28, (int) ($rentDay ?: 1)));
        $month = $starts->copy()->startOfMonth()->max($today->copy()->startOfMonth());

        if ($month->copy()->addDays($day - 1)->lt($today)) {
            $month->addMonthNoOverflow();
        }

        if ($last !== null && $month->gt($last)) {
            return null;
        }

        return $month->addDays($day - 1);
    }

    /* ─────────────────────────── হাতধার ─────────────────────────── */

    /**
     * `GET /api/v1/finance/hand-loans`
     *
     * ⓘ ওয়েবের তালিকার একই সেবা ([[HandLoanService::standing()]]) — খোলা খাতগুলো, নামের ক্রমে, প্রতিটার জের, আর দুই মোট।
     * ⓘ পাতা ভাগ নেই, ওয়েবের পাতারও নেই (EveryListScreenPaginatesTest-এ `finance.hand_loan.index`): সারি বাড়ে খোলা
     * হাতধারের সংখ্যায়, আর মোট দুইটা ঠিক এই সারিগুলোরই যোগ।
     */
    public function handLoans(): JsonResponse
    {
        $standing = $this->handLoans->standing();

        return response()->json([
            'owed_to_us' => Money::round($standing['owed_to_us'], 2),
            'we_owe' => Money::round($standing['we_owe'], 2),
            'rows' => collect($standing['rows'])->map(fn (array $row) => $this->handLoanFacts($row['account'], (string) $row['balance']))->values(),
        ]);
    }

    public function handLoan(string $id): JsonResponse
    {
        /** @var HandLoanAccount $account */
        $account = $this->open(HandLoanAccount::query()->with('person'), $id);

        return response()->json($this->handLoanFacts($account, $this->handLoans->balanceOf($account)) + [
            'principal' => $account->principal === null ? null : Money::round((string) $account->principal, 2),
            'interest_rate' => $account->interest_rate === null ? null : rtrim(rtrim((string) $account->interest_rate, '0'), '.'),
            'term_months' => $account->term_months === null ? null : (int) $account->term_months,
            'security' => (string) ($account->security ?? ''),
            'note' => (string) ($account->note ?? ''),
            'movements' => $account->movements()
                ->orderByDesc('moved_on')->orderByDesc('id')
                ->limit(self::MOVES)->get()
                ->map(fn (HandLoanMovement $m) => [
                    'date' => $m->moved_on?->toDateString(),
                    'direction' => (string) $m->direction,
                    'amount' => Money::round((string) $m->amount, 2),
                    'return_on' => $m->return_on?->toDateString(),
                ])->values(),
        ]);
    }

    /** @return array<string, mixed> */
    private function handLoanFacts(HandLoanAccount $account, string $balance): array
    {
        $due = $account->next_due_on ?? $account->due_on;

        return [
            'id' => (string) $account->public_id,
            'name' => (string) ($account->person?->name() ?? ''),
            'mobile' => (string) ($account->person?->mobile ?? ''),
            // ⓘ ধনাত্মক = তিনি আমাদের দেবেন; ঋণাত্মক = আমরা তাঁকে দেব ([[HandLoanService::balanceOf()]])
            'balance' => Money::round($balance, 2),
            // ⓘ কবে ফেরতের কথা — পরের কিস্তির দিন, না থাকলে শেষ দিন ([[DueNotices]]-এর একই নিয়ম)
            'due_on' => $due?->toDateString(),
            'repayment' => (string) ($account->repayment ?? ''),
            'status' => (string) $account->status,
        ];
    }

    /* ─────────────────────────── সাধারণ ─────────────────────────── */

    /**
     * একটা কাগজ — public_id ধরে, দেখার শাখায়, আর কাজের শাখার নাগালে; নইলে ৪০৪।
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return T
     */
    private function open(Builder $query, string $id): Model
    {
        $row = $query->inViewedBranch()->where('public_id', $id)->firstOrFail();

        abort_unless(ActingBranches::allows($row->getAttribute('branch_id')), 404);

        return $row;
    }

    /** @param list<string> $allowed */
    private function tab(Request $request, array $allowed, string $key = 'tab'): string
    {
        $asked = PhoneInput::text($request, $key, $allowed[0]);

        return in_array($asked, $allowed, true) ? $asked : $allowed[0];
    }

    /**
     * @param  LengthAwarePaginator<int, Model>  $page
     * @param  callable(Model): array<string, mixed>  $row
     */
    private function page(LengthAwarePaginator $page, callable $row): JsonResponse
    {
        return response()->json([
            'rows' => collect($page->items())->map($row)->values(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }
}

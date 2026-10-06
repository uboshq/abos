<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Concerns\ReadsTheRowUnderLock;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Services\OpenPeriod;
use App\Core\Services\PartyRegistry;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Finance\Models\Tenancy;
use App\Modules\Finance\Models\TenancyCharge;
use App\Modules\Finance\Models\TenancyMove;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ আমরা যখন বাড়িওয়ালা — মালিকের সিদ্ধান্ত প্র৩, ৬ অক্টোবর ২০২৬ (সমন্বয়কের মারফত): ভাড়াটের চুক্তি, ভাড়াটের জামানত (দায়),
 * মাসের শুরুতে ভাড়া আয় (Dr ভাড়া প্রাপ্য / Cr ভাড়া আয়), আদায় আর বকেয়া।
 *
 * ── ⭐ দাখিলা ─────────────────────────────────────────────────────────────────
 *     মাসের দাবি (মাসের প্রথম দিনে)   Dr ১১২৫ ভাড়া প্রাপ্য  / Cr ৪৩২০ ভাড়া আয় (বা চুক্তির আয়ের খাত)
 *     জামানত নেওয়া                   Dr নগদ/ব্যাংক         / Cr ২১৫৫ ভাড়াটের জামানত
 *     ভাড়া আদায়                      Dr নগদ/ব্যাংক         / Cr ১১২৫
 *     জামানত থেকে কাটা                Dr ২১৫৫               / Cr ১১২৫
 *     জামানত ফেরত                     Dr ২১৫৫               / Cr নগদ/ব্যাংক
 *
 * ⭐ ১১২৫ আর ২১৫৫-এর প্রতিটা সারিতে ভাড়াটে পক্ষ হিসেবে বসেন (সমন্বয়কের শর্ত) — তাই পক্ষের খাতা ভাড়াটে ধরে জের দেখায়, আর সব
 * ভাড়াটের বকেয়ার যোগ ১১২৫-এর জের, জামানতের যোগ ২১৫৫-এর জের। ⓘ আদায় মাস ধরে নয়, টাকা ধরে — ভাড়াটে একসাথে দুই মাস দেন,
 * বা আংশিক; বেশি দিলে ১১২৫ তাঁর নামে ক্রেডিটে যায় (আগাম), পরের মাসের দাবি সেটা খেয়ে ফেলে।
 *
 * ── ⛔ পাহারা ───────────────────────────────────────────────────────────────────
 *   · মাসের দাবি এক চুক্তিতে এক মাস একবার (অনন্য চাবি + সারিতে তালা); চলতি মাস পর্যন্ত; বন্ধ মাসে নয় — খসড়াও নয়
 *   · জামানত থেকে কাটা বা ফেরত হাতে থাকা জামানতের বেশি নয় (সইয়ের অপেক্ষারগুলো বাদ দিয়ে)
 *   · টাকার খাত সত্যিই টাকার খাত ([[FinanceSignature::moneyAccount()]])
 *   · সব টাকা ভাড়ার নিজের সইয়ের ছকে ([[FinanceSignature::RENTAL]]); নতুন ছক নয়, তাই ডিপ্লয়ে নতুন ছক বসে না
 */
class TenancyService
{
    use ReadsTheRowUnderLock;

    public function __construct(
        private readonly VoucherService $vouchers,
        private readonly NumberSeriesEngine $numbers,
        private readonly FinanceSignature $signature,
        private readonly OpenPeriod $period,
        private readonly PartyRegistry $parties,
    ) {}

    /**
     * চুক্তি খোলা; জামানত তখনই হাতে এলে সেটাও (না এলে পরে [[receiveDeposit()]])।
     *
     * @param  array<string, mixed>  $data
     */
    public function open(array $data): Tenancy
    {
        $type = (string) ($data['party_type'] ?? '');
        $id = (int) ($data['party_id'] ?? 0);

        // ⛔ ভাড়াটে সবসময় একটা পক্ষ — নইলে ১১২৫ আর ২১৫৫-এর সারি কারো নামে বসত না
        if (! in_array($type, Tenancy::PARTY_TYPES, true) || $id <= 0 || ! $this->parties->exists($type, $id)) {
            throw ValidationException::withMessages(['party' => __('finance::tenancy.party_required')]);
        }

        $rent = (string) ($data['monthly_rent'] ?? '0');
        $deposit = (string) ($data['deposit_amount'] ?? '0');
        $term = (int) ($data['term_months'] ?? 0);

        if (bccomp($rent, '0', 4) <= 0 || $term <= 0 || bccomp($deposit, '0', 4) < 0) {
            throw ValidationException::withMessages(['monthly_rent' => __('finance::tenancy.terms_bad')]);
        }

        $starts = Carbon::parse((string) ($data['starts_on'] ?? now()->toDateString()))->startOfDay();
        $income = $this->incomeAccount($data['income_account_id'] ?? null);

        return DB::transaction(function () use ($data, $type, $id, $rent, $deposit, $term, $starts, $income) {
            $tenancy = Tenancy::query()->create([
                'branch_id' => CompanyContext::branchId(),
                'document_no' => $this->numbers->next('TNT'),
                'tenant' => filled($data['tenant'] ?? null)
                    ? (string) $data['tenant']
                    : ($this->parties->labelsOf([[$type, $id]])[$type.':'.$id] ?? $type),
                'tenant_phone' => $data['tenant_phone'] ?? null,
                'party_type' => $type,
                'party_id' => $id,
                'premises' => $data['premises'] ?? null,
                'income_account_id' => $income->id,
                'deposit_amount' => $deposit,
                'monthly_rent' => $rent,
                'rent_day' => (int) ($data['rent_day'] ?? 5),
                'starts_on' => $starts->toDateString(),
                'term_months' => $term,
                // ⓘ ১ জানুয়ারির ১২ মাস শেষ হয় ৩১ ডিসেম্বরে ([[RentalContractService::open()]]-এর একই কথা)
                'ends_on' => $starts->copy()->addMonths($term)->subDay()->toDateString(),
                'status' => Tenancy::ACTIVE,
                'note' => $data['note'] ?? null,
                'created_by' => auth()->id(),
            ]);

            if (filled($data['money_account_id'] ?? null) && bccomp($deposit, '0', 4) > 0) {
                $held = $this->move($tenancy, TenancyMove::DEPOSIT_IN, $deposit, $data + ['moved_on' => $starts->toDateString()]);

                // ⛔ জামানতের সই পড়ার আগে চুক্তি চলে না — মাসের দাবিও বসে না
                if ($held) {
                    $tenancy->update(['status' => Tenancy::AWAITING]);
                }
            }

            return $tenancy->fresh();
        });
    }

    /** @param  array<string, mixed>  $data */
    public function receiveDeposit(Tenancy $tenancy, array $data): TenancyMove
    {
        return $this->guarded($tenancy, TenancyMove::DEPOSIT_IN, $data);
    }

    /** ⭐ ভাড়া আদায় — টাকা ধরে, মাস ধরে নয়; বন্ধ চুক্তির পুরনো বকেয়াও আদায় হয় @param  array<string, mixed>  $data */
    public function collect(Tenancy $tenancy, array $data): TenancyMove
    {
        return $this->guarded($tenancy, TenancyMove::RENT, $data);
    }

    /** @param  array<string, mixed>  $data */
    public function fromDeposit(Tenancy $tenancy, array $data): TenancyMove
    {
        return $this->guarded($tenancy, TenancyMove::FROM_DEPOSIT, $data);
    }

    /** ⭐ জামানত ফেরত — চুক্তি চালু বা বন্ধ যা-ই হোক; হাতে থাকার বেশি নয় @param  array<string, mixed>  $data */
    public function refund(Tenancy $tenancy, array $data): TenancyMove
    {
        return $this->guarded($tenancy, TenancyMove::REFUND, $data);
    }

    /**
     * চুক্তি শেষ — আর মাসের দাবি বসে না। ⓘ বকেয়া থাকলে সেটা পাওনাই থাকে (আদায় চলে), জামানত ফেরত আলাদা কাগজ ([[refund()]])।
     *
     * @param  array<string, mixed>  $data
     */
    public function close(Tenancy $tenancy, array $data): Tenancy
    {
        return DB::transaction(function () use ($tenancy, $data) {
            $this->lockFresh($tenancy);

            if (! $tenancy->isActive()) {
                throw ValidationException::withMessages(['status' => __('finance::tenancy.not_active')]);
            }

            $tenancy->update([
                'status' => Tenancy::CLOSED,
                'closed_on' => Carbon::parse((string) ($data['closed_on'] ?? now()->toDateString()))->toDateString(),
            ]);

            return $tenancy->fresh();
        });
    }

    /**
     * শর্ত বদল — ভাড়া, ভাড়ার দিন, ফোন, জায়গা, টীকা। ⓘ যে মাসের দাবি বসে গেছে সেটা নিজের অঙ্কেই থাকে; বদল কেবল সামনের দাবিতে।
     *
     * @param  array<string, mixed>  $data
     */
    public function revise(Tenancy $tenancy, array $data): Tenancy
    {
        $rent = (string) ($data['monthly_rent'] ?? $tenancy->monthly_rent);

        if (bccomp($rent, '0', 4) <= 0) {
            throw ValidationException::withMessages(['monthly_rent' => __('finance::tenancy.terms_bad')]);
        }

        $tenancy->update(array_filter([
            'monthly_rent' => $rent,
            'rent_day' => $data['rent_day'] ?? null,
            'tenant_phone' => $data['tenant_phone'] ?? null,
            'premises' => $data['premises'] ?? null,
            'note' => $data['note'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));

        return $tenancy->fresh();
    }

    /**
     * ⭐ মাসের ভাড়া দাবি — চালু প্রতিটা চুক্তি, যার মেয়াদে মাসটা পড়ে আর যার মাসটা এখনো বসেনি।
     *
     * @return array{charged: int, held: int}
     */
    public function charge(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth()->startOfDay();
        $end = $start->copy()->endOfMonth()->startOfDay();

        if ($start->gt(Carbon::today()->startOfMonth())) {
            throw ValidationException::withMessages(['month' => __('finance::tenancy.charge_future')]);
        }

        if (($lock = $this->period->lockOn($start)) !== null) {
            throw ValidationException::withMessages(['month' => __('finance::tenancy.charge_closed', ['month' => $lock->label()])]);
        }

        $charged = 0;
        $held = 0;

        $tenancies = Tenancy::query()->active()
            ->whereDate('starts_on', '<=', $end->toDateString())
            ->whereDate('ends_on', '>=', $start->toDateString())
            ->orderBy('id')->get();

        foreach ($tenancies as $tenancy) {
            if ($this->monthCharged($tenancy, $start)) {
                continue;
            }

            $wasHeld = DB::transaction(function () use ($tenancy, $start, &$charged): ?bool {
                $this->lockFresh($tenancy);

                // ⛔ তালার পরে আবার — দুইজন একসাথে একই মাস চালালেও একবারই
                if (! $tenancy->isActive() || $this->monthCharged($tenancy, $start)) {
                    return null;
                }

                $amount = bcadd((string) $tenancy->monthly_rent, '0', 2);
                $on = $tenancy->starts_on->gt($start) ? $tenancy->starts_on->copy() : $start->copy();

                $voucher = $this->vouchers->create([
                    'type' => Voucher::JOURNAL,
                    'branch_id' => $tenancy->branch_id,
                    'trx_date' => $on->toDateString(),
                    'narration' => __('finance::tenancy.charge_narration', [
                        'who' => $tenancy->tenant, 'month' => $start->translatedFormat('F Y'),
                    ]),
                    'against_type' => Tenancy::drillSourceType(),
                    'against_id' => $tenancy->id,
                ], [
                    $this->partyLine($tenancy, $this->head(StandardChart::RENT_RECEIVABLE), $amount, '0'),
                    ['account_id' => $tenancy->income_account_id, 'debit' => '0', 'credit' => $amount],
                ]);

                TenancyCharge::query()->create([
                    'company_id' => CompanyContext::id(),
                    'branch_id' => $tenancy->branch_id,
                    'tenancy_id' => $tenancy->id,
                    'for_month' => $start->toDateString(),
                    'amount' => $amount,
                    'voucher_id' => $voucher->id,
                    'created_by' => auth()->id(),
                ]);

                $charged++;

                return $this->signature->postOrHold($voucher, FinanceSignature::RENTAL, $amount);
            });

            $held += $wasHeld === true ? 1 : 0;
        }

        return ['charged' => $charged, 'held' => $held];
    }

    /** ⭐ শেষ সই পড়ল — খসড়া খাতায়; জামানতের অপেক্ষায় থাকা চুক্তি চালু ([[FinishTheFinancePaperOnTheLastSignature]]) */
    public function finishSigned(Voucher $voucher): void
    {
        DB::transaction(function () use ($voucher): void {
            $this->lockFresh($voucher);

            if (! $voucher->isDraft()) {
                return;
            }

            $this->vouchers->post($voucher);

            $tenancy = Tenancy::query()->find($voucher->against_id);

            if ($tenancy !== null && $tenancy->status === Tenancy::AWAITING
                && TenancyMove::query()->where('voucher_id', $voucher->id)->where('kind', TenancyMove::DEPOSIT_IN)->exists()) {
                $tenancy->update(['status' => Tenancy::ACTIVE]);
            }
        });
    }

    /** ⭐ সইকারী "না" বললেন — খসড়া বাতিল, সারি মোছা; খোলার জামানতই "না" হলে চুক্তিটা ভুল করে বসানো চুক্তির মতো সরে যায় */
    public function dropRefused(Voucher $voucher, string $reason): void
    {
        DB::transaction(function () use ($voucher, $reason): void {
            $this->lockFresh($voucher);

            if (! $voucher->isDraft()) {
                return;
            }

            $this->vouchers->cancel($voucher, $reason);
            TenancyCharge::query()->where('voucher_id', $voucher->id)->delete();
            TenancyMove::query()->where('voucher_id', $voucher->id)->delete();

            $tenancy = Tenancy::query()->find($voucher->against_id);

            if ($tenancy !== null && $tenancy->status === Tenancy::AWAITING) {
                $tenancy->delete();
            }
        });
    }

    /** এই ভাউচারটা কি কোনো ভাড়াটের — সইয়ের শ্রোতা ভাড়ার চুক্তির পথ থেকে আলাদা করে চেনে */
    public static function isTenancy(Voucher $voucher): bool
    {
        return $voucher->against_type === Tenancy::drillSourceType();
    }

    /** ভাড়াটের কোনো কাগজ কি সইয়ের অপেক্ষায় — পর্দার বার্তার জন্য */
    public function isWaiting(Tenancy $tenancy): bool
    {
        return Voucher::query()->where('against_type', Tenancy::drillSourceType())->where('against_id', $tenancy->id)
            ->where('status', DocumentStatus::DRAFT)->exists();
    }

    // ── ভিতরের ─────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $data */
    private function guarded(Tenancy $tenancy, string $kind, array $data): TenancyMove
    {
        return DB::transaction(function () use ($tenancy, $kind, $data) {
            $this->lockFresh($tenancy);

            // ⓘ আদায় আর ফেরত বন্ধ চুক্তিতেও (পুরনো বকেয়া, শেষের জামানত); নতুন জামানত বা কাটা কেবল চালুতে
            $allowed = in_array($kind, [TenancyMove::RENT, TenancyMove::REFUND], true)
                ? in_array($tenancy->status, [Tenancy::ACTIVE, Tenancy::CLOSED], true)
                : $tenancy->isActive();

            if (! $allowed) {
                throw ValidationException::withMessages(['status' => __('finance::tenancy.not_active')]);
            }

            $amount = bcadd((string) ($data['amount'] ?? '0'), '0', 2);

            if (bccomp($amount, '0', 2) <= 0) {
                throw ValidationException::withMessages(['amount' => __('finance::tenancy.amount_bad')]);
            }

            // ⛔ জামানতে যা নেই তা কাটা বা ফেরত যায় না — ২১৫৫ ঐ ভাড়াটের নামে উল্টো দিকে যেত
            if (in_array($kind, [TenancyMove::FROM_DEPOSIT, TenancyMove::REFUND], true) && bccomp($amount, $tenancy->depositFree(), 2) > 0) {
                throw ValidationException::withMessages([
                    'amount' => __('finance::tenancy.deposit_short', ['left' => Money::format($tenancy->depositFree())]),
                ]);
            }

            $this->move($tenancy, $kind, $amount, $data);

            return TenancyMove::query()->where('tenancy_id', $tenancy->id)->latest('id')->firstOrFail();
        });
    }

    /**
     * এক নড়াচড়া — ভাউচার, সারি, আর সই। ফেরত: সইয়ের অপেক্ষায় কি না।
     *
     * @param  array<string, mixed>  $data
     */
    private function move(Tenancy $tenancy, string $kind, string $amount, array $data): bool
    {
        $money = $kind === TenancyMove::FROM_DEPOSIT ? null : $this->signature->moneyAccount($data['money_account_id'] ?? null);
        $on = Carbon::parse((string) ($data['moved_on'] ?? now()->toDateString()))->toDateString();
        $deposits = $this->head(StandardChart::TENANT_DEPOSITS);
        $receivable = $this->head(StandardChart::RENT_RECEIVABLE);

        [$type, $lines] = match ($kind) {
            TenancyMove::DEPOSIT_IN => [Voucher::RECEIPT, [
                ['account_id' => $money->id, 'debit' => $amount, 'credit' => '0'],
                $this->partyLine($tenancy, $deposits, '0', $amount),
            ]],
            TenancyMove::RENT => [Voucher::RECEIPT, [
                ['account_id' => $money->id, 'debit' => $amount, 'credit' => '0'],
                $this->partyLine($tenancy, $receivable, '0', $amount),
            ]],
            TenancyMove::FROM_DEPOSIT => [Voucher::JOURNAL, [
                $this->partyLine($tenancy, $deposits, $amount, '0'),
                $this->partyLine($tenancy, $receivable, '0', $amount),
            ]],
            TenancyMove::REFUND => [Voucher::PAYMENT, [
                $this->partyLine($tenancy, $deposits, $amount, '0'),
                ['account_id' => $money->id, 'debit' => '0', 'credit' => $amount],
            ]],
        };

        $voucher = $this->vouchers->create([
            'type' => $type,
            'branch_id' => $tenancy->branch_id,
            'trx_date' => $on,
            'narration' => __('finance::tenancy.narration_'.$kind, ['who' => $tenancy->tenant, 'no' => $tenancy->document_no]),
            'against_type' => Tenancy::drillSourceType(),
            'against_id' => $tenancy->id,
            'instrument_no' => ($data['instrument_no'] ?? '') ?: null,
        ], $lines);

        TenancyMove::query()->create([
            'company_id' => CompanyContext::id(),
            'branch_id' => $tenancy->branch_id,
            'tenancy_id' => $tenancy->id,
            'kind' => $kind,
            'moved_on' => $on,
            'amount' => $amount,
            'money_account_id' => $money?->id,
            'voucher_id' => $voucher->id,
            'note' => $data['note'] ?? null,
            'created_by' => auth()->id(),
        ]);

        return $this->signature->postOrHold($voucher, FinanceSignature::RENTAL, $amount);
    }

    /** @return array<string, mixed> ভাড়াটের নামে বসা সারি — পক্ষের খাতা ভাড়াটে ধরে জের দেখায় */
    private function partyLine(Tenancy $tenancy, Account $account, string $debit, string $credit): array
    {
        return [
            'account_id' => $account->id, 'debit' => $debit, 'credit' => $credit,
            'party_type' => $tenancy->party_type, 'party_id' => (int) $tenancy->party_id,
        ];
    }

    private function monthCharged(Tenancy $tenancy, Carbon $start): bool
    {
        return TenancyCharge::query()->where('tenancy_id', $tenancy->id)->whereDate('for_month', $start->toDateString())->exists();
    }

    /** আয়ের খাত — না দিলে ৪৩২০; দিলে পোস্টযোগ্য, চালু আয়ের খাতই */
    private function incomeAccount(mixed $id): Account
    {
        if ($id === null || $id === '') {
            return $this->head(StandardChart::RENT_INCOME);
        }

        $account = Account::query()->postable()->active()->where('type', Account::INCOME)->find($id);

        if ($account === null) {
            throw ValidationException::withMessages(['income_account_id' => __('finance::tenancy.income_account_bad')]);
        }

        return $account;
    }

    /** ছকের খাত — না থাকলে (পুরনো কোম্পানি) ছক একবার বসিয়ে নেয় */
    private function head(string $code): Account
    {
        $account = StandardChart::find($code);

        if ($account === null) {
            app(StandardChart::class)->install();
            $account = StandardChart::find($code);
        }

        if ($account === null) {
            throw ValidationException::withMessages(['month' => __('finance::validation.chart_head_missing', ['code' => $code])]);
        }

        return $account;
    }
}

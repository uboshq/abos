<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Hr\Models\BankUnpaidLine;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\HeldWagePayment;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Support\BranchReach;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * ⭐ দেওয়ার অপেক্ষায় বকেয়া মজুরি — আটকে থাকা স্লিপ, যার কর্মীর ব্যাংক বা MFS হিসাব এখন সই-পড়া; যেকোনো দিন দেওয়া যায় (fe, ২১ অক্টোবর
 * ২০২৬; শ্রম আইন ধারা ১২৩ — পরের মাসের রানের অপেক্ষা আবার দেরি)।
 *
 * ⓘ দুই পথ, এক খাতা: বকেয়ার নিজের কাগজ ([[pay()]] — ব্যাংকের ফাইল বা MFS-এর তালিকা, সাথে খসড়া পরিশোধ ভাউচার), বা পরের রানের
 * ব্যাংক ফাইল ([[PayrollService::arrearsFor()]])। ⛔ যে পথেই যাক, [[HeldWagePayment]]-এ স্লিপ প্রতি একটা সারি (unique) — দুইবার কখনো নয়।
 *
 * ⓘ টাকা খাতায় যায় হিসাবের চেনা পথে: খসড়া পরিশোধ ভাউচার, Dr প্রদেয় বেতন / Cr বাছা ব্যাংক বা MFS খাত — খরচের দাবির খসড়ার
 * অবিকল ধাঁচ ([[ExpenseClaimService]])। পাকা করেন হিসাবরক্ষক ভাউচারের পর্দায়, কোম্পানির নিজের সইয়ের নিয়মে (maker-checker)।
 */
final class HeldWages
{
    public function __construct(private readonly VoucherService $vouchers) {}

    /**
     * ⭐ দেওয়ার অপেক্ষায় — নিশ্চিত রানের আটকে থাকা স্লিপ, এখনো কোনো পথে দেওয়া হয়নি, আর কর্মীর পথ এখন সই-পড়া।
     *
     * @return Collection<int, array{payslip: Payslip, employee: Employee, channel: string, amount: string}>
     */
    public function ready(?User $user = null): Collection
    {
        $query = Payslip::query()
            ->where('payment_method', PayrollService::HELD)
            ->where('net', '>', 0)
            ->whereDoesntHave('heldPayment')
            ->whereHas('run', fn ($q) => $q->where('status', DocumentStatus::CONFIRMED))
            ->with(['employee', 'run']);

        $query = $user === null ? $query : app(BranchReach::class)->throughEmployee($query, $user);

        return $query->orderBy('id')->get()
            ->filter(fn (Payslip $slip) => $slip->employee !== null && self::channelOf($slip->employee) !== null)
            ->map(fn (Payslip $slip) => [
                'payslip' => $slip,
                'employee' => $slip->employee,
                'channel' => (string) self::channelOf($slip->employee),
                'amount' => Money::round((string) $slip->net, 2),
            ])
            ->values();
    }

    /** ⓘ সই-পড়া পথ — ব্যাংক (নম্বরসহ) বা MFS (নম্বরসহ); নাহলে `null` */
    public static function channelOf(Employee $employee): ?string
    {
        if (PayrollService::heldReason($employee) !== null) {
            return null;
        }

        return match ((string) $employee->payment_method) {
            'bank' => filled($employee->bank_account_no) ? 'bank' : null,
            'mfs' => filled($employee->mfs_number) ? 'mfs' : null,
            default => null,
        };
    }

    /**
     * ⭐ দেওয়া — বাছা স্লিপগুলো, এক পথে (ব্যাংক বা MFS), এক টাকার খাত থেকে: খসড়া পরিশোধ ভাউচার আর দেওয়ার কাগজ (CSV)।
     *
     * ⛔ একবারই: প্রতিটা স্লিপের সারি এই লেনদেনেই বসে (unique) — আগে কোনো পথে দেওয়া হয়ে থাকলে গোটা চাপটা ফেরে, কিছুই লেখা হয় না।
     *
     * @param  list<int>  $payslipIds
     * @return array{voucher: Voucher, name: string, content: string}
     */
    public function pay(array $payslipIds, int $moneyAccountId, User $by): array
    {
        $money = Account::query()->whereKey($moneyAccountId)->first();

        if ($money === null || ! in_array($money->money_kind, [Account::BANK, Account::MFS], true)) {
            throw ValidationException::withMessages(['account_id' => __('hr::payroll.held_pay_account')]);
        }

        $channel = $money->money_kind === Account::MFS ? 'mfs' : 'bank';

        return DB::transaction(function () use ($payslipIds, $money, $channel, $by) {
            $slips = Payslip::query()->whereKey($payslipIds)->with(['employee', 'run'])->lockForUpdate()->get();

            if ($slips->isEmpty() || $slips->count() !== count(array_unique($payslipIds))) {
                throw ValidationException::withMessages(['payslips' => __('hr::payroll.held_pay_none')]);
            }

            foreach ($slips as $slip) {
                if (HeldWagePayment::query()->where('payslip_id', $slip->id)->exists()) {
                    throw ValidationException::withMessages(['payslips' => __('hr::payroll.held_pay_twice', ['code' => (string) $slip->employee?->code])]);
                }

                if ($slip->payment_method !== PayrollService::HELD || $slip->run?->status !== DocumentStatus::CONFIRMED
                    || $slip->employee === null || self::channelOf($slip->employee) !== $channel) {
                    throw ValidationException::withMessages(['payslips' => __('hr::payroll.held_pay_not_ready', ['code' => (string) $slip->employee?->code])]);
                }
            }

            $total = $slips->reduce(fn (string $sum, Payslip $slip) => bcadd($sum, Money::round((string) $slip->net, 2), 4), '0');
            $payable = StandardChart::find(StandardChart::SALARY_PAYABLE);
            $branch = Company::query()->find(CompanyContext::id())?->defaultBranch()?->id;
            $reference = 'ARR'.Carbon::now()->format('ymdHis').str_pad((string) $slips->first()->id, 6, '0', STR_PAD_LEFT);

            // ⓘ হিসাবের চেনা পথ — খসড়া; পাকা করেন হিসাবরক্ষক, কোম্পানির সইয়ের নিয়মে
            $voucher = $this->vouchers->create([
                'type' => Voucher::PAYMENT,
                'branch_id' => $branch,
                'trx_date' => Carbon::today()->toDateString(),
                'narration' => __('hr::payroll.held_pay_narration', ['count' => $slips->count()]),
                'instrument' => $channel === 'mfs' ? 'mfs' : 'transfer',
                'instrument_no' => $reference,
            ], [
                ['account_id' => (int) $payable->id, 'debit' => $total, 'credit' => '0'],
                ['account_id' => (int) $money->id, 'debit' => '0', 'credit' => $total],
            ]);

            foreach ($slips as $slip) {
                HeldWagePayment::query()->create([
                    'company_id' => (int) $slip->company_id,
                    'payslip_id' => (int) $slip->id,
                    'payroll_run_id' => (int) $slip->payroll_run_id,
                    'paid_in_run_id' => null,
                    'voucher_id' => (int) $voucher->id,
                    'channel' => $channel,
                    'amount' => (string) $slip->net,
                    'created_by' => $by->id,
                ]);
            }

            return ['voucher' => $voucher, ...$this->paper($voucher)];
        });
    }

    /**
     * ⭐ বকেয়ার খসড়া ভাউচার "না" পেল বা বাতিল হলো — মজুরি চিরকাল আটকে থাকে না (শ্রম আইন ধারা ১২৩; fe, ২১ অক্টোবর ২০২৬;
     * [[ReleaseHeldWagesWhenTheVoucherFalls]])।
     *
     * ⓘ এই ভাউচারের "দেওয়া হয়েছে" সারিগুলো মোছা — স্লিপ আবার "দেওয়ার অপেক্ষায়", আবার দেওয়া যায় (একবারের নিয়ম অটুট: সারি না থাকা
     * মানে এখনো দেওয়া হয়নি)। প্রতিটা কর্মীর অডিটে `held_wage_payment_released`, পুরনো ভাউচারের নম্বরসহ।
     * ⛔ যে ভাউচার কখনো খাতায় বসেছিল (পাকা, পরে বাতিল হলেও) — ছাড়া হয় না: টাকা হয়তো সত্যিই গেছে; সেটা হিসাবের মানুষের হাতে দেখার কথা।
     *
     * @return int কয়টা সারি ছাড়া হলো
     */
    public function releaseFor(int $voucherId): int
    {
        $voucher = Voucher::query()->withoutGlobalScopes()->find($voucherId);

        if ($voucher === null || $voucher->type !== Voucher::PAYMENT) {
            return 0;
        }

        // ⛔ খাতায় কখনো বসেছিল কি না — পাকা ভাউচারের নিজের সারি থাকে (বাতিলে উল্টো সারি আলাদা নামে)
        $everPosted = DB::table('ledger_entries')
            ->where('company_id', (int) $voucher->company_id)
            ->where('source_type', Voucher::SOURCE_TYPES[Voucher::PAYMENT])
            ->where('source_id', $voucher->id)
            ->exists();

        if ($everPosted || $voucher->isPosted()) {
            return 0;
        }

        $released = 0;

        foreach (HeldWagePayment::query()->where('voucher_id', $voucher->id)->with('payslip.employee')->get() as $payment) {
            $payment->payslip?->employee?->auditAction('held_wage_released',
                __('hr::payroll.held_released', ['no' => (string) $voucher->document_no]));
            $payment->delete();
            $released++;
        }

        return $released;
    }

    /** ⭐ "ব্যাংক এই লাইন দেয়নি"-র সই — অনুমোদনের ছকে `hr` · `bank_unpaid` (module.php-র `second_hand`, বেতনের রানের একই নিয়ম) */
    public const UNPAID_MODULE = 'hr';

    public const UNPAID_ACTION = 'bank_unpaid';

    /**
     * ⭐ ব্যাংকে পাঠানো — নিশ্চিতের অপেক্ষায় (fe-র সিদ্ধান্ত ২, ২১ অক্টোবর ২০২৬; শ্রম আইন ধারা ১২৩)।
     *
     * ⓘ যে বকেয়া মজুরি কোনো রানের ব্যাংক ফাইলে গিয়েছিল ([[PayrollService::arrearsFor()]] — ফাইল নামানোর সময় সারি বসে), আর সেই রান
     * পরে বাতিল। ⛔ রান বাতিলে সারিটা নিজে নিজে ছাড়া হয় না: ফাইলটা ব্যাংকে উঠে গিয়ে থাকলে টাকা গেছে — ছাড়লে মজুরি দুইবার। হিসাবের
     * মানুষ ব্যাংকের বিবরণী মিলিয়ে "ব্যাংক এই লাইন দেয়নি" বলেন ([[askBankUnpaid()]]), আরেকজনের দ্বিতীয় সইয়ে তবে ছাড়া।
     *
     * @return Collection<int, array{payslip: Payslip, employee: ?Employee, payment: HeldWagePayment, held_run: ?PayrollRun, carrier: ?PayrollRun, amount: string, asked: ?BankUnpaidLine}>
     */
    public function awaitingBank(?User $user = null): Collection
    {
        $query = Payslip::query()
            ->whereHas('heldPayment', fn ($q) => $q->whereIn('paid_in_run_id',
                PayrollRun::acrossBranches()->where('status', DocumentStatus::CANCELLED)->select('id')))
            ->with(['employee', 'heldPayment']);

        $query = $user === null ? $query : app(BranchReach::class)->throughEmployee($query, $user);
        $slips = $query->orderBy('id')->get();

        if ($slips->isEmpty()) {
            return collect();
        }

        // ⓘ রান সব শাখা জুড়ে — বাতিল রানটা অন্য শাখার হলেও লাইনটা দেখা যায় (কর্মী নাগালে, তাই যথেষ্ট)
        $runs = PayrollRun::acrossBranches()
            ->whereKey([...$slips->pluck('payroll_run_id')->all(), ...$slips->pluck('heldPayment.paid_in_run_id')->all()])
            ->get()->keyBy('id');
        $asked = BankUnpaidLine::query()->whereIn('held_wage_payment_id', $slips->pluck('heldPayment.id')->all())
            ->where('status', BankUnpaidLine::PENDING)->get()->keyBy('held_wage_payment_id');

        return $slips->map(fn (Payslip $slip) => [
            'payslip' => $slip,
            'employee' => $slip->employee,
            'payment' => $slip->heldPayment,
            'held_run' => $runs->get($slip->payroll_run_id),
            'carrier' => $runs->get($slip->heldPayment->paid_in_run_id),
            'amount' => Money::round((string) $slip->heldPayment->amount, 2),
            'asked' => $asked->get($slip->heldPayment->id),
        ])->values();
    }

    /** ⓘ এই "দেওয়া হয়েছে" সারি কি বাতিল রানের ব্যাংক ফাইলের — "ব্যাংকে পাঠানো — নিশ্চিতের অপেক্ষায়" */
    public static function awaitsTheBank(HeldWagePayment $payment): bool
    {
        return $payment->paid_in_run_id !== null
            && PayrollRun::acrossBranches()->whereKey($payment->paid_in_run_id)->value('status') === DocumentStatus::CANCELLED;
    }

    /**
     * ⭐ "ব্যাংক এই লাইন দেয়নি" — কারণসহ চাওয়া; দ্বিতীয় সইয়ের অপেক্ষায় (fe-র সিদ্ধান্ত ২, ২১ অক্টোবর ২০২৬)।
     *
     * ⛔ কারণ ছাড়া নয় — ব্যাংকের বিবরণীর কোন কথায় বলা হলো, নিরীক্ষকের প্রশ্ন সেটাই। ⛔ কেবল "নিশ্চিতের অপেক্ষায়" লাইনে — চালু রানের
     * ফাইল বা বকেয়ার নিজের কাগজের লাইনে নয় (সেটার পথ ভাউচার, [[releaseFor()]])। ⛔ এক লাইনে একটাই অপেক্ষার চাওয়া।
     * ⓘ সই সইয়ের ইঞ্জিনে, বেতনের রানের একই নিয়মে — ছক না থাকলেও যন্ত্রের "দ্বিতীয় সই", যিনি চাইলেন তিনি কখনো নন, মালিকও নন
     * ([[ApprovalEngine::twoHands()]])। মজুরি ছাড়ে কেবল শেষ সইয়ে ([[finishBankUnpaid()]])। কর্মীর অডিটে `bank_unpaid_asked`।
     */
    public function askBankUnpaid(HeldWagePayment $payment, ?string $reason, User $by): BankUnpaidLine
    {
        $reason = mb_substr(trim((string) $reason), 0, 500);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('hr::payroll.bank_unpaid_reason_required')]);
        }

        return DB::transaction(function () use ($payment, $reason, $by): BankUnpaidLine {
            $fresh = HeldWagePayment::query()->whereKey($payment->getKey())->lockForUpdate()->first();

            if ($fresh === null || ! self::awaitsTheBank($fresh)) {
                throw ValidationException::withMessages(['payment' => __('hr::payroll.bank_unpaid_not_awaiting')]);
            }

            if (BankUnpaidLine::query()->where('held_wage_payment_id', $fresh->id)->where('status', BankUnpaidLine::PENDING)->exists()) {
                throw ValidationException::withMessages(['payment' => __('hr::payroll.bank_unpaid_already_asked')]);
            }

            $slip = Payslip::query()->with('employee')->findOrFail($fresh->payslip_id);
            $carrier = (string) PayrollRun::acrossBranches()->whereKey($fresh->paid_in_run_id)->value('document_no');

            $line = BankUnpaidLine::query()->create([
                'company_id' => (int) $fresh->company_id,
                'employee_id' => (int) $slip->employee_id,
                'payslip_id' => (int) $slip->id,
                'held_wage_payment_id' => (int) $fresh->id,
                'payroll_run_id' => (int) $fresh->paid_in_run_id,
                'amount' => (string) $fresh->amount,
                'reason' => $reason,
                'status' => BankUnpaidLine::PENDING,
                'requested_by' => $by->id,
            ]);

            $slip->employee?->auditAction('bank_unpaid_asked', __('hr::payroll.bank_unpaid_asked_audit', ['no' => $carrier, 'reason' => $reason]));

            $approval = app(ApprovalEngine::class)->request(
                $line,
                self::UNPAID_MODULE,
                self::UNPAID_ACTION,
                amount: null,
                reason: __('hr::payroll.bank_unpaid_asked_reason', ['employee' => (string) $slip->employee?->name(), 'no' => $carrier]),
                userId: (int) $by->id,
                matchOn: [],
            );

            // ⛔ সই ছাড়া কখনো নয় — ঘোষণা (`second_hand`) সরানো হলে চাওয়াটাই ফেরে, এক হাতে মজুরি ছাড়ার পথ খোলে না
            if ($approval === null) {
                throw new RuntimeException('hr.bank_unpaid must declare its second_hand in module.php — a wage is never released by one hand.');
            }

            return $line->fresh();
        });
    }

    /**
     * ⭐ দ্বিতীয় সই — পাতার বোতাম; ইনবক্সের একই ইঞ্জিন, একই প্রশ্ন ([[ApprovalEngine::canDecide()]])।
     *
     * ⛔ যিনি চাইলেন তিনি নন (মালিক ছাড়া — ২০ নভেম্বর ২০২৬, দাগসহ); চাবি ছাড়া কেউ নন; অপেক্ষায় নেই এমন অনুরোধে আর নয় — দ্বিতীয় চাপ ফেরে।
     */
    public function signBankUnpaid(BankUnpaidLine $line, User $by): BankUnpaidLine
    {
        $approval = $this->unpaidApprovalOf($line);

        if (! $line->isPending() || $approval === null || ! $approval->isPending()) {
            throw ValidationException::withMessages(['bank_unpaid' => __('hr::payroll.bank_unpaid_not_pending')]);
        }

        if (! app(ApprovalEngine::class)->canDecide($approval, $by)) {
            throw ValidationException::withMessages(['bank_unpaid' => (int) $line->requested_by === (int) $by->id
                ? __('hr::payroll.bank_unpaid_another_hand')
                : __('hr::payroll.bank_unpaid_no_right')]);
        }

        app(ApprovalEngine::class)->approve($approval, $by);

        return $line->fresh();
    }

    /**
     * ⭐ শেষ সই পড়ল — "দেওয়া হয়েছে" সারিটা মোছে, মজুরি আবার দেওয়ার তালিকায় ([[ready()]], [[PayrollService::heldWagesOwed()]], পরের
     * রানের ব্যাংক ফাইল) — একবারই; "না" হলে লাইন যেমন ছিল ([[FinishTheBankUnpaidLineOnTheLastSignature]])।
     *
     * ⛔ একবারই: লাইনে তালা দিয়ে তাজা অবস্থা — অপেক্ষায় না থাকলে কিছুই নয়; অনুরোধ `used`; আর সারিটা মোছে কেবল যদি সেটা এখনো একই
     * বাতিল রানের — মাঝে মজুরি অন্য পথে আবার দেওয়া হয়ে থাকলে সেই নতুন সারি কখনো ছোঁয়া হয় না। কর্মীর অডিটে `bank_unpaid_released`।
     */
    public function finishBankUnpaid(Approval $approval): void
    {
        $line = $approval->approvable;

        if (! $line instanceof BankUnpaidLine || ! $line->isPending()) {
            return;
        }

        $signerId = $approval->decisions()->whereIn('decision', [ApprovalDecision::APPROVED, ApprovalDecision::REJECTED])
            ->latest('id')->value('user_id');
        $signer = $signerId === null ? null : User::query()->find((int) $signerId);

        if ($approval->status === Approval::REJECTED) {
            $this->closeUnpaid($line, BankUnpaidLine::REJECTED, $signer, $approval->decisions()->latest('id')->value('remarks'), 'bank_unpaid_refused');

            return;
        }

        if ($approval->status !== Approval::APPROVED || $approval->isUsed() || $signer === null) {
            return;
        }

        DB::transaction(function () use ($line, $approval, $signer): void {
            $fresh = BankUnpaidLine::query()->whereKey($line->getKey())->lockForUpdate()->first();

            if ($fresh === null || ! $fresh->isPending()) {
                return;
            }

            $payment = $fresh->held_wage_payment_id === null ? null
                : HeldWagePayment::query()->whereKey($fresh->held_wage_payment_id)->lockForUpdate()->first();

            if ($payment !== null && (int) $payment->payslip_id === (int) $fresh->payslip_id
                && (int) $payment->paid_in_run_id === (int) $fresh->payroll_run_id) {
                $payment->delete();
            }

            $fresh->forceFill(['status' => BankUnpaidLine::RELEASED, 'decided_by' => $signer->id, 'decided_at' => now()])->save();
            $approval->markUsed();

            Employee::query()->find($fresh->employee_id)?->auditAction('bank_unpaid_released', __('hr::payroll.bank_unpaid_released_audit', [
                'no' => (string) PayrollRun::acrossBranches()->whereKey($fresh->payroll_run_id)->value('document_no'),
                'by' => (string) $signer->name,
                'reason' => (string) $fresh->reason,
            ]));
        });
    }

    /** ⓘ অনুমোদনের কেন্দ্রের "ফিরিয়ে নিন" — লাইন বন্ধ, মজুরির সারি যেমন ছিল */
    public function closeWithdrawnUnpaid(Approval $approval): void
    {
        $line = $approval->approvable;

        if ($line instanceof BankUnpaidLine && $line->isPending()) {
            $by = $approval->requested_by === null ? null : User::query()->find((int) $approval->requested_by);
            $this->closeUnpaid($line, BankUnpaidLine::CANCELLED, $by, null, 'bank_unpaid_withdrawn');
        }
    }

    /** ⓘ এই লাইনের সইয়ের অনুরোধ — সর্বশেষটা */
    public function unpaidApprovalOf(BankUnpaidLine $line): ?Approval
    {
        return app(ApprovalEngine::class)->latestFor($line, self::UNPAID_ACTION);
    }

    /** ⓘ পাতার বোতাম — এই মানুষ এখন সই দিতে পারেন কি না, ইনবক্সের একই প্রশ্নে */
    public function canSignUnpaid(BankUnpaidLine $line, ?User $user): bool
    {
        $approval = $this->unpaidApprovalOf($line);

        return $user !== null && $line->isPending() && $approval !== null && $approval->isPending()
            && app(ApprovalEngine::class)->canDecide($approval, $user);
    }

    private function closeUnpaid(BankUnpaidLine $line, string $status, ?User $by, ?string $note, string $audit): void
    {
        DB::transaction(function () use ($line, $status, $by, $note, $audit): void {
            $fresh = BankUnpaidLine::query()->whereKey($line->getKey())->lockForUpdate()->first();

            if ($fresh === null || ! $fresh->isPending()) {
                return;
            }

            $fresh->forceFill([
                'status' => $status,
                'decided_by' => $by?->id,
                'decided_at' => now(),
                'note' => filled($note) ? mb_substr(trim((string) $note), 0, 500) : null,
            ])->save();

            Employee::query()->find($fresh->employee_id)?->auditAction($audit, __('hr::payroll.bank_unpaid_closed_audit', [
                'no' => (string) PayrollRun::acrossBranches()->whereKey($fresh->payroll_run_id)->value('document_no'),
            ]));
        });
    }

    /**
     * ⓘ দেওয়ার কাগজ — একই ভাউচারের, যতবার নামানো হোক একই সারি (নতুন কিছু দেওয়া হয় না)।
     *
     * @return array{name: string, content: string}
     */
    public function paper(Voucher $voucher): array
    {
        $payments = HeldWagePayment::query()->where('voucher_id', $voucher->id)->with(['payslip.employee', 'payslip.run'])->orderBy('id')->get();
        $mfs = $payments->first()?->channel === 'mfs';

        $rows = [$mfs ? ['Number', 'Name', 'Amount', 'Reference'] : ['Account No', 'Account Name', 'Amount', 'Routing No', 'Bank', 'Employee Code', 'Reference']];

        foreach ($payments as $payment) {
            $employee = $payment->payslip?->employee;
            $reference = (string) __('hr::payroll.arrears_reference', ['no' => (string) $payment->payslip?->run?->document_no,
                'month' => (string) $payment->payslip?->run?->month->format('M Y')]);
            $amount = Money::round((string) $payment->amount, 2);

            $rows[] = $mfs
                ? [(string) $employee?->mfs_number, (string) $employee?->name_en, $amount, $reference]
                : [(string) $employee?->bank_account_no, (string) ($employee?->bank_account_name ?: $employee?->name_en), $amount,
                    (string) $employee?->bank_routing_no, (string) $employee?->bank_name, (string) $employee?->code, $reference];
        }

        $csv = '';

        foreach ($rows as $row) {
            $csv .= implode(',', array_map(fn (string $v) => '"'.str_replace('"', '""', ltrim($v, "=+-@\t\r")).'"', $row))."\r\n";
        }

        return [
            'name' => 'arrears-'.($mfs ? 'mfs' : 'bank').'-'.$voucher->document_no.'.csv',
            'content' => $csv,
        ];
    }
}

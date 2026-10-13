<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Concerns\ReadsTheRowUnderLock;
use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Engines\Attachment\AttachmentException;
use App\Core\Engines\Audit\AuditEngine;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\ExpenseClaim;
use App\Modules\Hr\Support\AdvanceBalance;
use App\Modules\Hr\Support\ClaimSigner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ কর্মীর টাকা চাওয়া — খরচের দাবি আর অগ্রিম অনুরোধ (মালিকের আদেশ, ৭ অক্টোবর ২০২৬: টাকা আসা-যাওয়ার আন্তর্জাতিক পরিকল্পনা,
 * ভাগ ১৩-গ)।
 *
 * ── ধাপ ──────────────────────────────────────────────────────────────────────
 *   ১ অনুরোধ — কর্মী নিজে (ওয়েব বা ফোন): খাত, অঙ্ক, তারিখ, কারণ, রসিদের ছবি; বা অগ্রিমের অঙ্ক আর কারণ
 *   ২ অনুমোদন — অঙ্কের সীমা অনুযায়ী, কোম্পানির নিজের ছকে ([[DocumentApproval]]); যিনি চাইলেন তিনি সই দেন না
 *     ([[ApprovalEngine::canDecide()]] — মালিক একাই সব করলে আটকায় না, সিদ্ধান্তের সারিতে দুই নামই থাকে)
 *   ৩ শেষ সইয়ে খসড়া ভাউচার নিজে থেকে ([[approve()]]); ক্যাশিয়ার টাকা দিয়ে পাকা করেন — "নগদ কেবল নিজের টিল থেকে"
 *     পাকা করার সময় খাটে ([[VoucherService::assertCashLandsInOwnTill()]]), আর ক্যাশিয়ার খসড়ায় নিজের টিল বসিয়ে নেন
 *   ৪ অগ্রিম মেলানো — খরচের দাবি আগে কর্মীর খোলা অগ্রিম থেকে মেটে (Dr খরচ / Cr ১১৩১ তাঁর নামে, সাথে সাথে — টাকা নড়ে না);
 *     বাকিটুকু নগদে। অগ্রিম বাকি থাকলে ফেরত (রসিদ) বা বেতন থেকে কাটা ([[PayrollService]], খোলা জেরের বেশি নয়)
 *
 * ── খাতা ─────────────────────────────────────────────────────────────────────
 *     খরচ (নগদে)            Dr খরচের খাত           / Cr নগদ        — ক্যাশিয়ারের খসড়া
 *     অগ্রিম                Dr ১১৩১ কর্মীর নামে     / Cr নগদ        — ক্যাশিয়ারের খসড়া
 *     খরচ (অগ্রিম থেকে)     Dr খরচের খাত           / Cr ১১৩১ কর্মীর নামে — শেষ সইয়ে খাতায়
 */
final class ExpenseClaimService
{
    use ReadsTheRowUnderLock;

    public const MODULE = 'hr';

    public function __construct(
        private readonly DocumentApproval $approvals,
        private readonly VoucherService $vouchers,
        private readonly NumberSeriesEngine $numbers,
        private readonly AttachmentEngine $attachments,
        private readonly AdvanceBalance $advances,
    ) {}

    /** ব্যবহারকারীর নিজের কর্মীর খাতা — না থাকলে টাকা চাওয়া যায় না */
    public function employeeOf(?User $user): ?Employee
    {
        return $user === null ? null : Employee::query()->withoutGlobalScopes()
            ->where('company_id', CompanyContext::id())->where('user_id', $user->id)->first();
    }

    /**
     * ⭐ অনুরোধ — কর্মী নিজে। সবসময় সই চায় — ছক না থাকলে মালিকের ([[ClaimSigner]]); কোম্পানির নিজের ছকের সীমার নিচে হলে সাথে সাথে।
     *
     * @param  array<string, mixed>  $data
     */
    public function submit(User $user, array $data, ?UploadedFile $receipt = null): ExpenseClaim
    {
        $employee = $this->employeeOf($user);

        if ($employee === null) {
            throw ValidationException::withMessages(['employee' => __('hr::claim.no_employee')]);
        }

        /*
         * ⛔ চলে যাওয়া বা মুছে ফেলা কর্মী টাকা চান না — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (HR ৩; [[AGoneEmployeeCannotAskForMoneyTest]])।
         * ⓘ খাতা খোঁজা হয় সব ছাঁকনি সরিয়ে ([[employeeOf()]] — কোম্পানি-ভাগের সুইচের জন্য), তাতে মুছে ফেলা খাতাও উঠত; আর নিষ্ক্রিয়
         * বা ছাড়ার তারিখ পেরোনো কর্মীর লগইন খোলা থাকলে তিনি অগ্রিম চাইতে পারতেন, যা আর বেতন থেকে কাটার উপায় নেই।
         */
        if ($employee->trashed() || ! $employee->is_active || ($employee->leaving_date !== null && $employee->leaving_date->lt(Carbon::today()))) {
            throw ValidationException::withMessages(['employee' => __('hr::claim.employee_gone')]);
        }

        $kind = (string) ($data['kind'] ?? '');

        if (! in_array($kind, ExpenseClaim::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => __('hr::claim.kind_bad')]);
        }

        $amount = bcadd((string) ($data['amount'] ?? '0'), '0', 2);

        if (bccomp($amount, '0', 2) <= 0) {
            throw ValidationException::withMessages(['amount' => __('hr::claim.amount_bad')]);
        }

        $reason = trim((string) ($data['reason'] ?? ''));

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('hr::claim.reason_required')]);
        }

        $account = null;
        $spentOn = null;

        if ($kind === ExpenseClaim::EXPENSE) {
            // ⛔ খরচের খাতই — পোস্টযোগ্য, চালু; নগদ বা অন্য খাতে "খরচ" নয়
            $account = Account::query()->postable()->active()->where('type', Account::EXPENSE)->find($data['expense_account_id'] ?? null);

            if ($account === null) {
                throw ValidationException::withMessages(['expense_account_id' => __('hr::claim.head_bad')]);
            }

            $spentOn = Carbon::parse((string) ($data['spent_on'] ?? now()->toDateString()))->startOfDay();

            if ($spentOn->gt(Carbon::today())) {
                throw ValidationException::withMessages(['spent_on' => __('hr::claim.spent_in_future')]);
            }
        }

        /*
         * ⛔ সই ছাড়া কখনো নয় — সমন্বয়কের আদেশ (অ্যাপ-অডিট, ৭ অক্টোবর ২০২৬; মালিকের নিয়ম "স্বয়ংক্রিয় অনুমোদন বাদ")।
         * ⓘ আগে ছক না থাকলে ইঞ্জিন `null` দিত আর দাবি সাথে সাথে অনুমোদিত হত। এখন ছক না থাকলে মালিকের সইয়ের ছক বসে
         * ([[ClaimSigner]]) — দাবি লেখার আগে, যাতে মালিক না পেলে দাবিটাই না বসে; কোম্পানির নিজের ছক থাকলে আগের মতোই সেটা।
         */
        $approvals = app(ClaimSigner::class)->ensure(ExpenseClaim::ACTIONS[$kind]) ? app(DocumentApproval::class) : $this->approvals;

        $claim = DB::transaction(function () use ($user, $employee, $kind, $amount, $reason, $account, $spentOn, $receipt) {
            $claim = ExpenseClaim::query()->create([
                'company_id' => CompanyContext::id(),
                'branch_id' => $employee->branch_id ?? CompanyContext::branchId(),
                'document_no' => $this->numbers->next('EXC'),
                'kind' => $kind,
                'employee_id' => $employee->id,
                'expense_account_id' => $account?->id,
                'amount' => $amount,
                'spent_on' => $spentOn?->toDateString(),
                'reason' => $reason,
                'status' => ExpenseClaim::SUBMITTED,
                'requested_by' => $user->id,
            ]);

            if ($receipt !== null) {
                try {
                    $this->attachments->store(
                        file: $receipt,
                        module: self::MODULE,
                        entity: ExpenseClaim::drillSourceType(),
                        entityId: (int) $claim->id,
                        maxBytes: AttachmentEngine::SLIP_MAX_BYTES,
                        only: AttachmentEngine::SLIP,
                    );
                } catch (AttachmentException $refused) {
                    throw ValidationException::withMessages(['receipt' => $refused->getMessage()]);
                }
            }

            return $claim;
        });

        // ⓘ সই চাওয়া — `null` কেবল কোম্পানির নিজের ছকের সীমার নিচে হলে, তখন সাথে সাথে অনুমোদিত
        $waits = $claim->kind === ExpenseClaim::ADVANCE
            ? $approvals->stopping($claim, module: 'hr', action: ExpenseClaim::ACTION_ADVANCE, amount: (string) $claim->amount)
            : $approvals->stopping($claim, module: 'hr', action: ExpenseClaim::ACTION_EXPENSE, amount: (string) $claim->amount);

        if ($waits === null) {
            $this->approve($claim);
        }

        return $claim->fresh();
    }

    /**
     * ⭐ যিনি চাইলেন তিনিই সই দিলেন — নিরীক্ষার খাতায় "একই মানুষ" দাগ (টাকার পরিকল্পনা, দায়িত্বের ছক; ৭ অক্টোবর ২০২৬;
     * [[AnOwnersOwnClaimIsMarkedTest]])।
     *
     * ⓘ সইয়ের ইঞ্জিন নিজের কাগজে সই কেবল মালিককে (সুপার অ্যাডমিন) দেয়। পরিকল্পনার নিয়ম: মালিক একাই সব করলে কাজ আটকায় না, কিন্তু
     * দাগ থাকে — পরিশোধের তিন-হাতের ([[PaymentService]] `three_hands_override`) একই ধাঁচ। সিদ্ধান্তের সারিতে দুই নাম আগেও ছিল,
     * কিন্তু আলাদা করে খোঁজা যেত না; এখন নিরীক্ষার পাতায় নিজের নামে একটা কাজ।
     */
    public function noteTheRequesterSigned(ExpenseClaim $claim, Approval $approval): void
    {
        $self = $approval->decisions()
            ->where('user_id', $claim->requested_by)
            ->where('decision', ApprovalDecision::APPROVED)
            ->exists();

        if (! $self) {
            return;
        }

        app(AuditEngine::class)->recordAction($claim, 'own_claim_signed', __('hr::claim.own_claim_signed', ['no' => $claim->document_no]));
    }

    /** ⭐ শেষ সই পড়ল — অনুমোদিত; অগ্রিম থেকে কাটা খাতায়, বাকিটুকুর খসড়া ভাউচার ক্যাশিয়ারের জন্য। দুবার খবর এলেও একবার। */
    public function approve(ExpenseClaim $claim): void
    {
        DB::transaction(function () use ($claim): void {
            $this->lockFresh($claim);

            if ($claim->status !== ExpenseClaim::SUBMITTED) {
                return;
            }

            $employee = $claim->employee;
            $claim->forceFill(['status' => ExpenseClaim::APPROVED, 'decided_at' => now()])->save();

            if ($claim->kind === ExpenseClaim::ADVANCE) {
                $this->cashVoucher($claim, $this->head(StandardChart::EMPLOYEE_ADVANCE)->id, (string) $claim->amount, true);

                return;
            }

            /*
             * ⭐ আগে খোলা অগ্রিম থেকে — খাতায় বসা জের পর্যন্ত।
             * ⛔ কর্মীর সারিতে তালা দিয়ে পড়া — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (HR ২; [[TwoClaimsTakeTheSameAdvanceTest]])। ⓘ তালা
             * কেবল দাবির সারিতে ছিল; একই কর্মীর দুইটা দাবি একসাথে সই হলে দুইজনেই একই জের পড়ে দুইজনেই কাটতেন, অগ্রিম ঋণাত্মক হত।
             */
            $this->advances->lock([(int) $employee->id]);
            $open = $this->advances->open($employee, Carbon::today());
            $fromAdvance = bccomp($open, '0', 2) > 0 ? (bccomp($open, (string) $claim->amount, 2) < 0 ? $open : (string) $claim->amount) : '0.00';

            if (bccomp($fromAdvance, '0', 2) > 0) {
                $journal = $this->vouchers->create([
                    'type' => Voucher::JOURNAL,
                    'branch_id' => $claim->branch_id,
                    'trx_date' => Carbon::today()->toDateString(),
                    'narration' => __('hr::claim.narration_from_advance', ['no' => $claim->document_no, 'who' => $employee->name()]),
                ], [
                    ['account_id' => $claim->expense_account_id, 'debit' => $fromAdvance, 'credit' => '0'],
                    ['account_id' => $this->head(StandardChart::EMPLOYEE_ADVANCE)->id, 'debit' => '0', 'credit' => $fromAdvance,
                        'party_type' => Employee::drillSourceType(), 'party_id' => (int) $employee->id],
                ]);
                $this->vouchers->post($journal);
                $claim->forceFill(['from_advance' => $fromAdvance, 'settle_voucher_id' => $journal->id])->save();
            }

            $cash = $claim->cashPart();

            if (bccomp($cash, '0', 2) > 0) {
                $this->cashVoucher($claim, (int) $claim->expense_account_id, $cash, false);

                return;
            }

            // ⓘ পুরোটা অগ্রিম থেকে মিটেছে — টাকা দেওয়ার কিছু নেই
            $claim->forceFill(['status' => ExpenseClaim::PAID, 'paid_at' => now()])->save();
        });
    }

    /** ⭐ সইয়ে "না" — ফেরানো; কোনো ভাউচার হয়নি */
    public function refuse(ExpenseClaim $claim): void
    {
        DB::transaction(function () use ($claim): void {
            $this->lockFresh($claim);

            if ($claim->status === ExpenseClaim::SUBMITTED) {
                $claim->forceFill(['status' => ExpenseClaim::REJECTED, 'decided_at' => now()])->save();
            }
        });
    }

    /**
     * ⭐ বাকি অগ্রিম নগদে ফেরত — খসড়া আদায়, ক্যাশিয়ার নিজের টিলে পাকা করেন (টাকার পরিকল্পনা দফা ১৩, ধাপ ৪: "বাকি থাকলে ফেরত বা
     * বেতন থেকে কাটা"; ৭ অক্টোবর ২০২৬; [[AnUnspentAdvanceComesBackInCashTest]])।
     *
     * ⓘ বেতন থেকে কাটা আগে থেকেই ([[PayrollService]], খোলা অগ্রিম পর্যন্ত)। নগদ ফেরতের পথ ছিল কেবল হাতে লেখা আদায় ভাউচার — কর্মীর
     * নাম আর ১১৩১ ঠিক বাছতে হত। এখন অগ্রিমের পাতা থেকে: Dr নগদ / Cr ১১৩১ কর্মীর নামে, খসড়া; নগদ আসে যিনি পাকা করেন তাঁর টিলে
     * ("নগদ কেবল নিজের টিলে")। অঙ্ক খোলা অগ্রিমের বেশি নয়, আর একজনের ফেরতের খসড়া একটাই — দুইটা খসড়া পাকা হলে অগ্রিম ঋণাত্মক হত।
     */
    public function takeBackAdvance(ExpenseClaim $claim, string $amount): Voucher
    {
        if ($claim->kind !== ExpenseClaim::ADVANCE || $claim->status !== ExpenseClaim::PAID) {
            throw ValidationException::withMessages(['amount' => __('hr::claim.return_only_paid_advance')]);
        }

        $amount = bcadd($amount, '0', 2);

        if (bccomp($amount, '0', 2) <= 0) {
            throw ValidationException::withMessages(['amount' => __('hr::claim.amount_bad')]);
        }

        $employee = $claim->employee;
        $advance = $this->head(StandardChart::EMPLOYEE_ADVANCE);

        $waiting = Voucher::query()
            ->where('status', DocumentStatus::DRAFT)
            ->where('type', Voucher::RECEIPT)
            ->whereHas('lines', fn ($q) => $q->where('account_id', $advance->id)
                ->where('party_type', Employee::drillSourceType())->where('party_id', $employee->id))
            ->value('document_no');

        if ($waiting !== null) {
            throw ValidationException::withMessages(['amount' => __('hr::claim.return_waiting', ['no' => $waiting])]);
        }

        $open = $this->advances->open($employee, Carbon::today());

        if (bccomp($amount, $open, 2) > 0) {
            throw ValidationException::withMessages(['amount' => __('hr::claim.return_over_open', ['open' => Money::format($open)])]);
        }

        $party = ['party_type' => Employee::drillSourceType(), 'party_id' => (int) $employee->id];

        return $this->vouchers->create([
            'type' => Voucher::RECEIPT,
            'branch_id' => $claim->branch_id,
            'trx_date' => Carbon::today()->toDateString(),
            'narration' => __('hr::claim.narration_return', ['no' => $claim->document_no, 'who' => $employee->name()]),
            ...$party,
        ], [
            ['account_id' => (int) app(CashTillService::class)->ensurePrimaryTill()->account_id, 'debit' => $amount, 'credit' => '0'],
            ['account_id' => (int) $advance->id, 'debit' => '0', 'credit' => $amount, ...$party],
        ]);
    }

    /** কর্মীর খোলা অগ্রিম — পর্দা আর ফোনের জন্য */
    public function openAdvance(Employee $employee): string
    {
        return $this->advances->open($employee, Carbon::today());
    }

    /**
     * ক্যাশিয়ারের খসড়া — Dr (খরচ বা কর্মীর অগ্রিম) / Cr নগদ, দাবির বিপরীতে, কর্মীর নামে। ⓘ নগদের খাত প্রধান বাক্স; ক্যাশিয়ার
     * পাকা করার আগে নিজের টিল বসান (খসড়া বদলানো যায়), নইলে "নগদ কেবল নিজের টিল থেকে" পাকা করতে দেয় না।
     */
    private function cashVoucher(ExpenseClaim $claim, int $debitAccount, string $amount, bool $named): void
    {
        $party = ['party_type' => Employee::drillSourceType(), 'party_id' => (int) $claim->employee_id];

        $voucher = $this->vouchers->create([
            'type' => Voucher::PAYMENT,
            'branch_id' => $claim->branch_id,
            'trx_date' => Carbon::today()->toDateString(),
            'narration' => __('hr::claim.narration_'.$claim->kind, ['no' => $claim->document_no, 'who' => $claim->employee?->name()]),
            'against_type' => ExpenseClaim::drillSourceType(),
            'against_id' => $claim->id,
            ...$party,
        ], [
            ['account_id' => $debitAccount, 'debit' => $amount, 'credit' => '0', ...($named ? $party : [])],
            ['account_id' => (int) app(CashTillService::class)->ensurePrimaryTill()->account_id, 'debit' => '0', 'credit' => $amount],
        ]);

        $claim->forceFill(['payment_voucher_id' => $voucher->id])->save();
    }

    private function head(string $code): Account
    {
        $account = StandardChart::find($code);

        if ($account === null) {
            app(StandardChart::class)->install();
            $account = StandardChart::find($code);
        }

        if ($account === null) {
            throw ValidationException::withMessages(['amount' => __('hr::claim.head_missing', ['code' => $code])]);
        }

        return $account;
    }
}

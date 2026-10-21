<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\HeldWagePayment;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Support\BranchReach;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

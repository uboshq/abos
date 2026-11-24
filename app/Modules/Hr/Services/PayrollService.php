<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Concerns\ReadsTheRowUnderLock;
use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\Approval\PaperChangedWhileChecking;
use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\Money;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\MaternityDelivery;
use App\Modules\Hr\Models\HeldWagePayment;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayslipLine;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Support\AdvanceBalance;
use App\Modules\Hr\Support\BranchReach;
use App\Modules\Hr\Support\WorkCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * এক মাসের বেতন — তৈরি, নিশ্চিতকরণ, বাতিল।
 *
 * ── প্ল্যানের মাপকাঠি ────────────────────────────────────────────────
 * "বেতন খাতায় বসে, ব্যাংক ফাইল বের হয়।" তাই এই সেবার দুইটা কাজ:
 * প্রতিটা কর্মীর অঙ্ক শিটে বসানো, আর নিশ্চিত করার দিনে সেটা হিসাবের
 * বইয়ে তোলা।
 *
 * ── কেন অঙ্কগুলো কপি হয়ে বসে ─────────────────────────────────────────
 * শিট বানানোর সময় কাঠামো থেকে হিসাব হয়, তারপর অঙ্কটা শিটেই লেখা থাকে।
 * প্রতিবার নতুন করে হিসাব করলে কাঠামো শুধরানোর দিনে গত মাসের শিটও
 * বদলে যেত — অথচ ব্যাংকে অন্য টাকা গেছে।
 */
final class PayrollService
{
    use ReadsTheRowUnderLock;

    /** ⭐ উৎসব ভাতা — বছরে বড়জোর দুইটা, মূলের শতাংশ (a6, ২১ অক্টোবর ২০২৬; [[buildBonus()]]) */
    public const BONUSES_A_YEAR = 2;

    public const BONUS_PERCENT = 'hr.bonus.percent_of_basic';

    /** ⓘ বেতনের স্লিপের সারির কোড */
    public const BONUS_CODE = 'BONUS';

    public function __construct(
        private readonly SalaryStructureService $salaries,
        private readonly AttendanceService $attendance,
        private readonly SettingsService $settings,
        private readonly PostingEngine $posting,
        private readonly NumberSeriesEngine $numbers,
        private readonly DocumentApproval $approvals,
        private readonly ProvidentFund $fund,
        private readonly OvertimeService $overtime,
        private readonly SalaryTax $tax,
        private readonly Gratuity $gratuity,
    ) {}

    /**
     * একটা মাসের খসড়া রান বানানো।
     *
     * @param  string  $month  মাসের যেকোনো দিন — ভেতরে প্রথম দিনে নামানো হয়
     */
    public function build(string $month, ?string $trxDate = null): PayrollRun
    {
        $monthStart = Carbon::parse($month)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $trx = $trxDate === null ? $monthEnd->copy() : Carbon::parse($trxDate);

        /*
         * ⛔ বেতনের দিন মাসের আগে নয় — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ ([[APayrollMonthAndDateStayInsideTheirLimitsTest]])।
         * ⓘ আগে অক্টোবরের রান ১ সেপ্টেম্বর তারিখে নেওয়া হত — অক্টোবরের বেতন-খরচ সেপ্টেম্বরের খাতায়, বন্ধ হয়ে যাওয়া তুলনার মাসে।
         */
        if ($trx->copy()->startOfDay()->lt($monthStart)) {
            throw ValidationException::withMessages([
                'trx_date' => __('hr::validation.payroll_date_before_month', ['month' => $monthStart->format('M Y')]),
            ]);
        }

        $this->assertNoLiveRun($monthStart, PayrollRun::SALARY);

        /*
         * পুরো কোম্পানির কর্মী, শাখা ধরে ভাগ নয়।
         *
         * ── কেন শাখা এখানে ছাঁকনি নয় ────────────────────────────────
         * রানে শাখা লেখা থাকে, কিন্তু সেটা কেবল "কোথায় বসে করা হয়েছে"
         * তার ছাপ — বাকি প্রতিটা ডকুমেন্টের মতোই।
         *
         * ছাঁকনি বানালে দুইটা বিপদ একসাথে আসত: যে কর্মীর কোনো শাখা
         * বসানো নেই তিনি কোনো রানেই পড়তেন না, আর দুই শাখা আলাদা রান
         * করলে শাখাহীন কর্মীদের বেতন দুইবার হত।
         */
        $employees = Employee::query()
            ->onPayrollFor($monthEnd)
            ->orderBy('code')
            ->get();

        if ($employees->isEmpty()) {
            throw ValidationException::withMessages([
                'month' => __('hr::validation.nobody_on_payroll'),
            ]);
        }

        return DB::transaction(function () use ($employees, $monthStart, $monthEnd, $trx) {
            /*
             * ⛔ তালা দিয়ে আবার — চূড়ান্ত অডিট ⛔১৮, ৩০ সেপ্টেম্বর ২০২৬
             * ([[TwoPayrollRunsForTheSameMonthTest]])। ⓘ উপরের যাচাই তালা ছাড়া, লেনদেনের আগে;
             * দুইজন একসাথে চাপলে দুইজনেই "নেই" দেখতেন আর একই মাসের দুইটা রান বসত। কোম্পানির
             * সারিতে তালা দিলে দ্বিতীয়জন প্রথমজনের কমিটের পরে দেখেন, আর ফিরে যান।
             */
            Company::query()->whereKey(CompanyContext::id())->lockForUpdate()->first();
            $this->assertNoLiveRun($monthStart, PayrollRun::SALARY);

            $run = PayrollRun::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => CompanyContext::branchId(),
                'financial_year_id' => $this->financialYear($trx)?->id,
                'document_no' => $this->numbers->next('PRL'),
                'run_type' => PayrollRun::SALARY,
                'month' => $monthStart->toDateString(),
                'trx_date' => $trx->toDateString(),
                'status' => DocumentStatus::DRAFT,
                'created_by' => auth()->id(),
            ]);

            foreach ($employees as $employee) {
                $this->buildPayslip($run, $employee, $monthEnd);
            }

            return $this->recount($run);
        });
    }

    /**
     * খসড়াটা আবার বানানো — কাঠামো শুধরানোর পর।
     *
     * পুরনো শিটগুলো মুছে নতুন করে বসে। নিশ্চিত করা রানে এটা চলে না:
     * তখন অঙ্কগুলো খাতায় বসে গেছে, আর খাতা বদলাতে হলে বিপরীত এন্ট্রি
     * লাগে — নীরব পুনর্গণনা নয়।
     */
    public function rebuild(PayrollRun $run): PayrollRun
    {
        $this->assertDraft($run);

        return DB::transaction(function () use ($run) {
            /*
             * ⛔ সারিতে তালা দিয়ে তাজা অবস্থা — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (HR ⚠️৫; [[ARebuildCannotEraseAConfirmedRunTest]])।
             * ⓘ পুরনো কপিতে "খসড়া" দেখে আবার-বানানো চললে, ততক্ষণে আরেকজনের নিশ্চিত করা রানের বেতনশিট মুছে নতুন বসত — খাতায় বসা
             * অঙ্ক আর শিট আলাদা হয়ে যেত। মুছবে কি না, সেটা তাজা অবস্থা বলে।
             */
            $this->lockFresh($run);
            $this->assertDraft($run);

            $monthEnd = $run->month->copy()->endOfMonth();

            /*
             * ⛔ id ধরে পাতা, অফসেটে নয় — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ ([[ARebuildClearsEveryOldPayslipTest]])।
             * ⓘ `each()` হাজারে হাজারে অফসেট ধরে পড়ে, অথচ পড়তে পড়তেই মোছে: প্রথম হাজার মোছার পর দ্বিতীয় পাতা বাকিগুলোর ১,০০০
             * নম্বর থেকে খোঁজে আর কিছু পায় না। হাজারের বেশি কর্মী থাকলে পুরনো শিট টিকে থাকত, নতুনগুলোর পাশে — মোট, খাতা আর ব্যাংকে
             * তাঁদের বেতন দুইবার। `lazyById()` শেষ পড়া id-র পর থেকে খোঁজে, তাই মুছলেও কিছু বাদ পড়ে না।
             */
            $run->payslips()->lazyById(500)->each(function (Payslip $slip) {
                $slip->lines()->delete();
                $slip->forceDelete();
            });

            // ⭐ উৎসব ভাতার রান নিজের নিয়মে — রানের দিনে এক বছরের চাকরি, মূলের শতাংশ ([[buildBonus()]])
            if ($run->isBonus()) {
                $on = $run->trx_date->copy()->startOfDay();

                foreach ($this->bonusEligible($on) as $employee) {
                    $this->buildBonusPayslip($run, $employee, $on);
                }

                return $this->recount($run);
            }

            // build()-এর হুবহু একই পরিধি — নাহলে "আবার বানান" চাপলে
            // তালিকা বদলে যেত, আর কেউ নীরবে বাদ পড়ত
            $employees = Employee::query()
                ->onPayrollFor($monthEnd)
                ->orderBy('code')
                ->get();

            foreach ($employees as $employee) {
                $this->buildPayslip($run, $employee, $monthEnd);
            }

            return $this->recount($run);
        });
    }

    /**
     * ⭐ উৎসব ভাতার রান — আলাদা ধরন, নিজের সই, নিজের খরচের খাত (শ্রম বিধিমালা ২০১৫ বিধি ১১১; মালিকের অনুমোদিত আইনি বেতনের পরিকল্পনা,
     * a6, ২১ অক্টোবর ২০২৬)।
     *
     * ── ⭐ নিয়ম ──────────────────────────────────────────────────────────
     *  · কে পান — রানের দিনে যাঁর চাকরি অন্তত এক বছরের (যোগদান ≤ রানের দিন − ১ বছর), আর সেদিন যিনি চাকরিতে আছেন।
     *  · কত — কাঠামোর মূল (রানের দিনে) × সেটিংয়ের শতাংশ (ডিফল্ট ১০০% — এক মাসের মূল; কমানো যায়, বাড়ানো নয়), পয়সায় গোল।
     *  · বছরে বড়জোর দুইটা — কোম্পানি জুড়ে, বাতিল ছাড়া, পঞ্জিকা-বছর ধরে; তৃতীয়টা ফেরে।
     *  · "এক মাসে একটা জীবিত রান" ধরন ধরে — একই মাসে বেতনের রান আর উৎসব ভাতার রান দুটোই চলে, দুইটা ভাতার রান নয়।
     *
     * ⓘ বাকিটা বেতনের রানের হুবহু: খসড়া → সই (একই `hr`·`payroll` ছক, নিট ধরে) → নিশ্চিত, খাতায় ৫২২৬ ডেবিট আর প্রদেয় বেতন (২১৩০)
     * ক্রেডিট, কর্মীর শাখায় ([[ledgerLines()]]); বাতিলে উল্টো দাখিলা। ⚠️ ভাতার উপর উৎসে কর এখানে কাটা হয় না — খোলা প্রশ্ন।
     *
     * @param  string  $date  রানের দিন — যোগ্যতা এই দিনে মাপা হয়, আর খরচও এই দিনের
     */
    public function buildBonus(string $date, ?string $narration = null): PayrollRun
    {
        $on = Carbon::parse($date)->startOfDay();
        $monthStart = $on->copy()->startOfMonth();

        $this->assertNoLiveRun($monthStart, PayrollRun::BONUS);
        $this->assertBonusRoom($on);

        if ($this->accountByCode(StandardChart::FESTIVAL_BONUS_EXPENSE) === null) {
            throw ValidationException::withMessages(['account' => __('hr::validation.bonus_account_missing')]);
        }

        $employees = $this->bonusEligible($on);

        if ($employees->isEmpty()) {
            throw ValidationException::withMessages(['bonus_date' => __('hr::validation.nobody_due_a_bonus')]);
        }

        return DB::transaction(function () use ($employees, $on, $monthStart, $narration) {
            // ⛔ তালা দিয়ে আবার — বেতনের রানের একই কারণে ([[build()]]); দুইজন একসাথে চাপলে বছরের তৃতীয় ভাতাও বসত
            Company::query()->whereKey(CompanyContext::id())->lockForUpdate()->first();
            $this->assertNoLiveRun($monthStart, PayrollRun::BONUS);
            $this->assertBonusRoom($on);

            $run = PayrollRun::create([
                'company_id' => CompanyContext::id(),
                'branch_id' => CompanyContext::branchId(),
                'financial_year_id' => $this->financialYear($on)?->id,
                'document_no' => $this->numbers->next('PRL'),
                'run_type' => PayrollRun::BONUS,
                'month' => $monthStart->toDateString(),
                'trx_date' => $on->toDateString(),
                'status' => DocumentStatus::DRAFT,
                'narration' => $narration,
                'created_by' => auth()->id(),
            ]);

            foreach ($employees as $employee) {
                $this->buildBonusPayslip($run, $employee, $on);
            }

            $run = $this->recount($run);

            // ⓘ সবার মূল শূন্য — খালি রান রাখা হয় না (লেনদেন ফেরে)
            if ($run->employee_count === 0) {
                throw ValidationException::withMessages(['bonus_date' => __('hr::validation.nobody_due_a_bonus')]);
            }

            return $run;
        });
    }

    /**
     * নিশ্চিত করা — এখানেই বেতন খাতায় বসে।
     *
     * ── কোন দিকে কী বসে ─────────────────────────────────────────────
     * প্রতিটা আয়ের খাত ডেবিট (খরচ বাড়ল), প্রতিটা কর্তন ক্রেডিট (দায়
     * বাড়ল বা অগ্রিম কমল), আর হাতে যা থাকে সেটা "প্রদেয় বেতন"-এ ক্রেডিট।
     *
     * টাকা তখনো যায়নি — যাবে ভাউচারে, প্রদেয় বেতন ডেবিট করে। দুইটা
     * ধাপ আলাদা, কারণ বেতন হিসাব করা আর বেতন দেওয়া একই দিনে নাও হতে
     * পারে, আর হলেও একই কাজ নয়।
     */
    public function confirm(PayrollRun $run): PayrollRun
    {
        $this->assertDraft($run);

        $run->load('payslips.lines');

        if ($run->payslips->isEmpty()) {
            throw ValidationException::withMessages([
                'status' => __('hr::validation.nothing_to_confirm'),
            ]);
        }

        /*
         * সই আগে, খতিয়ান পরে।
         *
         * অঙ্কটা `net_total` — মোট বেতন নয়, হাতে যা যাবে সেটা। ছকের
         * সীমাটা মানুষ ওই সংখ্যাটা ধরেই ভাবেন ("দুই লাখের উপরে হলে
         * আমাকে জিজ্ঞেস কোরো"), আর কর্তনের আগের সংখ্যাটা সবসময় বড় বলে
         * সীমাটা নীরবে কড়া হয়ে যেত।
         *
         * ⛔ সই যে অঙ্কে দেখা হল, তুলনাও সেই অঙ্কে — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬
         * ([[TheSignatureCoversTheNetThatIsPostedTest]])। ⓘ আগে তুলনার অঙ্কটা পড়া হত তালার পরে, তাজা সারি থেকে: সই দেখা হল
         * ১,৯৯,০০০-এ, এর মধ্যে আরেক ট্যাবে "আবার বানান" net তুলল ২,০৫,০০০-এ, আর তালার পরে সেটাই "সই হওয়া" অঙ্ক ধরে সই
         * ছাড়া খাতায় বসত। এখন সই-দেখা অঙ্কটাই ধরে রাখা হয় — তাজা net তার বেশি হলে নিচের যাচাই থামায়, আর নতুন অঙ্কে আবার সই দেখা হয়।
         */
        $signedNet = (string) $run->net_total;

        try {
            $print = $this->approvals->assertClear(
                document: $run,
                module: 'hr',
                action: 'payroll',
                field: 'status',
                amount: $signedNet,
                reason: $run->narration,
            );
        } catch (PaperChangedWhileChecking) {
            // ⓘ দেখার মাঝেই আবার-বানানো — নতুন অঙ্কে শুরু থেকে
            return $this->confirm($run->fresh());
        }

        $posted = DB::transaction(function () use ($run, $signedNet, $print) {
            /*
             * ⛔ সারিতে তালা দিয়ে তাজা অবস্থা — ১ অক্টোবর ২০২৬ ([[APayrollWasCancelledAfterItWasConfirmedTest]])।
             * ⓘ পুরনো কপি থেকে "নিশ্চিত" চাপলে বাতিল হয়ে যাওয়া রানও খাতায় বসত।
             */
            $this->lockFresh($run);
            $this->assertDraft($run);
            /*
             * ⛔ যাচাই আর তালার মাঝে কাগজ বদলালে (শিট বা সারি — net একই থাকলেও) কিছুই খাতায় নয় ([[DocumentApproval::isUnchangedSince()]],
             * ১৩ অক্টোবর ২০২৬); ec-এর net-এর নিয়মের মতোই নতুন কাগজে শুরু থেকে আবার — সই লাগলে নিচের আবার-ডাকা অনুরোধ বসিয়ে থামে।
             */
            if (! $this->approvals->isUnchangedSince($run, $print)) {
                return null;
            }

            $this->capAdvancesNow($run);

            /*
             * ⛔ অগ্রিম কমায় হাতে-যাওয়া বেতন বাড়লে সই পুরনো অঙ্কের — cloud/people-fixes-এর রিভিউ, ১০ অক্টোবর ২০২৬। ⓘ সই হয়েছিল
             * খসড়ার net-এ (উপরে), আর অগ্রিম মেপে কিস্তি কমালে net বাড়ে: ১,৯৯,০০০-এ সই, বসত ২,০২,০০০ — সীমা পার, সই ছাড়া।
             * ⭐ তাই বাড়লে কেবল ঠিক করা খসড়াটা থাকে (খাতায় কিছু নয়), আর নিশ্চিত আবার নতুন অঙ্কে সই দেখে — লেনদেনের বাইরে, যাতে
             * নতুন সইয়ের অনুরোধটা ফিরে না যায়।
             */
            if (bccomp((string) $run->net_total, $signedNet, 4) > 0) {
                return null;
            }

            $lines = $this->ledgerLines($run);

            $this->posting->post(
                sourceType: PayrollRun::SOURCE_TYPE,
                sourceId: $run->id,
                trxDate: $run->trx_date,
                lines: $lines,
                documentNo: $run->document_no,
                branchId: $run->branch_id,
            );

            $run->forceFill(['status' => DocumentStatus::CONFIRMED])->save();

            /*
             * ⭐ মাসের বেতন নিশ্চিত হলে সেই মাসের গ্র্যাচুইটি সঞ্চিতিও — একই লেনদেনে; সুইচ চালু থাকলে, আর মাসটা আগে বসানো না থাকলে
             * (fe, ২১ অক্টোবর ২০২৬, দফা ৮; [[Gratuity::accrue()]])। ⓘ উৎসব ভাতার রানে নয়।
             */
            if (! $run->isBonus() && $this->gratuity->enabled()) {
                $this->gratuity->accrue($run->month->copy());
            }

            return $run->fresh();
        });

        // ⓘ net বেড়েছিল — নতুন অঙ্কে আবার, শুরু থেকে (সই না থাকলে উপরের assertClear অনুরোধ বসিয়ে থামে); দ্বিতীয়বার কিস্তি আর কমে না
        return $posted ?? $this->confirm($run->fresh());
    }

    /**
     * বাতিল — খাতায় বসে গিয়ে থাকলে বিপরীত এন্ট্রি সহ।
     *
     * পুরনো এন্ট্রি মোছা হয় না। মুছলে ট্রায়াল ব্যালেন্স মিললেও "কী
     * হয়েছিল" প্রশ্নের উত্তর হারাত, আর নিরীক্ষায় একটা ফাঁক থাকত।
     */
    public function cancel(PayrollRun $run, string $reason): PayrollRun
    {
        $this->assertNotCancelled($run);

        return DB::transaction(function () use ($run, $reason) {
            /*
             * ⛔ সারিতে তালা দিয়ে তাজা অবস্থা — ১ অক্টোবর ২০২৬ ([[APayrollWasCancelledAfterItWasConfirmedTest]])।
             * ⓘ হাতের কপিতে "খসড়া" থাকলে বিপরীত দাখিলা হত না, অথচ ততক্ষণে আরেকজন নিশ্চিত করে বেতন খাতায়
             * বসিয়েছেন — রান বাতিল, খরচ রয়ে যেত। উল্টাবে কি না, সেটা তাজা অবস্থা বলে।
             */
            $this->lockFresh($run);
            $this->assertNotCancelled($run);

            if ($run->status === DocumentStatus::CONFIRMED) {
                $this->assertSalaryNotPaidOut($run);

                /*
                 * ⛔ উল্টো দাখিলা আসলটার আগে নয় — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ ([[AReversalNeverLandsBeforeThePayrollTest]])।
                 * ⓘ অক্টোবরের রান ৩১ অক্টোবর তারিখে, নিশ্চিত ১৪ তারিখে, বাতিল ১৫ তারিখে — উল্টোটা বসত ১৫ অক্টোবর, আর ১৫ থেকে ৩০
                 * অক্টোবর খাতায় বেতন-খরচ ঋণাত্মক দেখাত। রানের দিন সামনে হলে উল্টোটাও সেদিনই; নাহলে আগের মতো আজ।
                 */
                $this->posting->reverse(
                    sourceType: PayrollRun::SOURCE_TYPE,
                    sourceId: $run->id,
                    reversalDate: $run->trx_date->greaterThan(Carbon::today()) ? $run->trx_date->copy() : now(),
                    reason: $reason,
                );
            }

            $run->forceFill([
                'status' => DocumentStatus::CANCELLED,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ])->save();

            return $run->fresh();
        });
    }

    /**
     * ব্যাংকে পাঠানোর ফাইল — যাদের বেতন ব্যাংকে যায় তাদের সারি।
     *
     * ── কেন CSV, আর কেন শুধু ব্যাংকের সারি ──────────────────────────
     * প্রতিটা ব্যাংকের নিজের ছক আছে, কিন্তু সবাই একই চারটা জিনিস চায়:
     * হিসাব নম্বর, নাম, অঙ্ক, আর শাখা চেনানোর নম্বর। CSV সব ব্যাংকের
     * পোর্টালেই তোলা যায়, আর দরকারে এক্সেলে খুলে ঠিক করা যায়।
     *
     * নগদ ও MFS-এর সারি বাদ: ব্যাংক সেগুলো চেনে না, আর ফাইলে থাকলে
     * পুরো ফাইলটাই প্রত্যাখ্যাত হত।
     *
     * @return array{name: string, content: string, rows: int}
     */
    /**
     * ⛔ বেতনশিটের "আটকে" পথ — কর্মীর সারিতে পথ ব্যাংক বা MFS, অথচ সই-পড়া নম্বর নেই (fe, ২১ অক্টোবর ২০২৬; a6; [[PaymentChanges]])।
     *
     * ⓘ নিয়ম, সবচেয়ে নিরাপদ আর এক রকম: বেতন যায় কেবল সই-পড়া তথ্যে। ঠিকানা বদল অপেক্ষায় থাকলে কর্মীর সারিতে আগের, সই-পড়া
     * তথ্যই থাকে — বেতন সেখানে যায় (আগের পথ)। আগের তথ্য না থাকলে (নতুন কর্মীর প্রথম নম্বর, বা খালি থেকে বসানো) বেতন
     * **আটকে**: নগদেও নয় (নগদে দিলে সই ছাড়া পথ খুলত), ব্যাংক ফাইলে নাম থাকে না, রানের পাতা আর ফাইলের সঙ্গী-তালিকায় কারণ।
     *
     * ⛔ "আটকে" মানে কেবল "এই ব্যাংক ফাইলে নেই" — পাওনা নয় এমন নয় (fe, ২১ অক্টোবর ২০২৬; শ্রম আইন ধারা ১২৩: মজুরিকাল শেষের সাত
     * কর্মদিবসের মধ্যে মজুরি)। রান পোস্ট হলে স্লিপের পুরো নিট প্রদেয় বেতনে বসে, আটকে হোক বা না হোক (IAS 19 accrual; [[ledgerLines()]]
     * পথ দেখে না)। পোস্ট-হওয়া রান বদলায় না। সই পড়লে আটকে থাকা নিট **পরের ব্যাংক ফাইলে "বকেয়া মজুরি"** হয়ে একবারই যায়
     * ([[arrearsFor()]], [[HeldWagePayment]]) — আবার বানানো লাগে না।
     */
    public const HELD = 'held';

    /** @return string|null কারণের ভাষা-চাবি; `null` = আটকে নয় */
    public static function heldReason(Employee $employee): ?string
    {
        $method = (string) $employee->payment_method;
        $missing = ($method === 'bank' && blank($employee->bank_account_no)) || ($method === 'mfs' && blank($employee->mfs_number));

        if (! $missing) {
            return null;
        }

        return app(PaymentChanges::class)->pendingFor($employee) !== null ? 'hr::payroll.held_waiting' : 'hr::payroll.held_no_account';
    }

    /** ⓘ মজুরিকাল শেষের পরে কত কর্মদিবস — শ্রম আইন ২০০৬ ধারা ১২৩ */
    public const WAGE_DAYS = 7;

    /**
     * ⛔ মজুরি দেওয়ার শেষ দিন — মজুরিকাল (রানের মাস) শেষের পরে সাত কর্মদিবস, শ্রম আইন ২০০৬ ধারা ১২৩।
     *
     * ⭐ কর্মদিবস গোনা ছুটির পঞ্জিকায় — কর্মীর নিজের শাখার সাপ্তাহিক ছুটি (নইলে কোম্পানির), আর তালিকার সরকারি ছুটি (সব শাখার বা তাঁর
     * শাখার) বাদ ([[WorkCalendar::addWorkingDays()]]; মেশানোর পর, a6, ২১ অক্টোবর ২০২৬; [[TheWageDeadlineCountsTheWorkCalendarTest]])।
     * ⓘ আগে কেবল শুক্রবার বাদ যেত — ঈদের সপ্তাহ বা শনিবারে বন্ধ দোকানের কর্মীর শেষ দিন আইনের আগেই "পেরিয়ে গেছে" (লাল) দেখাত।
     * ⓘ কর্মী না দিলে (বা শাখাহীন কর্মী) কোম্পানির পঞ্জিকা — সাপ্তাহিক ছুটি কোম্পানির, তালিকার কেবল সব-শাখার ছুটি।
     */
    public static function wageDeadline(PayrollRun $run, ?Employee $employee = null, ?WorkCalendar $calendar = null): Carbon
    {
        return ($calendar ?? app(WorkCalendar::class))->addWorkingDays(
            $run->month->copy()->endOfMonth(),
            self::WAGE_DAYS,
            (int) $run->company_id,
            $employee?->branch_id === null ? null : (int) $employee->branch_id,
        );
    }

    /**
     * ⭐ ব্যাংক ফাইলের সঙ্গী-তালিকা — কারা বাদ, কেন, আর আইনের শেষ দিন; পেরোলে লাল (fe, ২১ অক্টোবর ২০২৬)। ⓘ রানের পাতা আর
     * `bank-file/left-out` ফাইল দুইটাই এটা পড়ে।
     *
     * @return list<array{code: string, name: string, reason: string, deadline: string, late: bool, paid: bool}>
     */
    public function leftOutOfTheBankFile(PayrollRun $run, ?User $user = null): array
    {
        $slips = ($user === null ? $run->payslips()->getQuery() : app(BranchReach::class)->throughEmployee($run->payslips()->getQuery(), $user))
            ->with('employee')
            ->where('payment_method', self::HELD)
            ->get();

        // ⓘ শেষ দিন কর্মী ধরে — শাখার সাপ্তাহিক ছুটি আলাদা হতে পারে ([[wageDeadline()]]); এক পঞ্জিকা, ছুটির তালিকা একবার পড়া
        $calendar = app(WorkCalendar::class);
        $paid = HeldWagePayment::query()->whereIn('payslip_id', $slips->modelKeys())->with(['paidIn', 'voucher'])->get()->keyBy('payslip_id');
        // ⭐ বকেয়া যে রানের ফাইলে গেল সেটা বাতিল — "ব্যাংকে পাঠানো — নিশ্চিতের অপেক্ষায়" (fe-র সিদ্ধান্ত ২; [[HeldWages::awaitingBank()]])
        $carriers = PayrollRun::acrossBranches()->whereKey($paid->pluck('paid_in_run_id')->filter()->all())->get(['id', 'status', 'document_no'])->keyBy('id');

        return $slips->map(function (Payslip $slip) use ($run, $calendar, $paid, $carriers): array {
            $employee = $slip->employee;
            $deadline = self::wageDeadline($run, $employee, $calendar);
            $payment = $paid->get($slip->id);
            $reason = $employee === null ? null : self::heldReason($employee);
            $carrier = $payment?->paid_in_run_id === null ? null : $carriers->get($payment->paid_in_run_id);

            return [
                'code' => (string) $employee?->code,
                'name' => (string) $employee?->name(),
                'reason' => $payment !== null
                    ? ($payment->paid_in_run_id === null
                        // ⓘ বকেয়ার নিজের কাগজে, যেকোনো দিন ([[HeldWages::pay()]])
                        ? (string) __('hr::payroll.held_paid_voucher', ['no' => (string) $payment->voucher?->document_no])
                        : ($carrier?->status === DocumentStatus::CANCELLED
                            // ⛔ বাতিল রানের ফাইলে গিয়েছিল — ব্যাংক হয়তো দিয়েছে; নিজে নিজে ছাড়া নয়
                            ? (string) __('hr::payroll.bank_awaiting_on', ['no' => (string) $carrier->document_no])
                            : (string) __('hr::payroll.held_paid_later', ['no' => (string) ($carrier?->document_no ?? $payment->paidIn?->document_no)])))
                    // ⓘ সই পড়ে গেছে — পরের ব্যাংক ফাইলে "বকেয়া মজুরি" হয়ে যাবে
                    : (string) __($reason ?? 'hr::payroll.held_next_file'),
                'deadline' => $deadline->toDateString(),
                'late' => $payment === null && Carbon::today()->greaterThan($deadline),
                'paid' => $payment !== null,
            ];
        })->values()->all();
    }

    /**
     * ⭐ আটকে থাকা মজুরি, এখনো যা দেওয়া হয়নি — সব নিশ্চিত রান জুড়ে, HR-এর ড্যাশবোর্ডের তালিকা (fe, ২১ অক্টোবর ২০২৬)।
     *
     * ⭐ সাথে বাতিল রানের ব্যাংক ফাইলে যাওয়া বকেয়া — `state` = `bank_awaiting`, "ব্যাংকে পাঠানো — নিশ্চিতের অপেক্ষায়" (fe-র সিদ্ধান্ত ২,
     * ২১ অক্টোবর ২০২৬; [[HeldWages::awaitingBank()]]); বাকিগুলো `owed`। ⓘ ব্যাংক হয়তো দিয়েছে, তাই "শেষ দিন পেরিয়েছে" লাল নয়।
     *
     * @return Collection<int, array{employee: string, channel: ?string, run: string, deadline: string, late: bool, state: string}>
     */
    public function heldWagesOwed(?User $user = null): Collection
    {
        $query = Payslip::query()
            ->where('payment_method', self::HELD)
            ->whereDoesntHave('heldPayment')
            ->whereHas('run', fn ($q) => $q->where('status', DocumentStatus::CONFIRMED))
            ->with(['employee', 'run']);

        $query = $user === null ? $query : app(BranchReach::class)->throughEmployee($query, $user);

        $calendar = app(WorkCalendar::class);

        $owed = $query->orderBy('id')->get()->map(function (Payslip $slip) use ($calendar): array {
            $deadline = self::wageDeadline($slip->run, $slip->employee, $calendar);

            return [
                'employee' => (string) $slip->employee?->name(),
                // ⓘ সই-পড়া পথ — ব্যাংক বা MFS; না থাকলে `null` (হিসাব সইয়ের অপেক্ষায়) — fe, ২১ অক্টোবর ২০২৬
                'channel' => $slip->employee === null ? null : HeldWages::channelOf($slip->employee),
                'run' => (string) $slip->run?->document_no,
                'deadline' => $deadline->toDateString(),
                'late' => Carbon::today()->greaterThan($deadline),
                'state' => 'owed',
            ];
        });

        $awaiting = app(HeldWages::class)->awaitingBank($user)->map(fn (array $line): array => [
            'employee' => (string) $line['employee']?->name(),
            'channel' => (string) $line['payment']->channel,
            'run' => (string) $line['held_run']?->document_no,
            'deadline' => $line['held_run'] === null ? Carbon::today()->toDateString()
                : self::wageDeadline($line['held_run'], $line['employee'], $calendar)->toDateString(),
            'late' => false,
            'state' => 'bank_awaiting',
        ]);

        return $owed->concat($awaiting)->values();
    }

    /**
     * ⭐ বকেয়া মজুরি — আগের নিশ্চিত রানের আটকে থাকা স্লিপ, যার হিসাব এখন সই-পড়া, এই রানের ব্যাংক ফাইলে (fe, ২১ অক্টোবর ২০২৬)।
     *
     * ⛔ একবারই: প্রথম যে পরের ফাইলে ঢোকে, সেটা [[HeldWagePayment]]-এ লেখা হয় (স্লিপ প্রতি একটা সারি, unique) — একই রানের ফাইল আবার
     * নামালে আবার আসে (একই টাকা, একই ফাইল), কিন্তু তার পরের রানের ফাইলে আর নয়। ⓘ আগের রান ছোঁয়া হয় না।
     *
     * @return list<array{0: Payslip, 1: string}> স্লিপ আর ফাইলের রেফারেন্স
     */
    private function arrearsFor(PayrollRun $run, ?User $user): array
    {
        $query = Payslip::query()
            ->where('payment_method', self::HELD)
            ->where('payroll_run_id', '!=', $run->id)
            ->where('net', '>', 0)
            ->whereHas('run', fn ($q) => $q->where('status', DocumentStatus::CONFIRMED)->where('month', '<', $run->month))
            ->with(['employee', 'run']);

        $query = $user === null ? $query : app(BranchReach::class)->throughEmployee($query, $user);
        $out = [];

        foreach ($query->orderBy('id')->get() as $slip) {
            $employee = $slip->employee;

            if ($employee === null || $employee->payment_method !== 'bank' || self::heldReason($employee) !== null) {
                continue;
            }

            HeldWagePayment::query()->insertOrIgnore([[
                'public_id' => (string) \Illuminate\Support\Str::uuid7(),
                'company_id' => (int) $slip->company_id,
                'payslip_id' => (int) $slip->id,
                'payroll_run_id' => (int) $slip->payroll_run_id,
                'paid_in_run_id' => (int) $run->id,
                'amount' => (string) $slip->net,
                'created_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]]);

            // ⓘ তালা নয়, unique — যে রান আগে লিখল, বকেয়াটা তারই; এই রানের না হলে বাদ
            if ((int) HeldWagePayment::query()->where('payslip_id', $slip->id)->value('paid_in_run_id') !== (int) $run->id) {
                continue;
            }

            $out[] = [$slip, (string) __('hr::payroll.arrears_reference', ['no' => (string) $slip->run?->document_no,
                'month' => $slip->run?->month->format('M Y')])];
        }

        return $out;
    }

    public function bankFile(PayrollRun $run, ?User $user = null): array
    {
        // ⓘ নাম ধরে কেউ চাইলে কেবল তাঁর নাগালের কর্মী ([[BranchReach]], অডিট HR ⛔২)
        $slips = ($user === null ? $run->payslips()->getQuery() : app(BranchReach::class)->throughEmployee($run->payslips()->getQuery(), $user))
            ->with('employee')
            ->where('payment_method', 'bank')
            ->get();

        $rows = [];
        $rows[] = ['Account No', 'Account Name', 'Amount', 'Routing No', 'Bank', 'Employee Code', 'Reference'];

        $reference = $run->document_no.' '.$run->month->format('M Y');

        foreach ($slips as $slip) {
            /*
             * শূন্য বা ঋণাত্মক নিট বাদ।
             *
             * কারও কর্তন আয়ের চেয়ে বেশি হলে নিট শূন্যের নিচে নামে (পুরো
             * অগ্রিম এক মাসেই কাটা)। ব্যাংককে ঋণাত্মক অঙ্ক পাঠানো যায় না,
             * আর শূন্য পাঠানোরও মানে নেই।
             */
            if (bccomp((string) $slip->net, '0', 4) <= 0) {
                continue;
            }

            $rows[] = [
                (string) $slip->bank_account_no,
                (string) ($slip->bank_account_name ?: $slip->employee?->name_en),
                // ব্যাংকের ফাইলে যাওয়া অঙ্ক — গোল করা bcmath-এ, কমা ছাড়া
                Money::round($slip->net, 2),
                (string) $slip->bank_routing_no,
                (string) $slip->bank_name,
                (string) $slip->employee?->code,
                $reference,
            ];
        }

        // ⭐ বকেয়া মজুরি — আগের রানের আটকে থাকা, এখন সই-পড়া হিসাবে, একবারই ([[arrearsFor()]])
        foreach ($this->arrearsFor($run, $user) as [$slip, $arrears]) {
            $employee = $slip->employee;

            $rows[] = [
                (string) $employee->bank_account_no,
                (string) ($employee->bank_account_name ?: $employee->name_en),
                Money::round($slip->net, 2),
                (string) $employee->bank_routing_no,
                (string) $employee->bank_name,
                (string) $employee->code,
                $arrears,
            ];
        }

        $csv = '';

        foreach ($rows as $row) {
            $csv .= implode(',', array_map($this->csvField(...), $row))."\r\n";
        }

        return [
            'name' => 'salary-'.$run->month->format('Y-m').'-'.$run->document_no.'.csv',
            'content' => $csv,
            // শিরোনামের সারিটা বাদ দিয়ে গোনা
            'rows' => max(0, count($rows) - 1),
        ];
    }

    // ── ভেতরের কাজ ────────────────────────────────────────────────────

    private function buildPayslip(PayrollRun $run, Employee $employee, Carbon $monthEnd): Payslip
    {
        $components = $this->salaries->componentsOn($employee, $monthEnd);

        /*
         * অনুপস্থিতির ভাগ — যতটুকু কাটার কথা ততটুকুই।
         *
         * ── কেন সব খাতে নয় ────────────────────────────────────────
         * তিন দিন কামাই করলে বেতন ও ভাতা কমে, কিন্তু অগ্রিমের কিস্তি
         * কমে না — ধার তো পুরোটাই নেওয়া হয়েছিল। কোন খাত কমবে তা
         * খাতের নিজের ঘরে (prorated_by_attendance) লেখা আছে।
         *
         * সুইচ বন্ধ থাকলে বা হাজিরাই না বসানো থাকলে ভাগটা ১ — কারও
         * বেতন কাটে না।
         */
        $factor = $this->attendanceFactor($employee, $monthEnd);

        /*
         * ⭐ মাতৃত্বকালীন সুবিধা — ধারা ৪৮ (fe-র উত্তর ২, ২১ অক্টোবর ২০২৬; [[MaternityBenefit]]; a6)।
         * ⓘ বেতনসহ মাতৃত্বকালীন ছুটির দিনগুলো কাঠামোর বেতনে নয়, আগের তিন মাসের গড় দৈনিক মজুরিতে: হাজিরায় কমে এমন আয়ের খাত থেকে
         * সেই দিনগুলোর ভাগ (দিন ÷ মাসের দিন) বাদ, আর "মাতৃত্বকালীন সুবিধা" সারি = দিন × গড়। আগের মজুরি না থাকলে (গড় নেই) কাঠামোই চলে।
         */
        $benefits = [];
        $maternity = app(MaternityBenefit::class);

        foreach ($maternity->daysIn($employee, $monthEnd) as ['application' => $application, 'days' => $mDays]) {
            $average = $maternity->averageDailyWage($application);

            if ($average === null) {
                continue;
            }

            $factor = bcsub($factor, bcdiv($mDays, (string) $monthEnd->daysInMonth, 10), 10);
            $benefits[] = Money::round(bcmul($mDays, $average, 10), 2);
        }

        $factor = bccomp($factor, '0', 10) < 0 ? '0' : $factor;

        $slip = Payslip::create([
            'company_id' => $run->company_id,
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            // ⛔ সই-না-পড়া প্রথম হিসাব — বেতন কোথাও যায় না, "আটকে" ([[heldReason()]]; fe, ২১ অক্টোবর ২০২৬)
            'payment_method' => self::heldReason($employee) === null ? $employee->payment_method : self::HELD,
            'bank_name' => $employee->bank_name,
            'bank_account_name' => $employee->bank_account_name,
            'bank_account_no' => $employee->bank_account_no,
            'bank_routing_no' => $employee->bank_routing_no,
            'mfs_number' => $employee->mfs_number,
        ]);

        $gross = '0';
        $deductions = '0';
        // ⓘ ভবিষ্য তহবিল আর অধিকালের দুই প্রশ্ন — দেওয়া মূল (কাজের দিনের ভাগে), আর কর্মী তহবিলে কিছু দিলেন কি না ([[ProvidentFund]])
        $paidBasic = '0';
        $paysIntoFund = false;
        $fundId = $this->fund->enabled() ? $this->fund->fundAccount()?->id : null;

        /*
         * ⛔ তহবিল চালু অথচ খাত নেই — রান থামে, বাংলায় কারণ বলে; ভুল খাতে (প্রদেয় বেতন বা বেতন-খরচ) নীরবে বসে না (a6, ২১ অক্টোবর ২০২৬;
         * খাত বসানোর কমান্ড `abos:hr-law-accounts`)।
         */
        if ($this->fund->enabled() && ($fundId === null || $this->fund->expenseAccount() === null)) {
            throw ValidationException::withMessages(['account' => __('hr::validation.pf_accounts_missing')]);
        }

        foreach ($components as $component) {
            /** @var SalaryHead $head */
            $head = $component['head'];

            /*
             * ⛔ পয়সায় গোল — এখানেই, একবার (চেকলিস্ট অডিট ২৭ সেপ্টেম্বর §২, ২ অক্টোবর ২০২৬;
             * [[TheBankFileAndTheBooksPaidTheSameSalaryTest]])। ⓘ আগে উপস্থিতির ভাগে চার ঘরের অঙ্ক থাকত
             * (৳১০,০০০ × ২৩/৩০ = ৭,৬৬৬.৬৬৬৭), খাতায় সেটাই বসত, আর ব্যাংক-ফাইল প্রতিজনকে দুই ঘরে গোল করত — খাতার
             * "প্রদেয় বেতন"-এ ভগ্নাংশ পয়সা চিরকাল ঝুলে থাকত। এখন বেতনশিট, খাতা আর ফাইল একই অঙ্ক।
             */
            $amount = Money::round($head->prorated_by_attendance
                ? bcmul($component['amount'], $factor, 10)
                : $component['amount'], 2);

            /*
             * ⭐ দেওয়া মূল — স্লিপের মূল-সারির অঙ্ক: মাসের কাজের দিনের ভাগে (যোগদান/ছাড়া আর বিনা-বেতনের দিন বাদ), পয়সায় গোল
             * ([[attendanceFactor()]])। প্রতিষ্ঠানের PF ভাগ আর অধিকালের ভিত এটাই — কাঠামোর পুরো মূল নয় (fe, ২১ অক্টোবর ২০২৬, দফা ৪)।
             */
            if ($head->is_basic) {
                $paidBasic = $amount;
            }

            /*
             * ⛔ অগ্রিমের কিস্তি কর্মীর খোলা অগ্রিমের বেশি নয় — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (HR ⛔৪)। ⓘ কিস্তি মাসিক নির্দিষ্ট
             * অঙ্ক, বাকির সাথে বাঁধা ছিল না: অগ্রিম শোধ হয়ে গেলেও প্রতি মাসে কাটা চলত। এখন মাস-শেষে তাঁর নামের ১১৩১-এর জের পর্যন্ত।
             */
            if (! $head->isEarning() && $this->isAdvance($this->accountFor($head)?->id)) {
                $open = $this->advanceOpen($employee, $monthEnd);
                $amount = bccomp($amount, $open, 2) > 0 ? Money::round(bccomp($open, '0', 2) > 0 ? $open : '0', 2) : $amount;
            }

            PayslipLine::create([
                'company_id' => $run->company_id,
                'payslip_id' => $slip->id,
                'salary_head_id' => $head->id,
                'head_code' => $head->code,
                'head_name_en' => $head->name_en,
                'head_name_bn' => $head->name_bn,
                'kind' => $head->kind,
                'amount' => $amount,
                'sort_order' => $head->sort_order,
                'account_id' => $this->accountFor($head)?->id,
            ]);

            if ($head->isEarning()) {
                $gross = bcadd($gross, $amount, 4);
            } else {
                $deductions = bcadd($deductions, $amount, 4);

                if ($fundId !== null && $this->accountFor($head)?->id === $fundId && bccomp($amount, '0', 2) > 0) {
                    $paysIntoFund = true;
                }
            }
        }

        /*
         * ⭐ অধিকাল — BLA ধারা ১০৮: মূল ÷ ২০৮ × ২ × অনুমোদিত বাড়তি ঘণ্টা (a6, ২১ অক্টোবর ২০২৬; [[OvertimeService]])।
         * ⛔ সুইচ চালু, কর্মী যোগ্য (নিয়োগের ধরন), আর ব্যবস্থাপকের সই হওয়া শিট — তিনটাই, তবে। সইয়ের অপেক্ষায় থাকা ঘণ্টার এক পয়সাও নয়।
         * ⓘ আয়ের সারি, "বেতন ও মজুরি" খরচে; মোট আয় আর নিটে ঢোকে, তাই রানের সই আর ব্যাংকের ফাইলও এটা ধরে।
         */
        if ($this->overtime->enabled() && $this->overtime->isEligible($employee)) {
            $hours = $this->overtime->signedHours($employee, $monthEnd);
            // ⓘ দেওয়া মূলের উপর — মাসের মাঝে যোগ দিলে ঘণ্টা-হারও সেই ভাগের (fe, দফা ৪)
            $pay = bccomp($hours, '0', 4) > 0 ? $this->overtime->amountFor($paidBasic, $hours) : '0';

            if (bccomp($pay, '0', 2) > 0) {
                PayslipLine::create([
                    'company_id' => $run->company_id,
                    'payslip_id' => $slip->id,
                    'salary_head_id' => null,
                    'head_code' => OvertimeService::LINE_CODE,
                    'head_name_en' => 'Overtime ('.rtrim(rtrim($hours, '0'), '.').' h)',
                    'head_name_bn' => 'অধিকাল ভাতা ('.rtrim(rtrim($hours, '0'), '.').' ঘণ্টা)',
                    'kind' => SalaryHead::EARNING,
                    'amount' => $pay,
                    'sort_order' => 900,
                    'account_id' => $this->accountByCode(StandardChart::SALARY_EXPENSE)?->id,
                ]);

                $gross = bcadd($gross, $pay, 4);
            }
        }

        /*
         * ⭐ বেতন থেকে উৎসে কর — আনুমানিক বার্ষিক কর ÷ ১২ (আয়কর আইন ২০২৩ ধারা ৮৬; a6, ২১ অক্টোবর ২০২৬; সূত্র [[SalaryTax]])।
         * ⛔ ধাপ বসানো না থাকলে কিছুই কাটা হয় না (রানের পাতা বলে "করের ধাপ বসানো নেই")। ⓘ কর্তনের সারি, ২১৩২-এ ক্রেডিট; খাত না
         * থাকলে থামে — প্রদেয় বেতনে নীরবে মিশে গেলে সরকারের পাওনা কর্মীর পাওনা হয়ে দেখাত।
         */
        if ($this->tax->hasSlabs()) {
            // ⭐ আয়ের বছরের শেষ মাসে (জুন) আনুমানিক ভাগ নয়, বছরের আসল আয়ের উপর সমন্বয় (fe, দফা ২; [[SalaryTax::trueUp()]])
            $tds = SalaryTax::closesTheIncomeYear($monthEnd)
                ? $this->tax->trueUp($employee, $monthEnd, $gross)['due']
                : $this->tax->estimate($employee, $monthEnd, $components)['monthly'];

            if (bccomp($tds, '0', 2) > 0) {
                $account = $this->tax->payableAccount();

                if ($account === null) {
                    throw ValidationException::withMessages(['account' => __('hr::validation.tds_account_missing')]);
                }

                PayslipLine::create([
                    'company_id' => $run->company_id,
                    'payslip_id' => $slip->id,
                    'salary_head_id' => null,
                    'head_code' => SalaryTax::LINE_CODE,
                    'head_name_en' => 'Tax deducted at source',
                    'head_name_bn' => 'উৎসে কর',
                    'kind' => SalaryHead::DEDUCTION,
                    'amount' => $tds,
                    'sort_order' => 900,
                    'account_id' => $account->id,
                ]);

                $deductions = bcadd($deductions, $tds, 4);
            }
        }

        /*
         * ⭐ ভবিষ্য তহবিলে প্রতিষ্ঠানের সমান ভাগ — সুইচ চালু আর কর্মী এই মাসে তহবিলে কিছু দিয়েছেন, তবেই (BLA ধারা ২৬৪; a6, ২১ অক্টোবর
         * ২০২৬, [[ProvidentFund]])। ⓘ স্লিপে আলাদা সারি, নিটের বাইরে — মোট আয়, কর্তন আর নিট যেমন ছিল তেমনই; খাতায় খরচ আর তহবিলের দায়
         * ([[ledgerLines()]])। ⛔ সুইচ বন্ধ বা সদস্য নন — কোনো সারি নেই, আগের অবিকল বেতনশিট।
         */
        if ($paysIntoFund) {
            $share = $this->fund->employerShare($paidBasic);

            if (bccomp($share, '0', 2) > 0) {
                PayslipLine::create([
                    'company_id' => $run->company_id,
                    'payslip_id' => $slip->id,
                    'salary_head_id' => null,
                    'head_code' => PayslipLine::PF_EMPLOYER_CODE,
                    'head_name_en' => 'Provident Fund (employer)',
                    'head_name_bn' => 'ভবিষ্য তহবিল (প্রতিষ্ঠানের অংশ)',
                    'kind' => PayslipLine::EMPLOYER,
                    'amount' => $share,
                    'sort_order' => 10,
                    'account_id' => $this->fund->expenseAccount()?->id,
                ]);
            }
        }

        /*
         * ⭐ প্রসবের আসল দিনে সরে যাওয়া মাতৃত্বকালীন ছুটির দেওয়া মাসের পার্থক্য — পরের রানে, একটা সমন্বয়ের সারিতে (ধারা ৪৫; fe-র উত্তর ৩,
         * ২১ অক্টোবর ২০২৬; [[MaternityDeliveryService::paidMonthsDifference()]]; a6)। ⓘ নিশ্চিত রান কখনো বদলায় না — পার্থক্য এই খসড়ায় বসে;
         * পাওনা হলে আয়, ফেরতযোগ্য হলে কর্তন। কোন রানে বসল তা লেখা থাকে: আবার-বানানো একই রানে আবার বসায়, বাতিল রান হলে পরের রানে।
         */
        $adjustments = MaternityDelivery::query()->withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('status', MaternityDelivery::APPROVED)
            ->where('adjustment', '!=', 0)
            ->where(fn ($q) => $q->whereNull('payroll_run_id')->orWhere('payroll_run_id', $run->id)
                ->orWhereIn('payroll_run_id', PayrollRun::acrossBranches()->where('status', DocumentStatus::CANCELLED)->select('id')))
            ->orderBy('id')
            ->get();

        foreach ($adjustments as $change) {
            $amount = (string) $change->adjustment;
            $kind = bccomp($amount, '0', 2) > 0 ? SalaryHead::EARNING : SalaryHead::DEDUCTION;
            $amount = Money::round(ltrim($amount, '-'), 2);

            PayslipLine::create([
                'company_id' => $run->company_id,
                'payslip_id' => $slip->id,
                'salary_head_id' => null,
                'head_code' => MaternityBenefit::ADJUSTMENT_CODE,
                'head_name_en' => 'Maternity adjustment ('.$change->actual_delivery_date->toDateString().')',
                'head_name_bn' => 'মাতৃত্বকালীন সমন্বয় ('.$change->actual_delivery_date->toDateString().')',
                'kind' => $kind,
                'amount' => $amount,
                'sort_order' => 910,
                'account_id' => $this->fallbackAccount($kind)?->id,
            ]);

            if ($kind === SalaryHead::EARNING) {
                $gross = bcadd($gross, $amount, 4);
            } else {
                $deductions = bcadd($deductions, $amount, 4);
            }

            $change->forceFill(['payroll_run_id' => $run->id])->save();
        }

        foreach ($benefits as $amount) {
            PayslipLine::create([
                'company_id' => $run->company_id,
                'payslip_id' => $slip->id,
                'salary_head_id' => null,
                'head_code' => MaternityBenefit::LINE_CODE,
                'head_name_en' => 'Maternity benefit',
                'head_name_bn' => 'মাতৃত্বকালীন সুবিধা',
                'kind' => SalaryHead::EARNING,
                'amount' => $amount,
                'sort_order' => 900,
                // ⓘ বেতনের খরচেই — খাতের নিজের হিসাব-খাত নেই, তাই আয়ের ফলব্যাক ([[fallbackAccount()]])
                'account_id' => $this->fallbackAccount(SalaryHead::EARNING)?->id,
            ]);

            $gross = bcadd($gross, $amount, 4);
        }

        $slip->forceFill([
            'gross' => $gross,
            'deductions' => $deductions,
            'net' => bcsub($gross, $deductions, 4),
        ])->save();

        return $slip->fresh();
    }

    /**
     * মাসের কত ভাগ বেতন প্রাপ্য — ১ মানে পুরোটা।
     *
     * ── কেন মাসের দিন দিয়ে ভাগ, ২৬ বা ৩০ দিয়ে নয় ─────────────────
     * ফেব্রুয়ারিতে ২৮ দিন আর জুলাইয়ে ৩১। স্থির ৩০ ধরলে ফেব্রুয়ারিতে
     * এক দিন কামাই করলে মাসের ১/৩০ কাটত, অথচ মাসটাই ২৮ দিনের —
     * অর্থাৎ কম কাটত। ছোট পার্থক্য, কিন্তু প্রতি মাসে, প্রতিজনের।
     *
     * ── কেন হাজিরা না বসালে ভাগটা ১ ────────────────────────────────
     * খালি খাতাকে অনুপস্থিতি ধরলে সুইচ চালু করার প্রথম মাসেই সবার
     * বেতন শূন্য হত।
     *
     * ── ⛔ চাকরির দিনগুলোই, মাসের বাকিটা নয় — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ ─────────
     * যোগদানের আগের আর ছাড়ার পরের দিন বাদ — হাজিরার সুইচ বন্ধ থাকলেও। ⓘ আগে মাসের যেকোনো দিন চাকরিতে থাকলেই পুরো মাস:
     * ২৫ অক্টোবর যোগ দিলে মূল ৩০,০০০ + বাড়িভাড়া ৫০% = ৪৫,০০০, অথচ পাওনা ৭ দিনের ১০,১৬১.২৯। একই মাসের দিনের ভাগ, তাই
     * অনুপস্থিতির কাটার সাথে মেলে।
     */
    private function attendanceFactor(Employee $employee, Carbon $monthEnd): string
    {
        $daysInMonth = (string) $monthEnd->daysInMonth;
        $from = $monthEnd->copy()->startOfMonth()->startOfDay();
        $to = $monthEnd->copy()->startOfDay();

        if ($employee->joining_date !== null && $employee->joining_date->copy()->startOfDay()->gt($from)) {
            $from = $employee->joining_date->copy()->startOfDay();
        }

        if ($employee->leaving_date !== null && $employee->leaving_date->copy()->startOfDay()->lt($to)) {
            $to = $employee->leaving_date->copy()->startOfDay();
        }

        $employed = $from->gt($to) ? '0' : (string) ((int) $from->diffInDays($to) + 1);
        $unpaid = $this->settings->get('hr.attendance_affects_salary')
            ? $this->attendance->unpaidDays($employee, $monthEnd) : '0';

        if (bccomp($employed, $daysInMonth, 1) >= 0 && bccomp($unpaid, '0', 1) <= 0) {
            return '1';
        }

        $paid = bcsub($employed, $unpaid, 1);

        // পুরো মাস অনুপস্থিত থাকলে ভাগটা শূন্য, ঋণাত্মক নয়
        if (bccomp($paid, '0', 1) <= 0) {
            return '0';
        }

        // ⓘ দশ ঘর — ছয় ঘরে ৳১০,০০০ × ২৩/৩০ গোল হত ৭,৬৬৬.৬৬, আসল ৭,৬৬৬.৬৭ ([[build]]-এর পয়সার গোল)
        return bcdiv($paid, $daysInMonth, 10);
    }

    /**
     * খাতাওয়ালা সারিগুলো — খাত ধরে জড়ো করা।
     *
     * ── কেন কর্মী ধরে ধরে নয় ────────────────────────────────────────
     * বিশ জন কর্মীর সাতটা খাত মানে ১৪০টা লেজার সারি, অথচ খাত ধরে
     * জড়ো করলে আটটা। খতিয়ানে "বেতন ও মজুরি" খুললে মাসে একটা সারি
     * দেখা যায়, আর কার কত তা বেতনশিটেই আছে — ড্রিল-ডাউন সেখানেই নেয়।
     *
     * @return list<array{account_id: int, debit?: string, credit?: string, narration?: string}>
     */
    private function ledgerLines(PayrollRun $run): array
    {
        $debits = [];
        $credits = [];
        $net = [];

        /*
         * ⛔ প্রতিটা কর্মীর সারি তাঁর নিজের শাখায় — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (HR ৬; [[TheSalaryIsBookedWhereThePersonWorksTest]])।
         * ⓘ রানটা গোটা কোম্পানির ([[build()]]), অথচ পুরো দাখিলা বসত রান-বানানো মানুষের শাখায় — ঢাকার কেরানি চালালে নেত্রকোনার বেতন-খরচও
         * ঢাকার লাভ-ক্ষতিতে। এখন খাত আর শাখা ধরে জড়ো; শাখা লেখা নেই এমন কর্মী (প্রধান অফিস) কোম্পানির প্রধান শাখায়। প্রতিটা বেতনশিট
         * নিজেই মেলে (আয় = কর্তন + নিট), তাই প্রতিটা শাখার দাখিলাও মেলে।
         */
        $head = Company::query()->find($run->company_id)?->defaultBranch()?->id ?? $run->branch_id;
        $branchOf = fn (Payslip $slip): ?int => $slip->employee?->branch_id === null ? $head : (int) $slip->employee->branch_id;
        $run->loadMissing(['payslips.employee' => fn ($q) => $q->withTrashed()]);

        /*
         * ⛔ অগ্রিমের আদায় কর্মী ধরে, তাঁর নামে — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (HR ⛔৪)। ⓘ অগ্রিম দেওয়া হয় ভাউচারে কর্মীর নামে
         * (১১৩১, পক্ষ `employee`), অথচ বেতনের কর্তন খাত ধরে একসাথে, নাম ছাড়া বসত — ১১৩১-এর মোট কমত, কারও নিজের জের নয়, আর
         * "কে কত বাকি" চিরকাল ভুল থাকত। বাকি কর্তন আগের মতো খাত ধরে একসাথে।
         */
        $owed = [];

        /*
         * ⛔ ঋণাত্মক নিট কর্মীর নামে পাওনা, মোটে কাটাকাটি নয় — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (HR ⚠️৭;
         * [[ANegativeSalaryIsOwedByTheEmployeeTest]])। ⓘ কর্তন মোটের বেশি হলে (কামাইয়ে বেতন কমল, কর্তন কমল না) শিটের নিট ঋণাত্মক;
         * আগে সেটা সবার নিটের যোগে মিশে প্রদেয় বেতন কমাত — অন্যদের দেনা থেকে কাটা, আর কর্মীর কাছে পাওনা কোথাও নেই (IAS 1: অফসেট
         * নয়)। এখন সে অঙ্ক ১১৩১ কর্মীর অগ্রিমে ডেবিট, তাঁর নামে — পরের মাসের অগ্রিম কর্তন সেটাও ধরে ([[AdvanceBalance]])।
         */
        $short = [];

        foreach ($run->payslips as $slip) {
            $branch = $branchOf($slip);

            if (bccomp((string) $slip->net, '0', 4) < 0) {
                $key = $slip->employee_id.':'.$branch;
                $short[$key] = [(int) $slip->employee_id, $branch, bcadd($short[$key][2] ?? '0', bcsub('0', (string) $slip->net, 4), 4)];
            } else {
                $net[(string) $branch] = bcadd($net[(string) $branch] ?? '0', (string) $slip->net, 4);
            }

            foreach ($slip->lines as $line) {
                /*
                 * ⭐ প্রতিষ্ঠানের অংশ — খরচ ডেবিট, তহবিলের দায় ক্রেডিট, দুটোই কর্মীর শাখায় ([[ProvidentFund]]; a6, ২১ অক্টোবর ২০২৬)।
                 * ⓘ নিটের বাইরে, তাই প্রদেয় বেতনে কিছু নয়; দুই পাশ সমান, তাই শাখার দাখিলা মেলে।
                 */
                if ($line->isEmployerShare()) {
                    $expenseId = $line->account_id ?? $this->fund->expenseAccount()?->id;
                    $fundId = $this->fund->fundAccount()?->id;

                    if ($expenseId === null || $fundId === null) {
                        throw ValidationException::withMessages([
                            'account' => __('hr::validation.pf_accounts_missing'),
                        ]);
                    }

                    $key = $expenseId.':'.$branch;
                    $debits[$key] = [(int) $expenseId, $branch, bcadd($debits[$key][2] ?? '0', (string) $line->amount, 4)];
                    $key = $fundId.':'.$branch;
                    $credits[$key] = [(int) $fundId, $branch, bcadd($credits[$key][2] ?? '0', (string) $line->amount, 4)];

                    continue;
                }

                // ⛔ খাত কোড ধরে, মানে ধরেই — না পেলে থামা, ভুল খাতে বা অর্ধেক জাবেদায় নয় ([[StandardChart::accountFor()]])
                // ⓘ শূন্য সারি খাতায় যায় না ([[bccomp]] নিচে), তাই তার জন্য প্রমিত খাত খোঁজা হয় না
                if ($line->account_id === null && bccomp((string) $line->amount, '0', 4) === 0) {
                    continue;
                }

                $accountId = $line->account_id ?? StandardChart::accountFor($this->fallbackCode($line->kind))->id;

                if (! $line->isEarning() && $this->isAdvance((int) $accountId)) {
                    $key = $accountId.':'.$slip->employee_id.':'.$branch;
                    $owed[$key] = [(int) $accountId, (int) $slip->employee_id, $branch, bcadd($owed[$key][3] ?? '0', (string) $line->amount, 4)];

                    continue;
                }

                $bucket = $line->isEarning() ? 'debits' : 'credits';
                $key = $accountId.':'.$branch;
                ${$bucket}[$key] = [(int) $accountId, $branch, bcadd(${$bucket}[$key][2] ?? '0', (string) $line->amount, 4)];
            }
        }

        // ⛔ খাত কোড ধরে, মানে ধরেই — না পেলে থামা, ভুল খাতে বা অর্ধেক জাবেদায় নয় ([[StandardChart::accountFor()]])
        $payable = StandardChart::accountFor(StandardChart::SALARY_PAYABLE);

        $narration = __('hr::message.ledger_narration', [
            'month' => $run->month->format('M Y'),
            'no' => $run->document_no,
        ]);

        $lines = [];

        foreach ($debits as [$accountId, $branch, $amount]) {
            if (bccomp($amount, '0', 4) === 0) {
                continue;
            }

            $lines[] = ['account_id' => $accountId, 'debit' => $amount, 'narration' => $narration, 'branch_id' => $branch];
        }

        // ⛔ খাত কোড ধরে, মানে ধরেই — না পেলে থামা, ভুল খাতে বা অর্ধেক জাবেদায় নয় ([[StandardChart::accountFor()]])
        $advance = $short === [] ? null : StandardChart::accountFor(StandardChart::EMPLOYEE_ADVANCE);

        foreach ($short as [$employeeId, $branch, $amount]) {
            $lines[] = [
                'account_id' => (int) $advance->id, 'debit' => $amount, 'narration' => $narration,
                'party_type' => Employee::drillSourceType(), 'party_id' => $employeeId, 'branch_id' => $branch,
            ];
        }

        /*
         * নিট অঙ্কটাও একই ঝুড়িতে, আলাদা সারি নয়।
         *
         * যে খাতে নিজের হিসাব-খাত বসানো নেই তার কর্তনও "প্রদেয় বেতন"-এ
         * পড়ে। আলাদা করে যোগ করলে এক ডকুমেন্টে একই খাতে দুইটা ক্রেডিট
         * সারি বসত — খতিয়ানে দেখতে যেন দুইবার কিছু হয়েছে, অথচ হয়নি।
         */
        foreach ($net as $branch => $amount) {
            $branch = $branch === '' ? null : (int) $branch;
            $key = $payable->id.':'.$branch;
            $credits[$key] = [(int) $payable->id, $branch, bcadd($credits[$key][2] ?? '0', $amount, 4)];
        }

        foreach ($credits as [$accountId, $branch, $amount]) {
            if (bccomp($amount, '0', 4) === 0) {
                continue;
            }

            $lines[] = ['account_id' => $accountId, 'credit' => $amount, 'narration' => $narration, 'branch_id' => $branch];
        }

        foreach ($owed as [$accountId, $employeeId, $branch, $amount]) {
            if (bccomp($amount, '0', 4) === 0) {
                continue;
            }

            $lines[] = [
                'account_id' => $accountId, 'credit' => $amount, 'narration' => $narration,
                'party_type' => Employee::drillSourceType(), 'party_id' => $employeeId, 'branch_id' => $branch,
            ];
        }

        return $lines;
    }

    /**
     * ⛔ নিশ্চিত করার মুহূর্তে অগ্রিমের কিস্তি আবার মাপা — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ ([[TheAdvanceIsNotTakenTwiceTest]])।
     *
     * ⓘ খসড়া বানানোর সময় কিস্তি মাস-শেষের খোলা অগ্রিমে আটকানো হয় ([[buildPayslip()]])। কিন্তু খসড়া আর নিশ্চিতের মাঝে, বা মাস-শেষের
     * পরে, কর্মী অগ্রিম নগদে ফেরত দিতে পারেন বা খরচের দাবি দিয়ে মেটাতে পারেন — তখনও বেতন থেকে পুরো কিস্তি কাটা হত, আর তাঁর নামের
     * ১১৩১ ঋণাত্মক হত (একই টাকা দুইবার আদায়)। এখন কর্মীর সারিতে তালা দিয়ে আজ পর্যন্ত খাতায় বসা জের পড়া হয়, কিস্তি তার বেশি হলে
     * কমে, আর বেতনশিট ও রানের মোট আবার গোনা হয়। তালাটা খরচের দাবির সাথে একই ([[AdvanceBalance::lock()]])।
     */
    private function capAdvancesNow(PayrollRun $run): void
    {
        // ⓘ চলে যাওয়া (মুছে ফেলা) কর্মীর শেষ মাসের শিটও থাকতে পারে — তাঁর অগ্রিমও তাঁর নামেই
        $run->load(['payslips.lines', 'payslips.employee' => fn ($q) => $q->withTrashed()]);

        $owing = $run->payslips->filter(fn (Payslip $slip) => $slip->lines
            ->contains(fn (PayslipLine $line) => ! $line->isEarning() && $this->isAdvance($line->account_id === null ? null : (int) $line->account_id)));

        if ($owing->isEmpty()) {
            return;
        }

        app(AdvanceBalance::class)->lock($owing->pluck('employee_id')->map(fn ($id) => (int) $id)->sort()->values()->all());

        // ⓘ খাতায় যা আজ পর্যন্ত বসেছে — মাস-শেষের পরের ফেরতও ধরে; রানের তারিখ পরে হলে সেদিন পর্যন্ত
        $on = $run->trx_date->greaterThan(Carbon::today()) ? $run->trx_date->copy() : Carbon::today();
        $changed = false;

        foreach ($owing as $slip) {
            $left = $this->advanceOpen($slip->employee, $on);
            $left = bccomp($left, '0', 2) > 0 ? $left : '0';
            $cut = '0';

            foreach ($slip->lines as $line) {
                if ($line->isEarning() || ! $this->isAdvance($line->account_id === null ? null : (int) $line->account_id)) {
                    continue;
                }

                $take = bccomp((string) $line->amount, $left, 2) > 0 ? Money::round($left, 2) : (string) $line->amount;
                $left = bcsub($left, $take, 4);

                if (bccomp($take, (string) $line->amount, 2) !== 0) {
                    $cut = bcadd($cut, bcsub((string) $line->amount, $take, 4), 4);
                    $line->forceFill(['amount' => $take])->save();
                }
            }

            if (bccomp($cut, '0', 4) !== 0) {
                $slip->forceFill([
                    'deductions' => bcsub((string) $slip->deductions, $cut, 4),
                    'net' => bcadd((string) $slip->net, $cut, 4),
                ])->save();
                $changed = true;
            }
        }

        if ($changed) {
            $this->recount($run);
            $run->load('payslips.lines');
        }
    }

    /** খাতটা কি কর্মীর অগ্রিম (১১৩১ বা তার নিচে) — কারও নামে বসে এমন খাত ([[AdvanceBalance]]) */
    private function isAdvance(?int $accountId): bool
    {
        return app(AdvanceBalance::class)->isAdvance($accountId);
    }

    /** মাস-শেষে কর্মীর নামের খোলা অগ্রিম — খরচের দাবির একই নিয়ম ([[AdvanceBalance::open()]]) */
    private function advanceOpen(Employee $employee, Carbon $monthEnd): string
    {
        return app(AdvanceBalance::class)->open($employee, $monthEnd);
    }

    /**
     * একটা খাতের হিসাব-খাত — নিজেরটা, নাহলে প্রমিতটা।
     */
    private function accountFor(SalaryHead $head): ?Account
    {
        if ($head->account_id !== null) {
            return $head->account;
        }

        return $this->fallbackAccount($head->kind);
    }

    /**
     * খাত না বসানো থাকলে যেখানে যাবে।
     *
     * আয় যায় "বেতন ও মজুরি" খরচে, আর কর্তন যায় "প্রদেয় বেতন" দায়ে —
     * কারণ কেটে রাখা টাকাটা এখনো কর্মীরই, শুধু হাতে যায়নি। যে
     * প্রতিষ্ঠান ভবিষ্য তহবিল আলাদা রাখতে চায় সে খাতে নিজের হিসাব খাত
     * বসিয়ে দেবে, আর তখন এই ফলব্যাকটা আর লাগে না।
     */
    private function fallbackAccount(string $kind): ?Account
    {
        return $this->accountByCode($this->fallbackCode($kind));
    }

    private function fallbackCode(string $kind): string
    {
        return $kind === SalaryHead::EARNING ? StandardChart::SALARY_EXPENSE : StandardChart::SALARY_PAYABLE;
    }

    // ⓘ পড়ার পথে (খসড়া শিট, বাতিলের পাহারা) — মানে না ধরলে বা দল হলে null; লেখার পথ [[StandardChart::accountFor()]] ডাকে
    private function accountByCode(string $code): ?Account
    {
        $account = StandardChart::find($code);

        return $account !== null && ! $account->is_group ? $account : null;
    }

    private function recount(PayrollRun $run): PayrollRun
    {
        $slips = $run->payslips()->get();

        $run->forceFill([
            'gross_total' => $slips->reduce(fn ($c, $s) => bcadd($c, (string) $s->gross, 4), '0'),
            'deduction_total' => $slips->reduce(fn ($c, $s) => bcadd($c, (string) $s->deductions, 4), '0'),
            'net_total' => $slips->reduce(fn ($c, $s) => bcadd($c, (string) $s->net, 4), '0'),
            'employee_count' => $slips->count(),
        ])->save();

        return $run->fresh();
    }

    /**
     * এক মাসে একটাই জীবিত রান, পুরো কোম্পানিতে।
     *
     * দুইটা থাকলে একই মাসের বেতন দুইবার খরচে বসত, আর কেউ খেয়াল না
     * করলে ব্যাংকেও দুইবার টাকা যেত।
     *
     * বাতিল করাগুলো বাদ — নাহলে ভুল রান বাতিল করে আর নতুন বানানো যেত না।
     */
    private function assertNoLiveRun(Carbon $monthStart, string $type): void
    {
        /*
         * ⭐ ধরন ধরে — a6, ২১ অক্টোবর ২০২৬ ([[buildBonus()]])। ⓘ একই মাসে বেতন আর উৎসব ভাতা দুটোই বৈধ; একই ধরনের দুইটা নয়।
         */
        /*
         * ⛔ সব শাখা জুড়ে — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (HR ⛔১)। ⓘ রানটা পুরো কোম্পানির কর্মীদের ([[build()]]), অথচ
         * `PayrollRun` হেডারের শাখার দেয়ালে; এক শাখা বাছা থাকলে অন্য শাখায় বানানো রান অদৃশ্য থাকত, যাচাই পার হত, আর একই মাসের
         * বেতন-খরচ ও বেতন-দেনা দ্বিগুণ বসত। তালার পরের দ্বিতীয় যাচাইও এটাই ডাকে।
         */
        $exists = PayrollRun::acrossBranches()
            ->forMonth($monthStart->toDateString())
            ->ofType($type)
            ->where('status', '<>', DocumentStatus::CANCELLED)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'month' => __($type === PayrollRun::BONUS ? 'hr::validation.bonus_month_already_run' : 'hr::validation.month_already_run', [
                    'month' => $monthStart->format('M Y'),
                ]),
            ]);
        }
    }

    /** ⛔ বছরে বড়জোর দুইটা উৎসব ভাতা — কোম্পানি জুড়ে, বাতিল ছাড়া, রানের পঞ্জিকা-বছরে */
    private function assertBonusRoom(Carbon $on): void
    {
        $taken = PayrollRun::acrossBranches()
            ->ofType(PayrollRun::BONUS)
            ->where('status', '<>', DocumentStatus::CANCELLED)
            ->whereYear('month', $on->year)
            ->count();

        if ($taken >= self::BONUSES_A_YEAR) {
            throw ValidationException::withMessages([
                'bonus_date' => __('hr::validation.bonus_limit_reached', ['year' => $on->year, 'max' => self::BONUSES_A_YEAR]),
            ]);
        }
    }

    /**
     * উৎসব ভাতা কারা পান — রানের দিনে অন্তত এক বছরের চাকরি, আর সেদিন চাকরিতে।
     *
     * @return \Illuminate\Support\Collection<int, Employee>
     */
    private function bonusEligible(Carbon $on): \Illuminate\Support\Collection
    {
        return Employee::query()
            ->whereNotNull('joining_date')
            ->whereDate('joining_date', '<=', $on->copy()->subYearNoOverflow()->toDateString())
            ->where(fn ($q) => $q->whereNull('leaving_date')->orWhereDate('leaving_date', '>=', $on->toDateString()))
            ->orderBy('code')
            ->get();
    }

    /** একজনের উৎসব ভাতার স্লিপ — মূল × শতাংশ; শূন্য হলে স্লিপই নয় */
    private function buildBonusPayslip(PayrollRun $run, Employee $employee, Carbon $on): ?Payslip
    {
        $basic = '0';

        foreach ($this->salaries->componentsOn($employee, $on) as $component) {
            if ($component['head']->is_basic) {
                $basic = (string) $component['amount'];
            }
        }

        $amount = Money::round(bcdiv(bcmul($basic, $this->bonusPercent(), 10), '100', 10), 2);

        if (bccomp($amount, '0', 2) <= 0) {
            return null;
        }

        $slip = Payslip::create([
            'company_id' => $run->company_id,
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            /*
             * ⛔ সই-না-পড়া প্রথম হিসাব — ভাতাও "আটকে", বেতনের স্লিপের একই নিয়ম ([[heldReason()]]; মেশানোর পর, a6, ২১ অক্টোবর ২০২৬;
             * [[AHeldFestivalBonusNeverReachesTheBankFileTest]])। ⓘ পে-শাখার ভাতা কর্মীর পথ সোজা কপি করত, অথচ সই-শাখায় প্রথম নম্বর সই
             * না পড়া পর্যন্ত কর্মীর সারিতে খালি থাকে — ভাতার ব্যাংক ফাইলে খালি হিসাব-নম্বরের সারি যেত। এখন আটকে থাকে, সই পড়লে পরের
             * ব্যাংক ফাইলে বা বকেয়ার কাগজে একবারই যায় ([[arrearsFor()]], [[HeldWages]])।
             */
            'payment_method' => self::heldReason($employee) === null ? $employee->payment_method : self::HELD,
            'bank_name' => $employee->bank_name,
            'bank_account_name' => $employee->bank_account_name,
            'bank_account_no' => $employee->bank_account_no,
            'bank_routing_no' => $employee->bank_routing_no,
            'mfs_number' => $employee->mfs_number,
        ]);

        PayslipLine::create([
            'company_id' => $run->company_id,
            'payslip_id' => $slip->id,
            'salary_head_id' => null,
            'head_code' => self::BONUS_CODE,
            'head_name_en' => 'Festival bonus',
            'head_name_bn' => 'উৎসব ভাতা',
            'kind' => SalaryHead::EARNING,
            'amount' => $amount,
            'sort_order' => 10,
            'account_id' => $this->accountByCode(StandardChart::FESTIVAL_BONUS_EXPENSE)?->id,
        ]);

        $slip->forceFill(['gross' => $amount, 'deductions' => '0', 'net' => $amount])->save();

        return $slip->fresh();
    }

    /** ⓘ উৎসব ভাতা — মূলের শতাংশ, ০ থেকে ১০০ (আইনের সীমা এক মাসের মূল); ভুল লেখায় ১০০ */
    private function bonusPercent(): string
    {
        return self::clampBonusPercent($this->settings->get(self::BONUS_PERCENT));
    }

    /** ⓘ খাঁটি নিয়ম — সেটিংয়ের মান থেকে শতাংশ, ১০০-এর বেশি নয়; করের আনুমানিক হিসাবও এটাই পড়ে ([[SalaryTax::expectedBonus()]]) */
    public static function clampBonusPercent(mixed $raw): string
    {
        $value = trim((string) $raw);

        if (! preg_match('/^\d{1,3}(\.\d{1,4})?$/', $value)) {
            return '100';
        }

        return bccomp($value, '100', 4) > 0 ? '100' : bcadd($value, '0', 4);
    }

    /**
     * ⛔ পরিশোধ হয়ে যাওয়া বেতন উল্টানো যায় না — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (HR ⚠️৬; [[APaidPayrollCannotBeCancelledTest]])।
     *
     * ⓘ বেতন দেওয়া হয় আলাদা ভাউচারে, "প্রদেয় বেতন" ডেবিট করে — রানের সাথে বাঁধা নেই। তাই বাতিলের উল্টো দাখিলা দেনাটা আবার
     * ডেবিট করত, অথচ টাকা ততক্ষণে কর্মীর হাতে: খাতায় বেতন-দেনা ঋণাত্মক, বা অন্য মাসের পাওনা নীরবে খেয়ে ফেলা। এখন রানটা যত
     * দেনা বসিয়েছিল, খাতার গোটা কোম্পানির বেতন-দেনা তার চেয়ে কম হলে — অর্থাৎ কিছু পরিশোধ হয়েছে — বাতিল থামে; আগে পরিশোধের
     * ভাউচার বাতিল, তারপর রান। গোটা কোম্পানি, কারণ দেনাটা এক খাতে আর রানটা গোটা কোম্পানির।
     *
     * ⓘ আগে আসা আগে শোধ: পরের মাসগুলোর জীবিত রানের দেনাও বাকি থাকার কথা ধরা হয় — নাহলে আগস্ট পরিশোধ, সেপ্টেম্বর বাকি অবস্থায়
     * আগস্ট বাতিল করলে সেপ্টেম্বরের দেনাটাই "বাকি" দেখাত আর নীরবে খেয়ে ফেলা হত।
     */
    private function assertSalaryNotPaidOut(PayrollRun $run): void
    {
        // ⛔ খাত কোড ধরে, মানে ধরেই — না পেলে থামা, পাহারা নীরবে বাদ দিয়ে উল্টো দাখিলা নয় ([[StandardChart::accountFor()]])
        // ⓘ নিশ্চিত রান খাতায় এই খাতেই বসেছিল, তাই সঠিক কোম্পানিতে এটা কখনো থামে না
        $payable = StandardChart::accountFor(StandardChart::SALARY_PAYABLE);

        $owed = fn () => DB::table('ledger_entries')
            ->where('company_id', CompanyContext::id())
            ->where('account_id', $payable->id)
            ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as n');

        $put = bcadd((string) $owed()->where('source_type', PayrollRun::SOURCE_TYPE)->where('source_id', $run->id)->value('n'), '0', 4);
        $later = bcadd((string) $owed()->where('source_type', PayrollRun::SOURCE_TYPE)->whereIn('source_id', PayrollRun::acrossBranches()
            ->where('status', DocumentStatus::CONFIRMED)
            ->whereDate('month', '>', $run->month->toDateString())
            ->select('id'))->value('n'), '0', 4);
        $left = bcsub(bcadd((string) $owed()->value('n'), '0', 4), $later, 4);

        if (bccomp($left, $put, 2) < 0) {
            throw ValidationException::withMessages([
                'status' => __('hr::validation.salary_already_paid', [
                    'paid' => Money::format(bcsub($put, $left, 4)),
                    'put' => Money::format($put),
                ]),
            ]);
        }
    }

    private function assertNotCancelled(PayrollRun $run): void
    {
        if ($run->status === DocumentStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => __('hr::validation.already_cancelled'),
            ]);
        }
    }

    private function assertDraft(PayrollRun $run): void
    {
        if ($run->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => __('hr::validation.only_a_draft_can_change'),
            ]);
        }
    }

    private function financialYear(Carbon $date): ?FinancialYear
    {
        return FinancialYear::query()
            ->whereDate('starts_on', '<=', $date->toDateString())
            ->whereDate('ends_on', '>=', $date->toDateString())
            ->first();
    }

    /** CSV-র একটা ঘর — কমা বা উদ্ধৃতি থাকলে মুড়ে দেওয়া। */
    private function csvField(string $value): string
    {
        /*
         * ⛔ সূত্রের মতো শুরু হলে আগে একটা ' — ec, Hr লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬ ([[TheBankFileCarriesNoFormulaTest]])।
         * ⓘ হিসাবের নাম "=HYPERLINK(...)" লেখা থাকলে ফাইলটা এক্সেলে খোলামাত্র সূত্র চলত। = + - @ আর ট্যাব/CR দিয়ে শুরু হওয়া ঘর
         * এখন লেখা হিসেবেই পড়ে। অঙ্কের ঘর এতে পড়ে না — নিট শূন্যের বেশি না হলে সারিটাই বাদ।
         */
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $value = "'".$value;
        }

        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}

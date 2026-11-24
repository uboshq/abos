<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Models\Approval;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\SalaryHead;
use App\Modules\Hr\Models\SalaryStructure;
use App\Modules\Hr\Support\PaidMonth;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * একটা তারিখে একজন কর্মীর বেতন কী দাঁড়ায়।
 *
 * ── কেন হিসাবটা এক জায়গায় ───────────────────────────────────────────
 * একই হিসাব তিন জায়গায় লাগে: কর্মীর পর্দায় "এখন কত পান", বেতনশিট
 * বানানোর সময়, আর বেতন বাড়ানোর আগে "এখন কত" দেখানোর সময়। তিন জায়গায়
 * তিনবার লিখলে একদিন একটায় বাড়িভাড়ার শতাংশ পুরনো থেকে যেত, আর দুই
 * পর্দায় দুই অঙ্ক দেখাত — তখন কোনটা সত্যি তা বলার উপায় থাকত না।
 */
final class SalaryStructureService
{
    /** ⭐ অনুমোদনের ছকে এই কাজটা — `hr` · `salary_change` (§৮ ⚠️৫; [[change()]]) */
    public const MODULE = 'hr';

    public const ACTION_CHANGE = 'salary_change';

    /**
     * ⛔ পুরনো চিহ্ন — মালিক নিজের চাওয়া বদলে নিজে সই দিতেন (hr-gap-2c)। ⓘ fe, ২১ অক্টোবর ২০২৬: বেতনের তিন কাজে আর নয় — অনুরোধকারী
     * কখনো সই দেন না, মালিকও নন ([[ApprovalEngine::twoHands()]]); নামটা রইল পুরনো অডিট-সারি খোঁজার জন্য।
     */
    public const OWNER_SELF_SIGNED = 'owner_self_signed';

    /**
     * এই তারিখে কার্যকর প্রতিটা খাত ও তার টাকার অঙ্ক।
     *
     * ফেরত আসে খাত ধরে ধরে, ক্রম সহ — যাতে বেতনশিটে সবসময় একই ক্রমে
     * সারিগুলো ছাপা হয়।
     *
     * @return list<array{head: SalaryHead, amount: string}>
     */
    public function componentsOn(Employee $employee, Carbon $date): array
    {
        $rows = $this->effectiveRows($employee, $date);

        // মূল বেতন আগে বের করা — শতাংশের খাতগুলো এটার উপর দাঁড়ায়
        $basic = '0';

        foreach ($rows as $row) {
            if ($row->salaryHead->is_basic) {
                $basic = (string) $row->amount;
            }
        }

        $components = [];

        foreach ($rows as $row) {
            $head = $row->salaryHead;

            $amount = $head->calculation === SalaryHead::PERCENT_OF_BASIC
                ? bcdiv(bcmul($basic, (string) $row->amount, 6), '100', 4)
                : bcadd((string) $row->amount, '0', 4);

            $components[] = ['head' => $head, 'amount' => $amount];
        }

        usort($components, fn (array $a, array $b) => [$a['head']->kind, $a['head']->sort_order, $a['head']->code]
            <=> [$b['head']->kind, $b['head']->sort_order, $b['head']->code]);

        return $components;
    }

    /**
     * মোট আয়, মোট কর্তন, আর হাতে যা থাকে।
     *
     * @return array{gross: string, deductions: string, net: string}
     */
    public function totalsOn(Employee $employee, Carbon $date): array
    {
        $gross = '0';
        $deductions = '0';

        foreach ($this->componentsOn($employee, $date) as $component) {
            if ($component['head']->isEarning()) {
                $gross = bcadd($gross, $component['amount'], 4);
            } else {
                $deductions = bcadd($deductions, $component['amount'], 4);
            }
        }

        return [
            'gross' => $gross,
            'deductions' => $deductions,
            'net' => bcsub($gross, $deductions, 4),
        ];
    }

    /**
     * ⭐ মানুষের দরজা — পর্দার "সংরক্ষণ" এখানে আসে (§৮ ⚠️৫, a6, ২১ অক্টোবর ২০২৬; [[ASalaryChangeNeedsASecondHandTest]])।
     *
     * ⓘ এক তারিখ, কয়েকটা খাত — একসাথে। ⭐ কিছুই সাথে সাথে বসে না: প্রস্তাবটা একটা অনুরোধে জমা থাকে ("সইয়ের অপেক্ষায়"), আর
     * আরেকজন "সই দিন" চাপলে তবে বসে ([[applySigned()]], [[FinishTheSalaryChangeOnTheLastSignature]]) — SOX/ITGC maker-checker।
     * ⛔ মালিকের ব্যতিক্রম (a6, ২১ অক্টোবর ২০২৬): কোম্পানির ছক (`hr` · `salary_change`) না থাকলে, বা UB-র মতো সব বন্ধ থাকলেও,
     * যন্ত্রের নিজের "দ্বিতীয় সই" ([[ApprovalRules::secondHand()]]) — `hr.salary.manage` আর `hr.salary.view` থাকা আরেকজন। ছক কোডে
     * বসানো হয় না। ⓘ `null` ফেরে কেবল তখন, যখন কোনো নিয়মই ধরে না (মডিউলের ঘোষণা সরালে) — তখন আগের মতো সাথে সাথে।
     *
     * ⛔ অনুরোধে অঙ্কটা `amount` ঘরে নয়, আর `payload`-এ খোলা নয় — এনক্রিপ্ট করা ([[Crypt]])। ⓘ `amount` ইনবক্স আর অনুমোদনের
     * রিপোর্টে ছাপে, `payload` ইনবক্সের খোঁজায় LIKE হয় আর অনুরোধের অডিট-সারিতে যায় — দুটোই বেতনের চাবি ছাড়া মানুষের চোখে
     * পড়ত (§৮ ⛔১-এর একই ফুটো)। অঙ্ক না থাকায় নিজের অনুরোধে নিজে সই দেওয়া যায় না ([[ApprovalEngine::canDecide()]]: অঙ্ক না
     * জানলে সীমার নিচে কি না তাও জানা নেই)।
     *
     * @param  array<int|string, mixed>  $amounts  খাতের id => অঙ্ক; খালি ঘর মানে "এই খাতে কিছু নয়"
     * @return Approval|null `null` = বসে গেছে; নাহলে যে অনুরোধ সইয়ের অপেক্ষায়
     *
     * @throws ValidationException বেতন হয়ে যাওয়া মাস, অঙ্কের ভুল, বা আগের একটা বদল এখনো সইয়ের অপেক্ষায়
     */
    public function change(Employee $employee, string $effectiveFrom, array $amounts): ?Approval
    {
        $heads = SalaryHead::query()->active()->get()->keyBy('id');
        $lines = [];

        foreach ($amounts as $headId => $amount) {
            $head = $heads->get((int) $headId);

            // খালি ঘর মানে "এই খাতে কিছু নয়" — শূন্য বসানো নয়, কারণ শূন্য বসালে পরের মাসেও খাতটা শূন্য হয়ে টিকে থাকত
            if ($head === null || blank($amount)) {
                continue;
            }

            $this->assertAmountIsSane($head, (string) $amount);
            $lines[(int) $head->id] = (string) $amount;
        }

        if ($lines === []) {
            return null;
        }

        $date = Carbon::parse($effectiveFrom)->toDateString();
        $this->assertMonthOpen($employee, $date);

        $engine = app(ApprovalEngine::class);

        if (! $engine->requires(self::MODULE, self::ACTION_CHANGE, null, class_basename($employee))) {
            $this->apply($employee, $date, $lines, null);

            return null;
        }

        $approval = $engine->request(
            $employee,
            self::MODULE,
            self::ACTION_CHANGE,
            amount: null,
            payload: ['proposal' => Crypt::encryptString(json_encode(['effective_from' => $date, 'lines' => $lines], JSON_THROW_ON_ERROR))],
            // ⓘ কারণের লেখায় কেবল তারিখ — অনুরোধের কারণ নিরীক্ষকও পড়েন
            reason: __('hr::message.salary_change_asked', ['date' => $date]),
            // ⓘ শর্ত মাপার ঘর নেই — এনক্রিপ্ট করা লেখার উপর শর্ত বসানোর মানে নেই
            matchOn: [],
        );

        // ⓘ দুই চাপের মাঝে ছকটা বন্ধ হলো — তখন আগের মতোই সাথে সাথে
        if ($approval === null) {
            $this->apply($employee, $date, $lines, null);

            return null;
        }

        /*
         * ⛔ এই কর্মীর আরেকটা বদল আগে থেকেই সইয়ের অপেক্ষায় (বা একই মুহূর্তে আরেকজন আগে চাপলেন) — ইঞ্জিন তালা দিয়ে খুঁজে সেটাই
         * ফেরায় ([[ApprovalEngine::request()]]), নতুন সারি বানায় না; না থামলে এই প্রস্তাবটা নীরবে হারাত আর পর্দা বলত "অপেক্ষায়"।
         */
        if (! $approval->wasRecentlyCreated) {
            throw ValidationException::withMessages(['amounts' => __('hr::validation.salary_change_waiting')]);
        }

        return $approval;
    }

    /** ⓘ এই কর্মীর যে বদল এখন সইয়ের অপেক্ষায় — না থাকলে `null`। */
    public function waitingChange(Employee $employee): ?Approval
    {
        return Approval::query()
            ->where('approvable_type', $employee::class)
            ->where('approvable_id', $employee->getKey())
            ->where('action', self::ACTION_CHANGE)
            ->pending()
            ->latest('id')
            ->first();
    }

    /**
     * ⓘ অনুরোধে জমা প্রস্তাব — পড়া না গেলে (চাবি বদলেছে, সারি ভাঙা) `null`।
     *
     * @return array{effective_from: string, lines: array<int, string>}|null
     */
    public function proposalOf(Approval $approval): ?array
    {
        $sealed = ((array) ($approval->payload ?? []))['proposal'] ?? null;

        if (! is_string($sealed)) {
            return null;
        }

        try {
            $proposal = json_decode(Crypt::decryptString($sealed), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (! is_array($proposal) || ! is_string($proposal['effective_from'] ?? null) || ! is_array($proposal['lines'] ?? null)) {
            return null;
        }

        $lines = [];

        foreach ($proposal['lines'] as $headId => $amount) {
            $lines[(int) $headId] = (string) $amount;
        }

        return ['effective_from' => $proposal['effective_from'], 'lines' => $lines];
    }

    /**
     * ⭐ শেষ সই পড়ল — প্রস্তাবটা এবার বসে, যিনি চেয়েছিলেন তাঁর নামে ([[FinishTheSalaryChangeOnTheLastSignature]])।
     *
     * ⛔ সই আর বসানোর মাঝে সেই মাসের বেতন নিশ্চিত হয়ে গেলে বসে না — সই মানুষের সিদ্ধান্ত, তা ফেরত যায় না, কিন্তু দেওয়া হয়ে
     * যাওয়া মাস বদলায় না; কর্মীর অডিটে কারণসহ একটা সারি বসে ("সই পেয়েও বসেনি"), আর HR নতুন তারিখে আবার চান।
     * ⓘ একটা সই একবারই খাটে ([[Approval::markUsed()]])।
     */
    public function applySigned(Approval $approval): void
    {
        if ($approval->status !== Approval::APPROVED || $approval->action !== self::ACTION_CHANGE || $approval->isUsed()) {
            return;
        }

        $employee = $approval->approvable;
        $proposal = $this->proposalOf($approval);

        if (! $employee instanceof Employee || $proposal === null) {
            return;
        }

        try {
            DB::transaction(function () use ($approval, $employee, $proposal) {
                $this->apply($employee, $proposal['effective_from'], $proposal['lines'],
                    $approval->requested_by === null ? null : (int) $approval->requested_by);
                $approval->markUsed();

                /*
                 * ⭐ প্রতিটা সই-পাওয়া বদলের নিজের অডিট-সারি, অঙ্ক ছাড়া — একসাথে দশটায় সই দিলেও দশটা সারি (fe-র উত্তর ৪, a6, ২১ অক্টোবর
                 * ২০২৬)। ⓘ অঙ্কগুলো কাঠামোর সারির নিজের `created`/`updated` অডিটে, বেতনের চাবিতে ঢাকা ([[FieldSecurity]])।
                 */
                $employee->auditAction('salary_change_signed', __('hr::message.salary_change_signed', [
                    'date' => $proposal['effective_from'],
                    'approval' => $approval->id,
                ]));
            });
        } catch (ValidationException $e) {
            $employee->auditAction('salary_change_late', __('hr::message.salary_change_late', [
                'reason' => (string) collect($e->errors())->flatten()->first(),
            ]));
        }
    }

    /**
     * একটা খাতে নতুন অঙ্ক বসানো — নির্দিষ্ট তারিখ থেকে।
     *
     * একই তারিখে দ্বিতীয়বার বসালে সেটাই সংশোধন, দ্বিতীয় সারি নয়:
     * এক দিনে এক খাতে দুইটা অঙ্ক থাকলে কোনটা চলছে তা বলার উপায় থাকত না।
     *
     * ⓘ সংশোধন নীরব নয়: সারিটা [[IsAudited]], তাই পুরনো আর নতুন অঙ্ক `updated` সারিতে বসে — আর সেই অঙ্ক কেবল
     * `hr.salary.view`-এর চোখে ([[FieldSecurity]], Hr `section_guarded_fields`)। ভিন্ন তারিখে বসালে নতুন সারি, পুরনোটা থাকে।
     *
     * ⛔ বেতন হয়ে যাওয়া মাসে নয় — §৮ ⚠️৫, a6, ২১ অক্টোবর ২০২৬ ([[assertMonthOpen()]])। আগে আগস্টের বেতন দেওয়ার পর
     * "১৫ জানুয়ারি থেকে" অঙ্কটা বদলালে আগস্টের বেতনশিট এক অঙ্কে দেওয়া, অথচ কাঠামো বলত আরেক — আর পুরনো অঙ্কটা কাঠামো থেকে
     * মুছে যেত।
     *
     * ⚠️ এটা বসানোর ধাপ, সইয়ের প্রশ্ন এখানে নেই — মানুষের দরজা [[change()]]।
     *
     * @param  ?int  $by  ⓘ কার নামে বসবে — সইয়ের পরে যিনি চেয়েছিলেন; না দিলে চলতি মানুষ
     */
    public function set(Employee $employee, SalaryHead $head, string $effectiveFrom, string $amount, ?int $by = null): SalaryStructure
    {
        $this->assertAmountIsSane($head, $amount);
        $this->assertMonthOpen($employee, Carbon::parse($effectiveFrom)->toDateString());

        return DB::transaction(function () use ($employee, $head, $effectiveFrom, $amount, $by) {
            $row = SalaryStructure::query()->updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'salary_head_id' => $head->id,
                    'effective_from' => Carbon::parse($effectiveFrom)->toDateString(),
                ],
                [
                    'amount' => $amount,
                    'created_by' => $by ?? auth()->id(),
                ],
            );

            return $row->fresh();
        });
    }

    /**
     * ⛔ এই তারিখের মাস বা তার পরের কোনো মাসের বেতন এই কর্মীর জন্য নিশ্চিত হয়ে থাকলে থামা ([[PaidMonth]])।
     *
     * ⓘ "তার পরের"ও, কারণ অঙ্কটা তারিখ থেকে সামনের সব মাসে খাটে: আগস্ট দেওয়া হয়ে গেলে জুলাইয়ের তারিখের বদলও আগস্টের
     * বেতনশিটকে মিথ্যা করত। ⓘ কেবল যে রানে এই কর্মী আছেন — নতুন কর্মী আগের রানে ছিলেন না।
     */
    private function assertMonthOpen(Employee $employee, string $date): void
    {
        PaidMonth::assertOpen(Carbon::parse($date), null, 'effective_from', 'hr::validation.salary_month_paid', (int) $employee->id);
    }

    /**
     * এক তারিখের সব খাত — এক লেনদেনে।
     *
     * @param  array<int, string>  $lines
     */
    private function apply(Employee $employee, string $date, array $lines, ?int $by): void
    {
        $heads = SalaryHead::query()->whereKey(array_keys($lines))->get()->keyBy('id');

        DB::transaction(function () use ($employee, $date, $lines, $by, $heads) {
            foreach ($lines as $headId => $amount) {
                $head = $heads->get($headId);

                if ($head !== null) {
                    $this->set($employee, $head, $date, $amount, $by);
                }
            }
        });
    }

    /**
     * প্রতিটা খাতের সর্বশেষ কার্যকর সারি।
     *
     * ── কেন "এই তারিখ বা তার আগের সর্বশেষ" ──────────────────────────
     * বেতন প্রতি মাসে বসানো হয় না। জানুয়ারিতে কাঠামো বসিয়ে জুনে
     * বেতনশিট বানালে জুনের কোনো সারি নেই — কিন্তু জানুয়ারিরটাই তো
     * চলছে। ঠিক মিল খুঁজলে ওই কর্মীর বেতনই শূন্য দেখাত।
     *
     * @return list<SalaryStructure>
     */
    private function effectiveRows(Employee $employee, Carbon $date): array
    {
        $rows = SalaryStructure::query()
            ->with('salaryHead')
            ->where('employee_id', $employee->id)
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get();

        $latest = [];

        // ক্রম অনুযায়ী হাঁটা, তাই পরেরটা আগেরটাকে চাপা দেয় — খাত প্রতি
        // একটাই সারি টিকে থাকে, আর সেটাই সর্বশেষ কার্যকরটা
        foreach ($rows as $row) {
            if ($row->salaryHead !== null && $row->salaryHead->is_active) {
                $latest[$row->salary_head_id] = $row;
            }
        }

        return array_values($latest);
    }

    /**
     * শতাংশের খাতে ১০০-র বেশি, বা যেকোনো খাতে ঋণাত্মক অঙ্ক নয়।
     *
     * ঋণাত্মক কর্তন আসলে একটা ভাতা, আর ঋণাত্মক ভাতা একটা কর্তন — দুইটাই
     * বইকে উল্টো করে দেয়, অথচ সংখ্যাটা দেখতে নিরীহ।
     */
    private function assertAmountIsSane(SalaryHead $head, string $amount): void
    {
        if (bccomp($amount, '0', 4) < 0) {
            throw ValidationException::withMessages([
                'amount' => __('hr::validation.amount_cannot_be_negative'),
            ]);
        }

        if ($head->calculation === SalaryHead::PERCENT_OF_BASIC && bccomp($amount, '100', 4) > 0) {
            throw ValidationException::withMessages([
                'amount' => __('hr::validation.percent_out_of_range'),
            ]);
        }
    }
}

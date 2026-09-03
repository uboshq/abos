<?php

declare(strict_types=1);

namespace App\Core\Engines\NumberSeries;

use App\Core\Services\NumberSeriesProvisioner;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\FinancialYear;
use App\Models\IssuedNumber;
use App\Models\NumberSeries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ডকুমেন্ট নম্বর ইস্যু — প্ল্যান সেকশন ২.২, দ্বিতীয় engine।
 *
 * পুরো ব্যাপারটার একটাই কঠিন অংশ: দুইজন একই মুহূর্তে বিল করলে দুইজনকে দুইটা
 * আলাদা নম্বর দিতে হবে। "পরের নম্বর পড়ো, এক বাড়িয়ে লেখো" — এই সরল কাজটাই
 * সমান্তরালে ভুল হয়, কারণ দুইজনেই একই মান পড়ে ফেলে।
 *
 * সমাধান lockForUpdate(): প্রথমজন সিরিজের রো-টা লক করে, দ্বিতীয়জন সেই লক
 * ছাড়ার জন্য অপেক্ষা করে, তারপর হালনাগাদ মান পড়ে। ধীর, কিন্তু নম্বর ইস্যু
 * সেকেন্ডে কয়েকবারের বেশি হয় না — আর দুইটা ইনভয়েসে একই নম্বর বসার খরচ
 * এই অপেক্ষার চেয়ে অনেক বেশি।
 */
final class NumberSeriesEngine
{
    public function __construct(
        private readonly NumberSeriesProvisioner $provisioner,
    ) {}

    /**
     * শাখা ও অর্থবছরের নাম — একবার দেখে মনে রাখা, প্রতি সারিতে নয়।
     *
     * ── কেন ─────────────────────────────────────────────────────────
     * preview() প্রতিটা সিরিজের নমুনা নম্বর বানায়, আর নমুনায় শাখার কোড
     * ও অর্থবছরের নাম বসে। সেটিংসের পাতায় ২৪টা সিরিজ পাশাপাশি দেখানো
     * হয় — সবগুলোই একই শাখার, একই বছরের। তবু প্রতিটা সারির জন্য আলাদা
     * করে খোঁজা হত, আর পাতাটা ৬১টা কোয়েরি চালাত যার ২৪টাই ছিল হুবহু
     * একই প্রশ্ন।
     *
     * মনে রাখাটা শুধু এই রিকোয়েস্টের জন্য — ইঞ্জিনের বস্তুটা রিকোয়েস্ট
     * শেষে মরে যায়। তাই কেউ শাখার কোড বদলালে পরের পাতাতেই নতুনটা দেখা
     * যাবে; বাসি তথ্য জমে থাকার জায়গা নেই।
     *
     * @var array<int, ?string>
     */
    private array $branchCodes = [];

    /** @var array<int, ?FinancialYear> */
    private array $financialYears = [];

    /**
     * পরের নম্বর নাও ও ধরে রাখো।
     *
     * ডকুমেন্ট সেভ হওয়ার ট্রানজেকশনের ভেতরেই ডাকতে হবে — বাইরে ডাকলে
     * ডকুমেন্ট সেভ ব্যর্থ হলেও নম্বরটা খরচ হয়ে যাবে।
     */
    public function next(
        string $docType,
        ?int $branchId = null,
        ?Carbon $date = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): string {
        $companyId = CompanyContext::id();

        if ($companyId === null) {
            throw new RuntimeException('Cannot issue a document number without a company in context.');
        }

        $date = $date ?? Carbon::today();
        $branchId = $branchId ?? CompanyContext::branchId();
        $financialYear = FinancialYear::forDate($date);

        return DB::transaction(function () use ($companyId, $docType, $branchId, $financialYear, $date, $sourceType, $sourceId) {
            $series = $this->lockSeries($companyId, $docType, $branchId, $financialYear?->id);

            $sequence = $series->next_number;
            $documentNo = $this->format($series, $sequence, $branchId, $financialYear, $date);

            $series->next_number = $sequence + 1;
            $series->save();

            IssuedNumber::create([
                'company_id' => $companyId,
                'number_series_id' => $series->id,
                'document_no' => $documentNo,
                'sequence' => $sequence,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'issued_by' => auth()->id(),
                'issued_at' => now(),
            ]);

            return $documentNo;
        });
    }

    /**
     * একটা ইস্যু করা নম্বর বাতিল হয়েছে বলে চিহ্নিত করা।
     *
     * নম্বরটা পুনরায় ব্যবহার করা হয় না — গুরুত্বপূর্ণ সিদ্ধান্ত। পুনর্ব্যবহার
     * করলে নিরীক্ষায় একই নম্বরের দুইটা কাগজ পাওয়া যাবে, একটা বাতিল আর একটা
     * বৈধ, এবং কোনটা কোনটা তা প্রমাণ করা যাবে না।
     */
    public function void(string $documentNo, string $reason): void
    {
        $issued = IssuedNumber::query()->where('document_no', $documentNo)->first();

        if ($issued === null) {
            throw new RuntimeException("Document number {$documentNo} was never issued.");
        }

        $issued->update(['is_voided' => true, 'void_reason' => $reason]);
    }

    /** ফাঁক আছে কি না — নিরীক্ষায় প্রথম যে প্রশ্নটা আসে। */
    public function gaps(string $docType, ?int $branchId = null, ?int $financialYearId = null): array
    {
        $series = $this->findSeries(CompanyContext::id(), $docType, $branchId, $financialYearId);

        if ($series === null) {
            return [];
        }

        $issued = IssuedNumber::query()
            ->where('number_series_id', $series->id)
            ->orderBy('sequence')
            ->pluck('sequence')
            ->all();

        $gaps = [];
        $expected = $series->start_number;

        foreach ($issued as $sequence) {
            while ($expected < $sequence) {
                $gaps[] = $expected;
                $expected++;
            }
            $expected = $sequence + 1;
        }

        return $gaps;
    }

    private function lockSeries(int $companyId, string $docType, ?int $branchId, ?int $financialYearId): NumberSeries
    {
        $series = NumberSeries::query()
            ->where('company_id', $companyId)
            ->where('doc_type', $docType)
            ->where('branch_id', $branchId)
            ->where('financial_year_id', $financialYearId)
            ->lockForUpdate()
            ->first();

        if ($series !== null) {
            return $series;
        }

        // শাখাভিত্তিক সিরিজ না থাকলে কোম্পানি-ব্যাপী সিরিজে নামা — অনেক
        // প্রতিষ্ঠান শাখা আলাদা করে না, আর তাদের জন্য প্রতিটা শাখায় সিরিজ
        // বানাতে বাধ্য করার কোনো কারণ নেই।
        if ($branchId !== null) {
            $series = NumberSeries::query()
                ->where('company_id', $companyId)
                ->where('doc_type', $docType)
                ->whereNull('branch_id')
                ->where('financial_year_id', $financialYearId)
                ->lockForUpdate()
                ->first();

            if ($series !== null) {
                return $series;
            }
        }

        /*
         * ধরনটা ঘোষিত অথচ সিরিজ নেই — নিজে বসিয়ে নাও।
         *
         * ── কেন এটা লাগল ───────────────────────────────────────────────
         * সিরিজগুলো বসে কোম্পানি তৈরির সময়, ওই মুহূর্তে যত ডকুমেন্ট টাইপ
         * ঘোষিত ছিল তত। কিন্তু নতুন ফিচার নতুন টাইপ আনে — ঋণ এল 'LN'
         * নিয়ে। যে কোম্পানিগুলো তার আগে তৈরি, তাদের জন্য 'LN'-এর কোনো
         * সিরিজ কখনোই বসত না, আর প্রথম ঋণ সেভ করতে গেলেই এই ব্যতিক্রম
         * উঠত — পর্দায় সোজা ৫০০। পরীক্ষক ঠিক তা-ই পেয়েছেন।
         *
         * ব্যবহারকারীর দিক থেকে এটা অর্থহীন: তিনি "মাস্টার ডাটা → নম্বর
         * সিরিজ"-এ গিয়ে কী বসাবেন, যখন ফিচারটা কালই যোগ হয়েছে? তাঁর
         * কোনো ভুল নেই, শুধু কোম্পানিটা পুরনো।
         *
         * তাই ঘোষিত ধরনের জন্য সিরিজটা এখানেই তৈরি হয় — একই ছক, একই
         * উপসর্গ, যা কোম্পানি তৈরির সময় হত। অঘোষিত ধরন এখনো ব্যতিক্রম
         * ছোড়ে: 'PBL' লিখতে গিয়ে 'PLB' লিখলে সেটা নীরবে একটা নতুন সিরিজ
         * বানিয়ে ফেলা সবচেয়ে খারাপ ফল হত।
         */
        if ($this->provisioner->knows($docType)) {
            $this->provisioner->provision(
                $financialYearId === null ? null : FinancialYear::query()->find($financialYearId),
            );

            $series = $this->findSeries($companyId, $docType, null, $financialYearId);

            if ($series !== null) {
                // সদ্য তৈরি সারিটা লক করে ফেরত — নইলে দুইটা অনুরোধ একই
                // নম্বর পেত
                return NumberSeries::query()->whereKey($series->id)->lockForUpdate()->firstOrFail();
            }
        }

        throw new RuntimeException(
            "No number series is configured for document type '{$docType}'. "
            .'Set it up in Master Data → Document Number Series before creating this document.'
        );
    }

    private function findSeries(?int $companyId, string $docType, ?int $branchId, ?int $financialYearId): ?NumberSeries
    {
        return NumberSeries::query()
            ->where('company_id', $companyId)
            ->where('doc_type', $docType)
            ->where('branch_id', $branchId)
            ->where('financial_year_id', $financialYearId)
            ->first();
    }

    /**
     * পরের নম্বরটা দেখতে কেমন হবে — কিছু খরচ না করে।
     *
     * সেটিংসের পর্দায় ছক বদলানোর সময় এটাই দেখানো হয়। আগে ওই পর্দাটা
     * নিজে হাতে "{prefix}-{বছর}-{ক্রম}" জুড়ে দেখাত, অথচ ছকটা অন্য কিছু
     * হলে নমুনাটা মিথ্যা বলত — ব্যবহারকারী একটা জিনিস দেখে সেভ করতেন
     * আর ডকুমেন্টে বসত অন্যটা।
     *
     * এখন নমুনা আর আসল নম্বর একই কোড থেকে আসে, তাই দুইটা আলাদা হতে
     * পারে না।
     */
    /**
     * পর্দা যে নম্বরটা আগে থেকে বসিয়ে রেখেছিল, সেটাই কি সিরিজের পরেরটা?
     *
     * ── কেন এই প্রশ্নটা দরকার হলো (৩ সেপ্টেম্বর ২০২৬) ────────────────
     * ⚠️ কাউন্টারের পর্দায় বিলের নম্বরের ঘরটা **আগে থেকে ভরা থাকে**,
     * `preview()` দিয়ে — আর ওটা সিরিজের পরের নম্বর। ব্যবহারকারী কিছু না
     * বদলে সেভ করলে নম্বরটা "হাতে লেখা" হিসেবে গণ্য হত, তাই **সিরিজ এক
     * ধাপও এগোত না**।
     *
     * ফল দিনের **দ্বিতীয় বিক্রয়েই**: পর্দা আবার সেই একই নম্বর বসাত।
     * ঘরটা না ছুঁলে পড়া-যায় এমন বার্তা আসত ("নম্বরটা আগেই বসে গেছে"),
     * আর ঘরটা খালি করলে `next()` ওই একই নম্বর ফিরিয়ে দিত — **ডাটাবেসের
     * ইউনিক ইনডেক্সে ৫০০**। মাপা হয়েছে: `DC` ও `COL` সিরিজ এগোচ্ছিল,
     * `INV` এক জায়গায় দাঁড়িয়ে ছিল।
     *
     * ── কেন "হাতে লেখা নম্বরে সিরিজ এগোয় না" নিয়মটা ঠিকই আছে ─────────
     * ওটা ইচ্ছাকৃত, আর দরকারি: কেউ পুরনো কাগজের নম্বর বসালে সিরিজে
     * ফাঁক পড়া উচিত নয়। ⭐ ভুলটা ছিল **পর্দার ভরে রাখা নম্বরকেও "হাতে
     * লেখা" ভাবা** — ওটা তো সিরিজেরই নম্বর, কেবল আগেভাগে দেখানো।
     *
     * ⓘ তুলনাটা তৈরি স্ট্রিং ধরে, ক্রম-সংখ্যা ধরে নয় — ছকে শাখা বা
     * বছর থাকতে পারে, আর তখন কেবল সংখ্যা মিলিয়ে সিদ্ধান্ত নেওয়া ভুল হত।
     */
    public function isNextNumber(string $docType, string $documentNo, ?int $branchId = null, ?Carbon $date = null): bool
    {
        $companyId = CompanyContext::id();

        if ($companyId === null || trim($documentNo) === '') {
            return false;
        }

        $date = $date ?? Carbon::today();
        $branchId = $branchId ?? CompanyContext::branchId();
        $financialYear = FinancialYear::forDate($date);

        $series = $this->findSeries($companyId, $docType, $branchId, $financialYear?->id)
            ?? $this->findSeries($companyId, $docType, null, $financialYear?->id)
            ?? $this->findSeries($companyId, $docType, $branchId, null)
            ?? $this->findSeries($companyId, $docType, null, null);

        return $series !== null && $this->preview($series) === trim($documentNo);
    }

    public function preview(NumberSeries $series, ?int $sequence = null): string
    {
        return $this->format(
            $series,
            $sequence ?? $series->next_number,
            $series->branch_id,
            $series->financial_year_id !== null
                ? $this->financialYear($series->financial_year_id)
                : null,
            Carbon::today(),
        );
    }

    /**
     * শাখাটা না থাকলেও মনে রাখা হয় (null হিসেবে)।
     *
     * array_key_exists দিয়ে দেখা হয়, isset দিয়ে নয় — isset null-কে
     * "নেই" ধরত, আর মুছে ফেলা একটা শাখার জন্য প্রতিবার নতুন করে খোঁজা
     * চলত, ঠিক যে সমস্যাটা এড়াতে এটা বসানো।
     */
    private function branchCode(int $branchId): ?string
    {
        if (! array_key_exists($branchId, $this->branchCodes)) {
            $this->branchCodes[$branchId] = Branch::query()->find($branchId)?->code;
        }

        return $this->branchCodes[$branchId];
    }

    private function financialYear(int $yearId): ?FinancialYear
    {
        if (! array_key_exists($yearId, $this->financialYears)) {
            $this->financialYears[$yearId] = FinancialYear::query()->find($yearId);
        }

        return $this->financialYears[$yearId];
    }

    private function format(
        NumberSeries $series,
        int $sequence,
        ?int $branchId,
        ?FinancialYear $financialYear,
        Carbon $date,
    ): string {
        $branchCode = $branchId !== null
            ? ($this->branchCode($branchId) ?? '')
            : '';

        return str_replace(
            ['{PREFIX}', '{SUFFIX}', '{BRANCH}', '{FY}', '{YYYY}', '{YY}', '{MM}', '{SEQ}'],
            [
                $series->prefix,
                $series->suffix,
                $branchCode,
                $financialYear?->name ?? '',
                $date->format('Y'),
                $date->format('y'),
                $date->format('m'),
                str_pad((string) $sequence, $series->padding, '0', STR_PAD_LEFT),
            ],
            $series->format,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Engines\NumberSeries;

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

    private function format(
        NumberSeries $series,
        int $sequence,
        ?int $branchId,
        ?FinancialYear $financialYear,
        Carbon $date,
    ): string {
        $branchCode = $branchId !== null
            ? (Branch::query()->find($branchId)?->code ?? '')
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

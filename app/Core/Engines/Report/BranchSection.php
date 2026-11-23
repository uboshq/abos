<?php

declare(strict_types=1);

namespace App\Core\Engines\Report;

/**
 * রিপোর্টের এক শাখার অংশ — "সব শাখা"-তে শাখা ধরে ভাগ (মালিকের নির্দেশ,
 * ২৯ সেপ্টেম্বর ২০২৬: *"সব রিপোর্ট শাখাভিত্তিক আলাদা করে দেখাবে … সাথে Grand Total"*)।
 *
 * ⓘ `rows` আর `totals`-এর আকার [[ReportResult]]-এর নিজের `rows`/`totals`-এর হুবহু —
 * তাই পর্দা আর ছাপা একই কোড দিয়ে আঁকে। `branchId` null = শাখাহীন দল
 * (প্রধান অফিস / কোনো শাখা নয়)।
 */
final class BranchSection
{
    /**
     * @param  list<array<string, mixed>>  $rows  এই শাখার সারি — প্রথম পাতা
     * @param  array<string, string>  $totals  এই শাখার মোট — পুরো অংশের, পাতার নয়
     * @param  string|null  $totalLabel  ⭐ ভাগের মোটের নিজের লেখা — রিপোর্টের নিজের ভাগ চাইলে ("দল মোট — করিম"; মালিক, ২৩ নভেম্বর
     *                                   ২০২৬, [[SalespersonReports]])। ⓘ null = আগের মতো "শাখার মোট/উপ-মোট — নাম"।
     */
    public function __construct(
        public readonly ?int $branchId,
        public readonly string $branchName,
        public readonly array $rows,
        public readonly array $totals,
        public readonly int $rowCount,
        public readonly ?string $totalLabel = null,
    ) {}
}

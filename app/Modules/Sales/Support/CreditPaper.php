<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ দেয়াল কোন কাগজের জন্য দাঁড়িয়েছে — সাক্ষ্যের সারির জন্য ([[CreditExposure]], [[CreditDecision]])।
 *
 * ⓘ দেয়াল নিজে কাগজ চেনে না, কেবল অঙ্ক; যে সেবা ডাকে সে-ই বলে কোন কাগজ। ⓘ না বললে (পর্দার আগাম হিসাব,
 * [[SalesPaperOverview]]) কোনো সারি লেখা হয় না — সেটা সিদ্ধান্ত নয়, ছবি।
 */
final class CreditPaper
{
    private function __construct(
        public readonly string $stage,
        public readonly string $type,
        public readonly ?int $id,
        public readonly ?string $no,
        public readonly ?int $branchId,
    ) {}

    public static function of(string $stage, Model $paper): self
    {
        return new self(
            stage: $stage,
            type: $paper->getTable(),
            id: $paper->getKey() === null ? null : (int) $paper->getKey(),
            no: $paper->getAttribute('document_no') === null ? null : (string) $paper->getAttribute('document_no'),
            branchId: $paper->getAttribute('branch_id') === null ? null : (int) $paper->getAttribute('branch_id'),
        );
    }

    /** কাউন্টারের ভিতরে থাকলে চালান-বিলের সিদ্ধান্তও "কাউন্টার" ([[CreditExposure::atCounter()]]) */
    public function at(string $stage): self
    {
        return new self($stage, $this->type, $this->id, $this->no, $this->branchId);
    }
}

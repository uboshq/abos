<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Approval\OwnerSelfApproval;
use App\Core\Engines\Report\ReportEngine;
use App\Models\Approval;
use App\Modules\Accounts\Reports\OwnerSelfApprovedReport;

/**
 * ⭐ মালিক নিজের অনুরোধে নিজে অনুমোদন দিলে স্থায়ী দাগ আর মাসের রিপোর্টে একটা সারি; অন্য কেউ দিলে কোনোটাই নয়
 * (মালিক, ২০ নভেম্বর ২০২৬; [[OwnerSelfApproval]])।
 */
trait SeesTheOwnersOwnMark
{
    protected function assertOwnerSelfMarked(Approval $approval): void
    {
        $approval = Approval::query()->withoutGlobalScopes()->findOrFail($approval->getKey());
        $this->assertSame(Approval::APPROVED, (string) $approval->status, '⛔ মালিকের নিজের অনুমোদন বসল না।');
        $marks = app(OwnerSelfApproval::class);
        $this->assertTrue($marks->ofApproval($approval), '⛔ অনুরোধে মালিকের দাগ নেই।');
        $this->assertTrue($marks->ofPaper($approval->approvable), '⛔ কাগজে মালিকের দাগ নেই।');
        $this->assertSame(1, $this->ownerRows($approval), '⛔ মাসের রিপোর্টে কাগজটার একটা সারি নেই।');
    }

    protected function assertNotOwnerSelfMarked(Approval $approval): void
    {
        $approval = Approval::query()->withoutGlobalScopes()->findOrFail($approval->getKey());
        $this->assertFalse(app(OwnerSelfApproval::class)->ofApproval($approval), '⛔ অন্যের অনুমোদনে মালিকের দাগ।');
        $this->assertSame(0, $this->ownerRows($approval), '⛔ অন্যের অনুমোদন মালিকের রিপোর্টে।');
    }

    private function ownerRows(Approval $approval): int
    {
        $paper = $approval->approvable;
        $number = (string) ($paper->getAttribute('document_no') ?? '#'.$paper->getKey());
        $type = $paper->getAttribute('document_no') === null
            ? app(\App\Core\Engines\Approval\ApprovalLabel::class)->of((string) $approval->module, (string) $approval->action) : null;
        $rows = app(ReportEngine::class)->run(OwnerSelfApprovedReport::KEY,
            ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()], 1, 200)->rows;

        return collect($rows)->filter(function ($r) use ($number, $type): bool {
            $r = (array) $r;

            return (string) $r['document_no'] === $number && ($type === null || (string) $r['paper_type'] === $type);
        })->count();
    }
}

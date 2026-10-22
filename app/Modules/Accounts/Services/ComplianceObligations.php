<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Services\SettingsService;
use App\Core\Support\Money;
use App\Models\User;
use App\Modules\Accounts\Models\ComplianceObligation;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ করণীয় — আইনের দায় বসানো, বাতিল আর "করা হয়েছে" (টাকা দেওয়ার মিল-তালিকা #৩; fe, ২২ অক্টোবর ২০২৬)।
 *
 * ⓘ যে মডিউল দায় জন্মায় সে এটাকে ডাকে — আজ ক্রয়ের পরিশোধ (কাটা AIT/VDS), পরে বিক্রয় (a6, `mushak_6_6_receive`)। কাগজ চেনা হয়
 * `source_type` + `source_id` দিয়ে, তাই এই ক্লাস কোনো মডিউলের কাগজ চেনে না।
 *
 * ⛔ একই কাগজের একই দায় একবারই — (কোম্পানি, উৎস, ধরন, বিষয়) অনন্য, আর [[raise()]] আগেরটাই ফেরায়। দুবার পোস্ট বা একই ঘটনা দুবার
 * এলেও দুটো সারি নয়।
 */
final class ComplianceObligations
{
    /** কোন দায়ের শেষ তারিখ কোন সেটিং থেকে — পরের মাসের কত তারিখ */
    private const DUE_DAY = [
        ComplianceObligation::KOSAGAR_DEPOSIT.'|'.ComplianceObligation::AIT => 'accounts.tax.ait_deposit_day',
        ComplianceObligation::KOSAGAR_DEPOSIT.'|'.ComplianceObligation::VDS => 'accounts.tax.vds_deposit_day',
        ComplianceObligation::MUSHAK_66_ISSUE.'|'.ComplianceObligation::VDS => 'accounts.tax.mushak_66_issue_day',
    ];

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * দায় বসানো — বা আগে বসানো থাকলে সেটাই ফেরানো।
     *
     * ⓘ অঙ্ক দুই দশমিকে, bcmath-এ ([[Money::round()]]); শূন্য বা ঋণাত্মক অঙ্কে কোনো দায় নেই — তখন `null`।
     */
    public function raise(
        int $companyId,
        ?int $branchId,
        string $sourceType,
        int $sourceId,
        ?string $sourceNo,
        string $kind,
        string $subject,
        string $amount,
        Carbon|string $arisedOn,
    ): ?ComplianceObligation {
        $amount = Money::round($amount, 2);

        if (bccomp($amount, '0', 2) <= 0) {
            return null;
        }

        return ComplianceObligation::query()->firstOrCreate(
            ['company_id' => $companyId, 'source_type' => $sourceType, 'source_id' => $sourceId, 'kind' => $kind, 'subject' => $subject],
            [
                'branch_id' => $branchId,
                'source_no' => $sourceNo,
                'amount' => $amount,
                'due_on' => $this->dueOn($kind, $subject, $arisedOn)->toDateString(),
            ],
        );
    }

    /**
     * ⓘ পরের মাসের নির্দিষ্ট তারিখ — সেটিং থেকে (ডিফল্ট ১৫), ১ থেকে ২৮-এর বাইরে গেলে কিনারায়; মাস ছোট হলে মাসের শেষ দিন।
     */
    public function dueOn(string $kind, string $subject, Carbon|string $arisedOn): Carbon
    {
        $key = self::DUE_DAY[$kind.'|'.$subject] ?? null;
        $day = $key === null ? 15 : (int) $this->settings->get($key, 15);
        $day = max(1, min(28, $day));

        $next = Carbon::parse($arisedOn)->startOfMonth()->addMonthNoOverflow();

        return $next->setDay(min($day, $next->daysInMonth));
    }

    /**
     * কাগজ বাতিল বা চেক ফেরত — খোলা দায় বাতিল। ⓘ করা হয়ে যাওয়া দায় থাকে: জমা সত্যিই হয়েছে, সেটা ফেরত চাওয়া আলাদা কাজ।
     *
     * @return int কয়টা বাতিল হল
     */
    public function cancelFor(string $sourceType, int $sourceId, string $reason): int
    {
        $open = ComplianceObligation::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->open()
            ->get();

        foreach ($open as $obligation) {
            $obligation->update(['cancelled_at' => now(), 'cancel_reason' => mb_substr($reason, 0, 255)]);
            $obligation->auditAction('obligation_cancelled', $reason);
        }

        return $open->count();
    }

    /**
     * "করা হয়েছে" — কে, কবে, কোন চালান বা সনদের নম্বরে; নিরীক্ষায় ওঠে।
     *
     * ⛔ নম্বর ছাড়া নয় (কোষাগারের চালান বা মূসক-৬.৬-এর নম্বরই প্রমাণ), আর কেবল খোলা দায়ে।
     */
    public function markDone(ComplianceObligation $obligation, User $by, string $refNo, ?string $note = null): ComplianceObligation
    {
        $refNo = trim($refNo);

        if ($refNo === '') {
            throw ValidationException::withMessages(['ref_no' => __('accounts::validation.obligation_needs_ref')]);
        }

        if (! $obligation->isOpen()) {
            throw ValidationException::withMessages(['ref_no' => __('accounts::validation.obligation_not_open')]);
        }

        $obligation->update([
            'done_at' => now(),
            'done_by' => $by->id,
            'ref_no' => mb_substr($refNo, 0, 64),
            'note' => filled($note) ? trim((string) $note) : null,
        ]);
        $obligation->auditAction('obligation_done', $refNo);

        return $obligation->fresh();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ListedInViewedBranch;
use App\Core\Contracts\Drillable;
use App\Core\Contracts\SettledByAVoucher;
use App\Core\Contracts\SettlementTerms;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Finance\Services\InsuranceClaimService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ বীমার একটা দাবি — অর্থ-মডিউলের পরিকল্পনা ৬.৪, ৬ অক্টোবর ২০২৬ ([[InsuranceClaimService]])।
 *
 * ── ⭐ খাতায় কখন (সমন্বয়কের উত্তর প্র৩, IAS 37) ─────────────────────────────
 * জমা দেওয়া দাবি একটা সম্ভাব্য সম্পদ — খাতায় নয়, কেবল এই খাতায় (তালিকায়)। খাতায় ওঠে দুই পথে:
 *   · লিখিত অনুমোদন — অনুমোদিত অঙ্ক (যা আগে আসেনি) Dr 1152 বীমা দাবি প্রাপ্য / Cr 4370 বীমা দাবি আদায়
 *   · টাকা এল — রসিদ ভাউচার, এই দাবির বিপরীতে; অনুমোদন খাতায় থাকলে Cr 1152, না থাকলে Cr 4370
 *
 * ⓘ অবস্থা: জমা → অনুমোদিত → আংশিক → নিষ্পন্ন, বা নাকচ। পাওয়া টাকা ভাউচার থেকেই গোনা ([[InsuranceClaimService::refresh()]]),
 * নিজে লেখা হয় না — রসিদ বাতিল হলে নিজেই কমে।
 */
class InsuranceClaim extends Model implements Drillable, SettledByAVoucher, SettlementTerms
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use ListedInViewedBranch;

    public const LODGED = 'lodged';

    public const APPROVED = 'approved';

    public const PARTIAL = 'partial';

    public const SETTLED = 'settled';

    public const REJECTED = 'rejected';

    /** @var list<string> */
    public const STATES = [self::LODGED, self::APPROVED, self::PARTIAL, self::SETTLED, self::REJECTED];

    /** @var list<string> টাকা আসতে পারে এমন অবস্থা */
    public const OPEN = [self::LODGED, self::APPROVED, self::PARTIAL];

    protected $table = 'fin_insurance_claims';

    /** ⓘ নতুন দাবি জমা অবস্থায়, কিছুই পাওয়া যায়নি — কলামের ডিফল্টের একই, মডেলেও যাতে সাথে সাথে পড়া যায় */
    protected $attributes = ['status' => self::LODGED, 'received_amount' => 0];

    protected $fillable = [
        'company_id', 'branch_id', 'policy_id', 'claim_no', 'incident_on', 'claimed_on', 'incident', 'claimed_amount',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'incident_on' => 'date',
            'claimed_on' => 'date',
            'approved_on' => 'date',
            'received_on' => 'date',
            'closed_on' => 'date',
            'claimed_amount' => 'decimal:4',
            'approved_amount' => 'decimal:4',
            'received_amount' => 'decimal:4',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(InsurancePolicy::class, 'policy_id');
    }

    public function approvalVoucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'approval_voucher_id');
    }

    public function closeVoucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'close_voucher_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** কত পেলে দাবি মেটে — অনুমোদন থাকলে অনুমোদিত অঙ্ক, না থাকলে চাওয়া অঙ্ক */
    public function target(): string
    {
        return bcadd((string) ($this->approved_amount ?? $this->claimed_amount), '0', 4);
    }

    /** এখনো যত আসতে পারে */
    public function outstanding(): string
    {
        $left = bcsub($this->target(), (string) $this->received_amount, 4);

        return bccomp($left, '0', 4) > 0 ? $left : '0.0000';
    }

    // ── Drillable — খতিয়ানের সারি থেকে দাবির পাতায় ────────────────────

    public static function drillSourceType(): string
    {
        return 'insurance_claim';
    }

    public function drillDocumentNo(): string
    {
        return (string) ($this->claim_no ?: $this->policy?->policy_no);
    }

    public function drillLabel(): string
    {
        return __('finance::insurance_claim.title').' — '.$this->drillDocumentNo();
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    public function drillRoute(): array
    {
        return ['finance.insurance.claim.show', ['claim' => $this->getKey()]];
    }

    // ── SettlementTerms — কোন রসিদ এই দাবির টাকা আনতে পারে ─────────────

    /**
     * ⓘ রসিদ, যা এখনো আসতে পারে তার বেশি নয় (কয়েক কিস্তিতে আসে); ⛔ নাকচ বা নিষ্পন্ন দাবিতে নয়।
     */
    public function settlementTerms(): array
    {
        return [
            'voucher_type' => 'receipt',
            'amount' => $this->outstanding(),
            'up_to' => true,
            'open' => $this->isOpen() && bccomp($this->outstanding(), '0', 4) > 0,
        ];
    }

    // ── SettledByAVoucher — রসিদ পোস্ট বা বাতিল হলে আবার গোনা ─────────

    /** ⛔ ভুল খাতে টাকা নিলে এখানেই থামে, আর রসিদটাও খাতায় বসে না ([[InsuranceClaimService::refresh()]]) */
    public function settleWith(int $voucherId): void
    {
        app(InsuranceClaimService::class)->refresh($this, including: $voucherId);
    }

    public function unsettle(int $voucherId): void
    {
        app(InsuranceClaimService::class)->refresh($this, excluding: $voucherId);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\Drillable;
use App\Core\Contracts\SettledByAVoucher;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * এক মেয়াদের প্রিমিয়াম — "এই খাতা → পরিশোধ ভাউচার → খতিয়ান"।
 *
 * ⓘ সারিটা টাকা নাড়ে না। "প্রিমিয়াম দিন" বোতাম পরিশোধ ভাউচার খোলে
 * `against_type=insurance_premium` নিয়ে, আর ভাউচার পোস্ট হলে
 * [[App\Modules\Accounts\Services\VoucherService]] `drill_sources` দিয়ে
 * ক্লাসটা খুঁজে [[settleWith()]] ডাকে — পোস্টিংয়ের একই লেনদেনে।
 */
class InsurancePremium extends Model implements Drillable, SettledByAVoucher, \App\Core\Contracts\SettlementTerms
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    protected $table = 'fin_insurance_premiums';

    protected $fillable = [
        'company_id', 'policy_id', 'period_from', 'period_to', 'amount',
        'status', 'voucher_id', 'posted_at', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            // ⭐ চার ঘর, কলামের মাপেই — `decimal:2` প্রতিবার পড়ায় শেষ দুই ঘর ফেলে দিত ([[TheInsuranceLostItsLastTwoPaisaOnEveryReadTest]])
            'amount' => 'decimal:4',
            'posted_at' => 'datetime',
        ];
    }

    /**
     * ⭐ কিস্তির অবস্থা — দেওয়া / দিন পার / আজ / সামনে; দিন = সময়কালের শুরু (পরিকল্পনা ৬.২, [[InsuranceReports::PREMIUMS]])।
     */
    public function dueState(?\Carbon\CarbonInterface $today = null): string
    {
        if ($this->isPaid()) {
            return 'paid';
        }

        $today = ($today ?? now())->toDateString();
        $due = $this->period_from->toDateString();

        return $due < $today ? 'overdue' : ($due === $today ? 'today' : 'upcoming');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(InsurancePolicy::class, 'policy_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function isPaid(): bool
    {
        return $this->status === self::POSTED;
    }

    // ── Drillable — খতিয়ানের সারি থেকে পলিসিতে ফেরা ────────────────────

    public static function drillSourceType(): string
    {
        return 'insurance_premium';
    }

    public function drillDocumentNo(): string
    {
        return (string) $this->policy?->policy_no;
    }

    public function drillLabel(): string
    {
        return __('finance::insurance.title').' — '.$this->policy?->policy_no;
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    public function drillRoute(): array
    {
        return ['finance.insurance.show', ['policy' => $this->policy_id]];
    }

    // ── SettledByAVoucher ────────────────────────────────────────────

    /**
     * ⚠️ `where('status', DRAFT)` — চুক্তি idempotent চায়: বাতিল করে আবার
     * পোস্ট হলে `posted_at` বদলালে "কবে দেওয়া হয়েছিল" তারিখটাই মিথ্যা হত।
     */
    public function settleWith(int $voucherId): void
    {
        static::query()
            ->whereKey($this->getKey())
            ->where('status', self::DRAFT)
            ->update([
                'status' => self::POSTED,
                'voucher_id' => $voucherId,
                'posted_at' => now(),
            ]);

        $this->refresh();
    }

    /**
     * ⚠️ `voucher_id` মেলানো হয়: অন্য কোনো ভাউচারের বাতিল এই সারি খুলতে
     * পারে না।
     */
    public function unsettle(int $voucherId): void
    {
        static::query()
            ->whereKey($this->getKey())
            ->where('status', self::POSTED)
            ->where('voucher_id', $voucherId)
            ->update([
                'status' => self::DRAFT,
                'voucher_id' => null,
                'posted_at' => null,
            ]);

        $this->refresh();
    }

    /**
     * ⭐ গ২ — কোন ভাউচার এই কাগজটা মেটাতে পারে ([[SettlementTerms]], Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬)।
     *
     * ⛔ আগে লুকানো "বিপরীতে" ঘরে যা লেখা হত তা-ই মানা হত — অঙ্ক, পক্ষ, ধরন, অবস্থা কিছুই দেখা হত না।
     */
    public function settlementTerms(): array
    {
        // ⓘ সইয়ের অপেক্ষায় বা ফেরত পাওয়া কাগজ খোলা নয় — সইয়ের পথ ভাউচার দিয়ে এড়ানো যায় না
        $latest = \App\Models\Approval::query()
            ->where('approvable_type', static::class)
            ->where('approvable_id', $this->getKey())
            ->latest('id')
            ->first();
        $signed = $latest === null || $latest->status === \App\Models\Approval::APPROVED;

        return [
            'voucher_type' => 'payment',
            'amount' => (string) $this->amount,
            'open' => $this->status === self::DRAFT && $this->voucher_id === null && $signed,
        ];
    }
}

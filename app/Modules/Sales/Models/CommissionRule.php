<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * এক হার, এক ভূমিকার জন্য, এক ধাপে।
 *
 * ── ধাপগুলো সারি, কলাম নয় ────────────────────────────────────────────
 * পাঁচ লাখ পর্যন্ত ২%, তার উপরে ৩% — **দুইটা সারি**। ধাপ বাড়ে, আর যে
 * গড়নে নতুন ধাপ যোগ করতে মাইগ্রেশন লাগে সেই গড়ন এড়িয়ে লোকে এক্সেলে
 * হিসাব করে।
 *
 * ── হার আর থোক টাকা, দুইটা ঘরই ───────────────────────────────────────
 * চুক্তি দুই রকম হয়। কেবল শতাংশ রাখলে থোক অঙ্কটা প্রতিবার হাতে গুনে
 * বসাতে হত, আর গোনার ভুল সরাসরি টাকার ভুল।
 */
class CommissionRule extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    protected $table = 'sal_commission_rules';

    protected $fillable = [
        'company_id', 'scheme_id', 'earner_role',
        'rate_percent', 'fixed_amount',
        'slab_from', 'slab_to', 'level_order',
    ];

    protected function casts(): array
    {
        return [
            'rate_percent' => 'decimal:4',
            'fixed_amount' => 'decimal:4',
            'slab_from' => 'decimal:4',
            'slab_to' => 'decimal:4',
        ];
    }

    public function scheme(): BelongsTo
    {
        return $this->belongsTo(Scheme::class);
    }

    /**
     * এই অঙ্কটা এই নিয়মের ধাপে পড়ে কি না।
     *
     * ── কেন উপরের সীমাটা "এবং তার নিচে", "তার কম" নয় ────────────────
     * ঠিক পাঁচ লাখ বিক্রি হলে সেটা "পাঁচ লাখ পর্যন্ত" ধাপেই পড়ার কথা।
     * `<` লিখলে ওই একটা অঙ্ক দুই ধাপের কোনোটাতেই পড়ত না, আর ব্যবসার
     * সবচেয়ে সাধারণ সংখ্যাটাই (গোল অঙ্ক) কিছু পেত না।
     */
    public function covers(string $amount): bool
    {
        $aboveFloor = $this->slab_from === null
            || bccomp($amount, (string) $this->slab_from, 4) >= 0;

        $belowCeiling = $this->slab_to === null
            || bccomp($amount, (string) $this->slab_to, 4) <= 0;

        return $aboveFloor && $belowCeiling;
    }

    /**
     * এই ভিত্তির উপর এই নিয়ম কত টাকা দেয়।
     *
     * ── কেন থোক টাকা হারকে হারায় ───────────────────────────────────
     * দুইটাই ভরা থাকলে কোনটা মানা হবে সেটা একবারই ঠিক করা দরকার,
     * নাহলে দুই পর্দায় দুই উত্তর আসত। থোক টাকা জেতে, কারণ ওটা লেখা
     * হয় তখনই যখন চুক্তিটা সত্যিই থোক — "যা-ই বেচো, দুই হাজার"।
     */
    public function amountOn(string $base): string
    {
        if ($this->fixed_amount !== null && bccomp((string) $this->fixed_amount, '0', 4) > 0) {
            return (string) $this->fixed_amount;
        }

        if ($this->rate_percent === null) {
            return '0';
        }

        return bcdiv(bcmul($base, (string) $this->rate_percent, 6), '100', 4);
    }

    /** পর্দায় এই নিয়মটা কী বলে — "৫,০০,০০০ পর্যন্ত ২%"। */
    public function bandLabel(): string
    {
        $from = Money::format($this->slab_from ?? '0');

        return $this->slab_to === null
            ? __('sales::field.slab_above', ['from' => $from])
            : __('sales::field.slab_between', [
                'from' => $from,
                'to' => Money::format($this->slab_to),
            ]);
    }
}

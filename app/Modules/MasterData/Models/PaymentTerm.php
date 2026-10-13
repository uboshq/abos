<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\IsMasterRecord;
use App\Core\Contracts\Drillable;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * পরিশোধের শর্ত — "নগদ", "৭ দিন", "৩০ দিন"।
 *
 * গ্রাহকের ঘরে সাধারণ শর্ত থাকে, কিন্তু একটা নির্দিষ্ট বিলে অন্য শর্ত
 * দেওয়া যায় — তাই এটা গ্রাহকের কলাম নয়, নিজের একটা মাস্টার।
 */
class PaymentTerm extends Model implements Drillable
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use IsMasterRecord;
    use SoftDeletes;

    protected $table = 'mdm_payment_terms';

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn',
        'days', 'early_discount_percent', 'early_discount_days',
        'is_default', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'days' => 'integer',
            'early_discount_percent' => 'decimal:4',
            'early_discount_days' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** বিলের তারিখ থেকে শেষ তারিখ। */
    public function dueDateFrom(Carbon|string $invoiceDate): Carbon
    {
        $date = $invoiceDate instanceof Carbon ? $invoiceDate->copy() : Carbon::parse($invoiceDate);

        /*
         * ⭐ "মাস শেষে" আর "বছর শেষে" দিন গোনা নয়, একটা ঘটনা — মালিক, ১৬ সেপ্টেম্বর ২০২৬ (মাস্টার লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর
         * ২০২৬)। ⛔ সারি দুইটার `days` ইচ্ছা করে ০, কিন্তু এখানে কেবল দিন যোগ হত — "মাস শেষে"-র সরবরাহকারীর ৩ তারিখের বিল ৩
         * তারিখেই মেয়াদোত্তীর্ণ, পরদিন থেকে "বাকি পেরোনো"। ⓘ বছর মানে কোম্পানির আর্থিক বছর; না পেলে ক্যালেন্ডারের শেষ দিন।
         */
        return match ($this->code) {
            'MONTH_END' => $date->endOfMonth()->startOfDay(),
            'YEAR_END' => (\App\Models\FinancialYear::forDate($date)?->ends_on?->copy() ?? $date->endOfYear())->startOfDay(),
            default => $date->addDays($this->days),
        };
    }

    /** সময়মতো দিলে ছাড় আছে কি না। */
    public function hasEarlyDiscount(): bool
    {
        return bccomp((string) $this->early_discount_percent, '0', 4) > 0
            && $this->early_discount_days > 0;
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'payment_term';
    }

    public function drillDocumentNo(): string
    {
        return $this->code;
    }

    public function drillLabel(): string
    {
        return $this->name();
    }

    public function drillRoute(): array
    {
        return ['master_data.term.show', ['term' => $this->id]];
    }
}

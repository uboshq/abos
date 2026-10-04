<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use App\Modules\MasterData\Models\OpportunityStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * সুযোগ — একটা সম্ভাব্য বিক্রয়, অঙ্ক আর সম্ভাবনাসহ।
 *
 * ── কেন সম্ভাবনা আলাদা ঘর, ধাপ থেকে নয় ────────────────────────────────
 * ধাপ একটা ডিফল্ট দেয় ("প্রস্তাব" = ৪০%), কিন্তু বিক্রয়কর্মী জানেন এই
 * দোকানটা প্রায় নিশ্চিত। ⓘ ধাপ বদলালে সম্ভাবনা ধাপেরটায় ফেরে; হাতে
 * বদলালে সেটাই থাকে। পাইপলাইনের ওজন-করা অঙ্ক এই ঘর থেকেই গোনা।
 */
class Opportunity extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'sal_opportunities';

    protected $fillable = [
        'company_id', 'document_no', 'title', 'customer_id', 'lead_id',
        'salesperson_user_id', 'stage_id', 'estimated_value', 'probability',
        'expected_close_date', 'competitor', 'remarks', 'sales_quotation_id',
        'closed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'estimated_value' => 'decimal:4',
            'probability' => 'integer',
            'expected_close_date' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_user_id');
    }

    public function stage(): BelongsTo
    {
        // ⓘ বন্ধ করা বা মুছে ফেলা ধাপও পুরনো সুযোগে নাম দেখায়
        return $this->belongsTo(OpportunityStage::class, 'stage_id')->withTrashed();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OpportunityLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** কার সাথে — গ্রাহক থাকলে গ্রাহক, নাহলে লিডের নাম। */
    public function partyName(): string
    {
        return $this->customer?->name() ?? (string) $this->lead?->name;
    }

    /** অঙ্ক × সম্ভাবনা — পাইপলাইনের ওজন, bcmath-এ। */
    public function weightedValue(): string
    {
        return bcdiv(bcmul((string) $this->estimated_value, (string) $this->probability, 4), '100', 4);
    }

    public function isWon(): bool
    {
        return (bool) $this->stage?->is_won;
    }

    /**
     * ⭐ দেখার দেয়াল — [[Lead::scopeVisibleTo()]]-এর যমজ, একই কারণে।
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('sales.opportunity.manage')) {
            return $query;
        }

        return $query->where('salesperson_user_id', $user->id);
    }
}

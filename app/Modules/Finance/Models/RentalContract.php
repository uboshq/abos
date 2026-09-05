<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\Branch;
use App\Modules\Accounts\Models\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * একটা ভাড়ার চুক্তি — গোডাউন, দোকান, অফিস, মিটার, গাড়ি।
 *
 * ── এখানে কোনো "কত পড়ে আছে" কলাম নেই ────────────────────────────────
 * খুঁজলে পাবেন না, আর সেটা ইচ্ছাকৃত — [[Batch]] আর হাতধারে ঠিক একই
 * সিদ্ধান্ত। জামানতে কত বাকি তা **সমন্বয়ের সারি যোগ করে** বের হয়।
 *
 * ⚠️ কলাম রাখলে সেটা খতিয়ানের দ্বিতীয় কপি হত, আর দুই কপি একদিন আলাদা
 * হয়ই — সাধারণত যেদিন একটা ভাউচার বাতিল হয় আর একটা কপি উল্টে যায়।
 * তখন কোনটা সত্যি তা বলার কোনো উপায় থাকে না।
 */
class RentalContract extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    /** চলছে — মাসের সমন্বয় করা যায়। */
    public const ACTIVE = 'active';

    /**
     * শেষ — মেয়াদ ফুরিয়েছে বা আগেই ছেড়ে দেওয়া হয়েছে।
     *
     * ⓘ "বাতিল" নেই: একটা চুক্তি বাতিল হয় না, **শেষ** হয়। ভুল করে
     * বসানো চুক্তি soft delete হয়, আর সেটা আলাদা ঘটনা।
     */
    public const CLOSED = 'closed';

    /** @var list<string> */
    public const STATES = [self::ACTIVE, self::CLOSED];

    protected $table = 'fin_rental_contracts';

    protected $fillable = [
        'company_id', 'branch_id', 'document_no',
        'counterparty', 'counterparty_phone', 'subject',
        'account_id', 'expense_account_id',
        'deposit_amount', 'monthly_rent', 'monthly_adjustment',
        'starts_on', 'term_months', 'ends_on',
        'status', 'closed_on', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'deposit_amount' => 'decimal:4',
            'monthly_rent' => 'decimal:4',
            'monthly_adjustment' => 'decimal:4',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'closed_on' => 'date',
            'term_months' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(RentalAdjustment::class);
    }

    /**
     * প্রতি মাসে নগদে কত — গোনা হয়, রাখা হয় না।
     *
     * ⚠️ আলাদা কলাম রাখলে কেউ একদিন এমন তিনটা সংখ্যা বসাত যাদের যোগফল
     * ভাড়ার সাথে মেলে না, আর ভাউচারটা ভারসাম্যহীন হয়ে থামত — অথচ ভুলটা
     * ফর্মে, ভাউচারে নয়।
     */
    public function monthlyCash(): string
    {
        return bcsub((string) $this->monthly_rent, (string) $this->monthly_adjustment, 4);
    }

    /** এ পর্যন্ত জামানত থেকে কত কাটা হয়েছে। */
    public function adjustedSoFar(): string
    {
        return (string) $this->adjustments()->sum('from_deposit');
    }

    /**
     * জামানতে এখন কত পড়ে আছে।
     *
     * ⓘ এটাই চুক্তি শেষে ফেরতযোগ্য টাকা — আর ঠিক এই সংখ্যাটাই মানুষ
     * ভুলে যায়।
     */
    public function depositLeft(): string
    {
        return bcsub((string) $this->deposit_amount, $this->adjustedSoFar(), 4);
    }

    /**
     * পুরো মেয়াদ শেষে কত ফেরত আসবে — আজকের নয়, শেষের হিসাব।
     *
     * ⓘ "ক" ধরনে ৯,৬০,০০০; "খ" ধরনে শূন্য। ⚠️ শূন্য হলে টাকাটা আসলে
     * জামানত নয়, **অগ্রিম ভাড়া** — আর তখন খাতটাও আলাদা হওয়া উচিত।
     */
    public function refundableAtEnd(): string
    {
        $whole = bcmul((string) $this->monthly_adjustment, (string) $this->term_months, 4);

        return bcsub((string) $this->deposit_amount, $whole, 4);
    }

    /** মেয়াদ শেষ হতে কত দিন — শেষ হয়ে গেলে ঋণাত্মক। */
    public function daysLeft(?Carbon $on = null): int
    {
        return (int) ($on ?? now())->startOfDay()->diffInDays($this->ends_on, false);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** @param  Builder<RentalContract>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    /**
     * যেগুলো শেষ হয়ে আসছে — ডিফল্টে নব্বই দিন।
     *
     * ⚠️ নব্বই কেন: বাড়িওয়ালাকে নোটিশ দিতে সাধারণত তিন মাস লাগে, আর
     * ⓘ সংখ্যাটা প্যারামিটার, তাই যাঁর চুক্তিতে অন্য শর্ত তিনি অন্য
     * সংখ্যা দিতে পারেন।
     *
     * @param  Builder<RentalContract>  $query
     */
    public function scopeEndingSoon(Builder $query, int $days = 90): Builder
    {
        return $query->active()->whereDate('ends_on', '<=', now()->addDays($days));
    }
}

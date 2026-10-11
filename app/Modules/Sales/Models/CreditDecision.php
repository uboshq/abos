<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ দেয়ালের একটা সিদ্ধান্ত — কী দেখেছিল আর কী বলেছিল (বাকি নিয়ন্ত্রণ ধাপ ১.৫, ১১ অক্টোবর ২০২৬)।
 *
 * ⓘ লেখে কেবল [[CreditExposure]] — DO-র হিসাবের যাচাইয়ে, চালান, বিল আর কাউন্টারে। "হ্যাঁ" লেখে তালাসহ দেয়াল (যেটা
 * কাগজকে খাতায় যেতে দেয়), "না" লেখে যে দেয়াল থামায়।
 *
 * ⛔ কেবল যোগের: সারি বদলায় না, মোছে না — পরে "কেন আটকাল" প্রশ্নের উত্তর এটাই, আর উত্তর বদলানো গেলে সেটা আর উত্তর নয়।
 */
class CreditDecision extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    public const PASS = 'pass';

    public const STOP = 'stop';

    /** কোথায় দেয়াল দাঁড়িয়েছিল */
    public const STAGES = ['delivery_order', 'challan', 'invoice', 'counter'];

    /** কেন — পাশ: সীমার ভিতরে, বা সীমার সুইচ বন্ধ; থামা: সীমা পার, হাতে বন্ধ, মেয়াদ পেরোনো বাকি, হিসাবই চলেনি */
    public const REASONS = ['within_limit', 'switch_off', 'over_limit', 'blocked', 'overdue', 'error'];

    public const UPDATED_AT = null;

    protected $table = 'sal_credit_decisions';

    protected $fillable = [
        'company_id', 'branch_id', 'customer_id', 'stage', 'paper_type', 'paper_id', 'paper_no',
        'credit_limit', 'due', 'held', 'adding', 'paying_now', 'exposure_after',
        'result', 'reason', 'message', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:4',
            'due' => 'decimal:4',
            'held' => 'decimal:4',
            'adding' => 'decimal:4',
            'paying_now' => 'decimal:4',
            'exposure_after' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $row): void {
            throw new \RuntimeException('দেয়ালের সাক্ষ্য বদলানো যায় না — সারি #'.$row->id.'।');
        });

        static::deleting(function (self $row): void {
            throw new \RuntimeException('দেয়ালের সাক্ষ্য মোছা যায় না — সারি #'.$row->id.'।');
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withoutGlobalScopes();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

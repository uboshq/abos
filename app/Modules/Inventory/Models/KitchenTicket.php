<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * রান্নাঘরের একটা টিকিট — একটা পদ, একটা কাগজ থেকে।
 *
 * ── কেন এটা অডিট করা হয় ─────────────────────────────────────────────
 * টিকিট টাকার কাগজ নয়, তাই প্রথমে মনে হয় অডিট লাগে না। লাগে: বিতর্কটা
 * ঠিক এখানেই হয় — "অর্ডার তো দিয়েছিলাম", "রান্নাঘরে আসেইনি", "কে
 * বাতিল করল"। ওই প্রশ্নগুলোর উত্তর অন্য কোথাও লেখা থাকে না।
 */
class KitchenTicket extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;

    /**
     * চারটা অবস্থা, আর ক্রমটাই তাদের সংজ্ঞা।
     *
     * ── কেন "বাতিল" নেই ─────────────────────────────────────────────
     * টিকিট বাতিল হয় না; **কাগজটা** বাতিল হয়, আর তখন টিকিটও যায়।
     * আলাদা একটা বাতিল অবস্থা রাখলে একটা বাতিল বিলের টিকিট রান্নাঘরে
     * "বাতিল" লেখা নিয়ে বসে থাকত, আর কেউ জানত না ওটা রাঁধা হয়েছিল
     * কি না।
     */
    public const PLACED = 'placed';

    public const COOKING = 'cooking';

    public const READY = 'ready';

    public const SERVED = 'served';

    /** @var list<string> */
    public const STATES = [self::PLACED, self::COOKING, self::READY, self::SERVED];

    protected $table = 'inv_kitchen_tickets';

    protected $fillable = [
        'company_id', 'branch_id', 'source_type', 'source_id', 'document_no',
        'product_id', 'qty', 'state', 'placed_at', 'started_at', 'ready_at',
        'served_at', 'note', 'created_by',
    ];

    /**
     * পরিমাণ decimal, আর সময়গুলো সত্যিকারের সময়।
     *
     * পরিমাণ float হলে আধা প্লেটের অর্ডারে ভুল জমত; সময় স্ট্রিং হলে
     * "কতক্ষণ বসে আছে" প্রতিবার হাতে গুনতে হত।
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'placed_at' => 'datetime',
            'started_at' => 'datetime',
            'ready_at' => 'datetime',
            'served_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * রান্নাঘরের পর্দায় যা যা থাকে — দেওয়া হয়ে গেছে যেগুলো, সেগুলো বাদে।
     *
     * ── কেন দেওয়া টিকিট পর্দা থেকে যায় ──────────────────────────────
     * ব্যস্ত সময়ে পর্দায় ত্রিশটা টিকিট। শেষ হওয়াগুলো থাকলে রাঁধুনিকে
     * প্রতিবার চোখ দিয়ে ছেঁকে নিতে হত, আর ঠিক তখনই একটা নতুন টিকিট
     * চোখ এড়ায়।
     *
     * @param  Builder<KitchenTicket>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('state', [self::PLACED, self::COOKING, self::READY]);
    }

    /**
     * কতক্ষণ ধরে বসে আছে, মিনিটে।
     *
     * ── কেন "রান্না শুরু" থেকে নয় ───────────────────────────────────
     * খদ্দের অপেক্ষা করছেন অর্ডার দেওয়ার সময় থেকে, রাঁধুনি হাত দেওয়ার
     * সময় থেকে নয়। রান্নাঘরের পর্দায় যেটা লাল হওয়া দরকার সেটা হলো
     * **খদ্দেরের** অপেক্ষা।
     */
    public function waitingMinutes(): int
    {
        $until = $this->ready_at ?? now();

        return (int) $this->placed_at->diffInMinutes($until);
    }
}

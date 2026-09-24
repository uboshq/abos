<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * একটা পিস — তার নিজের নম্বর, নিজের ওয়ারেন্টি, নিজের গল্প।
 *
 * ── ⓘ লট থাকতে সিরিয়াল কেন ──────────────────────────────────────────
 * লট একটা **দল**: একসাথে আসা পঞ্চাশ বস্তা, একটাই মেয়াদ। ⚠️ ওতে রিকলের
 * উত্তর মেলে (*"ঐ চালানটা কোথায় গেল"*), ⛔ কিন্তু ওয়ারেন্টির নয়:
 * *"এই একটা পিস কবে কার কাছে গেল"*।
 *
 * ⓘ লট দিয়ে উত্তর দিতে গেলে বলতে হত *"এই পঞ্চাশটার কোনো একটা"*, আর
 * ওটা কোনো উত্তর নয়।
 *
 * ── ⚠️ এখানে কোনো "কত আছে" নেই ──────────────────────────────────────
 * ⓘ প্রতিটা সারি **একটা পিস**, তাই গুনতি মানে সারি গোনা। ⛔ একটা
 * পরিমাণের ঘর রাখলে সেটা একই সত্যের দ্বিতীয় কপি হত — ঠিক যে কারণে
 * [[Batch]]-এও ওরকম কোনো ঘর নেই।
 */
class SerialNumber extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    protected $table = 'inv_serial_numbers';

    /**
     * ⭐ একটা পিস এখন কোথায়।
     *
     * ── ⓘ তিনটাই, আর তিনটাই আলাদা প্রশ্নের উত্তর ─────────────────────
     *   `in_stock` — গুদামে, বেচা যায়
     *   `sold`     — গ্রাহকের কাছে; ওয়ারেন্টির ঘড়ি চলছে
     *   `returned` — ফেরত এসেছে, আর এখনো সিদ্ধান্ত হয়নি
     *
     * ⚠️ `scrapped` নেই, ইচ্ছাকৃতভাবে: ⛔ নষ্ট হওয়া পিসের সিদ্ধান্তটা
     * গুণমান পরিদর্শনের কাগজে ([[QualityInspection]]), আর একই সিদ্ধান্ত
     * দুই জায়গায় রাখলে একদিন দুইটা আলাদা কথা বলত।
     */
    public const IN_STOCK = 'in_stock';

    public const SOLD = 'sold';

    public const RETURNED = 'returned';

    /** @var list<string> */
    public const STATES = [self::IN_STOCK, self::SOLD, self::RETURNED];

    protected $fillable = [
        'company_id', 'product_id', 'batch_id', 'serial_no', 'warehouse_id',
        'in_source_type', 'in_source_id', 'received_on',
        'out_source_type', 'out_source_id', 'issued_on',
        'sold_to', 'warranty_from', 'warranty_to',
        'status', 'remarks', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'received_on' => 'date',
            'issued_on' => 'date',
            'warranty_from' => 'date',
            'warranty_to' => 'date',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('status', self::IN_STOCK);
    }

    /**
     * নম্বর ধরে খোঁজা — ⚠️ বড়-ছোট হরফ ও ফাঁকা জায়গা বাদ দিয়ে।
     *
     * ⓘ সিরিয়াল নম্বর মানুষ কাগজ দেখে টাইপ করেন, আর কাগজে ওটা প্রায়ই
     * বড় হরফে ছাপা থাকে। ⛔ হুবহু মেলানো চাইলে ওয়ারেন্টির দাবি নিয়ে
     * আসা মানুষটাকে বলতে হত *"এই নম্বরের কিছু নেই"*, অথচ পিসটা
     * খাতাতেই আছে।
     */
    public function scopeNumbered(Builder $query, string $serial): Builder
    {
        return $query->whereRaw('UPPER(serial_no) = ?', [
            mb_strtoupper(trim($serial)),
        ]);
    }

    /**
     * ওয়ারেন্টি এখনো চলছে কি না।
     *
     * ⚠️ তারিখ বসানো না থাকলে উত্তরটা *"না"*, *"হ্যাঁ"* নয়। ⓘ যে
     * পিসের ওয়ারেন্টি কেউ লেখেনি তার দাবি মেনে নেওয়ার কোনো ভিত্তি
     * নেই — ⛔ আর উল্টোটা ধরলে প্রতিটা অলিখিত পিস আজীবন ওয়ারেন্টিতে
     * থাকত।
     */
    public function underWarranty(?Carbon $on = null): bool
    {
        if ($this->warranty_to === null) {
            return false;
        }

        $on = $on ?? Carbon::today();

        return $this->warranty_to->gte($on)
            && ($this->warranty_from === null || $this->warranty_from->lte($on));
    }
}

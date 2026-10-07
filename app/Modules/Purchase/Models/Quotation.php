<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserBranch;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * একজন সরবরাহকারীর দর — তাঁর নিজের শর্ত সহ।
 *
 * ── ⚠️ দর মানে কেবল একটা সংখ্যা নয় ──────────────────────────────────
 * ⓘ একজন কম দর বলেন কিন্তু ত্রিশ দিনে মাল দেন; আরেকজন একটু বেশি বলেন
 * আর তিন দিনে দেন। ⛔ কেবল দর দেখে বাছলে দ্বিতীয়জন কোনোদিন জিততেন না,
 * অথচ যে দোকানের মাল ফুরিয়ে গেছে তার কাছে ত্রিশ দিন মানে ত্রিশ দিনের
 * বিক্রি হারানো।
 *
 * ⭐ তাই তুলনায় চারটা জিনিস পাশাপাশি: **মোট · সরবরাহের দিন · পরিশোধের
 * শর্ত · মেয়াদ**।
 */
class Quotation extends Model
{
    use BelongsToCompany;
    use HasDocumentStatus;
    use HasPublicId;
    use IsAudited;
    use ScopedToUserBranch;
    use SoftDeletes;

    protected $table = 'pur_quotations';

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'rfq_id', 'supplier_id',
        'supplier_quote_no', 'quoted_on', 'valid_until', 'delivery_days',
        'payment_terms', 'freight', 'other_charges', 'narration',
        'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quoted_on' => 'date',
            'valid_until' => 'date',
            'freight' => 'decimal:4',
            'other_charges' => 'decimal:4',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class, 'quotation_id')->orderBy('line_no');
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class, 'rfq_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * ⭐ পণ্যের মোট — ছাড় বাদ, কর যোগ।
     *
     * ⓘ ভাড়া ও অন্যান্য খরচ এখানে নেই, ইচ্ছাকৃতভাবে: ⚠️ ওগুলো কাগজের
     * মাথায় বসে, আর তুলনায় আলাদা করে দেখা দরকার। ⛔ একসাথে মিলিয়ে
     * ফেললে কার পণ্য সস্তা আর কার ভাড়া বেশি তা আর আলাদা করা যেত না।
     */
    public function goodsTotal(): string
    {
        return $this->lines->reduce(
            fn (string $sum, QuotationLine $line) => bcadd($sum, $line->netAmount(), 4),
            '0',
        );
    }

    /**
     * ⭐ সব মিলিয়ে কত — আর তুলনার আসল সংখ্যা এটাই।
     *
     * ⚠️ যে সরবরাহকারী পণ্যে কম দর দিয়ে ভাড়ায় পুষিয়ে নেন, তিনি কেবল
     * পণ্যের মোট দেখলে জিতে যেতেন। ⓘ সিদ্ধান্তটা হওয়া উচিত **যা
     * সত্যিই দিতে হবে** তার উপর।
     */
    public function grandTotal(): string
    {
        return bcadd(
            $this->goodsTotal(),
            bcadd((string) $this->freight, (string) $this->other_charges, 4),
            4,
        );
    }

    /**
     * দরটা এখনো টিকে আছে কি না।
     *
     * ⚠️ মেয়াদ বসানো না থাকলে উত্তরটা *"হ্যাঁ"* — ⓘ সরবরাহকারী কোনো
     * শেষ তারিখ না বললে দরটা খোলা, আর সেটাই স্বাভাবিক পড়া। ⛔ উল্টোটা
     * ধরলে মেয়াদহীন প্রতিটা দর প্রথম দিনেই বাতিল দেখাত।
     */
    public function isStillGood(?Carbon $on = null): bool
    {
        if ($this->valid_until === null) {
            return true;
        }

        return $this->valid_until->gte($on ?? Carbon::today());
    }
}

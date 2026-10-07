<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserBranch;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * একটা ক্রয় চুক্তি — বছরের শুরুতে ঠিক করা দর।
 *
 * ── ⛔ এর আগে দরটা থাকত কারও স্মৃতিতে ───────────────────────────────
 * বছরের শুরুতে সরবরাহকারীর সাথে দর ঠিক হত, আর সেটা থাকত একটা কাগজে বা
 * কারও মনে। ⚠️ প্রতিটা আদেশে দর হাতে বসানো হত, আর কেউ মেলাত না — তাই
 * চুক্তির চেয়ে বেশি দরে অর্ডার চলে যেত, নীরবে।
 *
 * ── ⓘ অবস্থাগুলো বিদ্যমান শব্দভাণ্ডারেই ──────────────────────────────
 *     draft     — লেখা হচ্ছে
 *     confirmed — চালু; দর মেলানো এখন এর বিরুদ্ধেই হয়
 *     closed    — মেয়াদ শেষ বা নবায়ন হয়েছে
 *     cancelled — বাতিল
 */
class PurchaseContract extends Model
{
    use BelongsToCompany;
    use HasDocumentStatus;
    use HasPublicId;
    use IsAudited;
    use ScopedToUserBranch;
    use SoftDeletes;

    protected $table = 'pur_contracts';

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'supplier_id', 'supplier_ref',
        'starts_on', 'ends_on', 'terms', 'narration', 'status',
        'created_by', 'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseContractLine::class, 'contract_id')->orderBy('line_no');
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
     * ⭐ আজ এই চুক্তিটা খাটে কি না।
     *
     * ── ⚠️ তিনটা শর্ত, আর তিনটাই লাগে ───────────────────────────────
     * ⓘ চালু হতে হবে (খসড়া চুক্তি কোনো দর বাঁধে না), শুরু হয়ে গেছে,
     * আর শেষ হয়নি। ⛔ যেকোনো একটা বাদ দিলে মেয়াদ পেরোনো বা এখনো শুরু
     * না হওয়া চুক্তির দর আজকের আদেশে খাটত।
     */
    public function coversToday(?Carbon $on = null): bool
    {
        $on = $on ?? Carbon::today();

        return $this->status === DocumentStatus::CONFIRMED
            && $this->starts_on->lte($on)
            && $this->ends_on->gte($on);
    }

    /**
     * ⭐ মেয়াদ শেষের কত দিন বাকি — ঋণাত্মক হলে পেরিয়ে গেছে।
     *
     * ⓘ রোজকার প্রশ্নটা এটাই: *"কোন চুক্তিগুলোর মেয়াদ শেষ হয়ে আসছে"*।
     * ⚠️ তারিখটা অ্যাপের ঘড়ি ধরে, ডাটাবেজের নয় — ⛔ দুইটা এক হওয়ার
     * কোনো নিশ্চয়তা কোথাও লেখা নেই, আর এক দিনের ভুলে একটা চুক্তি
     * নীরবে পেরিয়ে যেত।
     */
    public function daysLeft(?Carbon $on = null): int
    {
        /*
         * ⛔ `(int)` টা অপরিহার্য — ২৪ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ Carbon ৩-এ `diffInDays()` **float** ফেরায়, আর ঘোষিত
         * ফেরত-ধরন `int` বলে PHP সরাসরি `TypeError` ছোড়ে। ⓘ ফল:
         * চুক্তির গোটা তালিকাটা ৫০০ দিত, কারণ পর্দার প্রতিটা সারি
         * এই সংখ্যাটা ছাপে।
         *
         * ⚠️ `round()` ব্যবহার হয়, `(int)` একা নয়: ⛔ `(int)` শূন্যের
         * দিকে কাটে, তাই ঋণাত্মক দিকে `-০.৯` হত `০` — অর্থাৎ পেরিয়ে
         * যাওয়া একটা চুক্তি *"আজই শেষ"* দেখাত। ⓘ দুইটা তারিখই
         * মধ্যরাতে, তাই ভগ্নাংশ আসার কথা নয় — কিন্তু "আসার কথা নয়"
         * আর "আসে না" এক জিনিস নয়।
         */
        return (int) round(($on ?? Carbon::today())->diffInDays($this->ends_on, false));
    }

    /**
     * এই পণ্যের চুক্তির দর — চুক্তিতে না থাকলে কিছুই না।
     *
     * ⚠️ `null` মানে *"এই পণ্যটা চুক্তিতে নেই"*, শূন্য নয়। ⛔ শূন্য
     * ফেরালে আদেশের দর মেলানোর সময় মনে হত চুক্তিতে জিনিসটা বিনামূল্যে।
     */
    public function rateFor(int $productId): ?string
    {
        $line = $this->lines->firstWhere('product_id', $productId);

        return $line === null ? null : (string) $line->agreed_rate;
    }

    /**
     * আজ যেগুলো খাটে।
     *
     * ⓘ ছাঁকনিটা কোয়েরিতে, কারণ *"এই পণ্যের চুক্তির দর কত"* প্রশ্নটা
     * আদেশের পর্দায় ওঠে, আর তখন সব চুক্তি মেমরিতে তোলা অর্থহীন।
     */
    public function scopeLiveOn(Builder $query, ?Carbon $on = null): Builder
    {
        $on = ($on ?? Carbon::today())->toDateString();

        return $query->where('status', DocumentStatus::CONFIRMED)
            ->whereDate('starts_on', '<=', $on)
            ->whereDate('ends_on', '>=', $on);
    }
}

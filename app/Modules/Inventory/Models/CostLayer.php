<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * এক চালানের মাল, তার নিজের দাম নিয়ে।
 *
 * মাল ঢুকলে একটা স্তর তৈরি হয়; বেরোলে পুরনো স্তর থেকে টানা হয়। স্তরটা
 * কখনো মোছে না — নিঃশেষ হলে qty_remaining শূন্য হয়, সারিটা থেকে যায়।
 * তাতে ছয় মাস পরেও বলা যায় ওই বিক্রয়ের খরচ কোন চালান থেকে এসেছিল।
 */
class CostLayer extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    protected $table = 'inv_cost_layers';

    protected $fillable = [
        'company_id', 'product_id', 'source_type', 'source_id', 'document_no',
        'trx_date', 'qty_in', 'qty_remaining', 'unit_cost', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'qty_in' => 'decimal:4',

            /*
             * ⛔ PHPStan এই ঘরটা নিয়ে যা বলে, সেটা বিশ্বাস করে "সারাতে"
             * যাবেন না।
             *
             * অভিযোগটা আসে এভাবে: *"Property CostLayer::$qty_remaining
             * (float) does not accept string"* — `CostLayerService`-এ।
             * পড়ে মনে হয় ঘরটা float, আর আমরা ভুল করে string বসাচ্ছি।
             *
             * ⓘ উল্টোটা সত্য। কলামটা মাইগ্রেশনে `decimal(18, 4)`, কাস্ট
             * এখানে `decimal:4`, আর যা বসে তা bcmath-এর string — অর্থাৎ
             * সবই ঠিক। ভুলটা যন্ত্রের: larastan মাইগ্রেশন পড়ার সময়
             * `decimal` কলামকে `float` ধরে নেয়, তাই সে string-কে আপত্তি
             * করে। অভিযোগটা phpstan-baseline.neon-এ ধরা আছে, আর ওখানেই
             * থাকার কথা।
             *
             * ⚠️ কেউ এটা "সারাতে" `(float)` কাস্ট বসালে সে রিপোর সবচেয়ে
             * কঠিন নিয়মটা ভাঙবে — আর **টেস্টও হয়তো পাশ করত**, কারণ ছোট
             * সংখ্যায় float আর decimal একই উত্তর দেয়। ভুলটা বেরোত মাস
             * ছয়েক পরে, পয়সার ঘরে, যখন কারণ খোঁজা প্রায় অসম্ভব।
             * নিয়মটা `MoneyIsNeverAFloatTest`-এ লেখা।
             */
            'qty_remaining' => 'decimal:4',

            'unit_cost' => 'decimal:4',
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

    public function uses(): HasMany
    {
        return $this->hasMany(CostLayerUse::class);
    }

    /**
     * যে স্তরগুলোয় এখনো মাল আছে, পুরনো আগে।
     *
     * তারিখে সাজানো, তারপর id-তে — একই দিনে দুইটা চালান এলে যেটা আগে
     * লেখা হয়েছিল সেটাই আগে বেরোবে। শুধু id-তে সাজালে পিছিয়ে বসানো
     * একটা পুরনো তারিখের চালান সবার শেষে গিয়ে পড়ত।
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('qty_remaining', '>', 0)
            ->orderBy('trx_date')
            ->orderBy('id');
    }
}

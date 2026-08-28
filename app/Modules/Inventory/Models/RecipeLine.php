<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * রেসিপির একটা উপকরণ — কতটা লাগে, আর কতটা নষ্ট হয়।
 *
 * ── কেন এটা অডিট করা হয় ─────────────────────────────────────────────
 * প্রথম লেখায় ধরে নেওয়া হয়েছিল `*Line` মডেল অডিটের বাইরে, যুক্তি ছিল
 * "লাইন একা বদলায় না, রেসিপির সাথেই বদলায়"। **ধারণাটা ভুল**:
 * `SalesInvoiceLine`-ও অডিটেড, আর [[AuditTest]] প্রতিটা `*Line.php`
 * ফাইলকে ধরেই দেখে।
 *
 * আর ভালোই যে ধরে। রেসিপির লাইন সাজসজ্জা নয় — **ওই সংখ্যাটাই ঠিক করে
 * বিক্রিতে গুদাম থেকে কতটা বেরোবে**। কেউ চুপচাপ "৫ কেজি চাল" বদলে
 * "৩ কেজি" করে দিলে প্রতিটা বিক্রিতে দুই কেজি চাল খাতায় থেকে যেত যা
 * বাস্তবে নেই, আর মাস শেষে কেউ বলতে পারত না কবে থেকে গোলমাল।
 *
 * অর্থাৎ এটা ঠিক সেই ধরনের তথ্য যার জন্য অডিট বানানো — কে কখন কী
 * বদলেছে।
 */
class RecipeLine extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;

    protected $table = 'inv_recipe_lines';

    protected $fillable = [
        'company_id', 'recipe_id', 'product_id', 'qty', 'waste_pct', 'sort',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * গুদাম থেকে যতটা সত্যিই বেরোয় — অপচয় ধরে।
     *
     * ── কেন স্টক এই সংখ্যাটা দিয়েই কমে ───────────────────────────────
     * রেসিপিতে লেখা থাকে "৮৫০ গ্রাম আলু", কারণ রান্নায় ওটাই যায়। কিন্তু
     * খোসাসহ ১ কেজি আলু গুদাম থেকে বেরোয়, আর খোসাটা ফেলে দেওয়া হয়।
     *
     * স্টক যদি ৮৫০ গ্রাম কমে, তবে প্রতি রান্নায় ১৫০ গ্রাম আলু খাতায়
     * থেকে যায় যা বাস্তবে নেই। এক মাসে ওটা কয়েক বস্তা।
     *
     * ── অঙ্কটা কেন ভাগ, গুণ নয় ──────────────────────────────────────
     * ১৫% অপচয় মানে "যা বেরোয় তার ১৫% নষ্ট", তাই যা লাগে সেটা বেরোনো
     * পরিমাণের ৮৫%। উল্টো করে ৮৫০-এর সাথে ১৫% যোগ করলে পাওয়া যেত
     * ৯৭৭.৫ — আর ৯৭৭.৫ গ্রাম আলুর খোসা ছাড়ালে ৮৩১ গ্রাম থাকে, ৮৫০ নয়।
     *
     * ভুলটা ছোট দেখায় কিন্তু প্রতিটা রান্নায় ঘটে, আর সবসময় এক দিকে।
     */
    public function grossQty(): string
    {
        $waste = (string) ($this->waste_pct ?? '0');

        /*
         * ১০০% বা তার বেশি অপচয় মানে কিছুই টেকে না — অঙ্কটা তখন শূন্য
         * দিয়ে ভাগ। ওটা তথ্য নয়, ভুল বসানো; তাই অপচয় ধরা হয় না আর
         * খাঁটি পরিমাণটাই ফেরত যায়। ফর্ম ওটা আটকায়
         * ([[RecipeRequest]]), এটা শেষ ছাঁকনি।
         */
        if (bccomp($waste, '0', 4) <= 0 || bccomp($waste, '100', 4) >= 0) {
            return $this->qty;
        }

        return bcdiv($this->qty, bcdiv(bcsub('100', $waste, 6), '100', 6), 6);
    }
}

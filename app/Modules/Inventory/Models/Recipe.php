<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * একটা খাবারের রেসিপি — কী দিয়ে তৈরি, আর কখন উপকরণ কমে।
 *
 * ── কেন এটা ইনভেন্টরিতে, বিক্রয়ে নয় ─────────────────────────────────
 * রেসিপি বিক্রির কথা নয়, **স্টকের** কথা। একই রেসিপি বিক্রিতে কাজে লাগে,
 * উৎপাদনে লাগে, খরচের রিপোর্টে লাগে। বিক্রয়ে রাখলে উৎপাদনকে বিক্রয়ের
 * উপর নির্ভর করতে হত — অথচ হাঁড়ি চড়ানোর সাথে বিক্রির কোনো সম্পর্ক নেই।
 */
class Recipe extends Model
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    /**
     * অর্ডারে রান্না — বিক্রির মুহূর্তে উপকরণ কমে।
     *
     * চা, চিকেন ফ্রাই, স্যান্ডউইচ। রান্না শুরুই হয় অর্ডার পাওয়ার পর,
     * তাই বিক্রি আর রান্না একই মুহূর্ত।
     */
    public const TO_ORDER = 'to_order';

    /**
     * হাঁড়িতে রান্না — উৎপাদনের মুহূর্তে উপকরণ কমে।
     *
     * বিরিয়ানি, ডাল, তরকারি। সকালে এক হাঁড়ি চড়ে, সারাদিন ওখান থেকেই
     * প্লেট যায়। বিক্রিতে কমে **তৈরি খাবারটা**, উপকরণ নয় — ওগুলো
     * রান্নার সময়ই কমে গেছে।
     */
    public const BATCH = 'batch';

    /** @var list<string> */
    public const KINDS = [self::TO_ORDER, self::BATCH];

    protected $table = 'inv_recipes';

    protected $fillable = [
        'company_id', 'product_id', 'kind', 'yield_qty', 'is_active', 'note',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',

            /*
             * ফলনটা decimal, নাহলে string হয়ে ফেরে আর কেউ `+`
             * লিখলেই PHP float বানিয়ে ফেলে — আর float-এ ১০/৩
             * কোনোদিন ঠিক হয় না ([[MoneyIsNeverAFloatTest]])।
             */
            'yield_qty' => 'decimal:4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RecipeLine::class)->orderBy('sort')->orderBy('id');
    }

    /** উপকরণের মুহূর্তেই স্টক কমে, না রান্নার সময়। */
    public function isMadeToOrder(): bool
    {
        return $this->kind === self::TO_ORDER;
    }

    /**
     * এই খাবারের একটার জন্য এই উপকরণটা কতটা লাগে — অপচয় সহ।
     *
     * ── কেন হিসাবটা এখানে, লাইনে নয় ─────────────────────────────────
     * ফলনটা রেসিপির, লাইনের নয়। "৫০ প্লেটে ১০ কেজি চাল" থেকে এক
     * প্লেটের হিসাব বের করতে দুইটাই লাগে, আর সেটা এক জায়গায় থাকা
     * দরকার — নাহলে বিক্রি এক নিয়মে ভাগ করত, রিপোর্ট আরেক নিয়মে।
     *
     * ── কেন `bc*`, সাধারণ ভাগ নয় ────────────────────────────────────
     * ভাসমান সংখ্যায় ১০/৩ কোনোদিন ঠিক হয় না, আর ওই ভুলটা প্রতিটা
     * বিক্রিতে জমতে থাকে। এই প্রকল্পে টাকার সব অঙ্ক `bc*`-এ, আর
     * পরিমাণও টাকায় গিয়েই ঠেকে।
     */
    public function perUnit(RecipeLine $line): string
    {
        $yield = $this->yield_qty;

        /*
         * ফলন শূন্য হলে ভাগ করা যায় না।
         *
         * কলামের ডিফল্ট ১, আর ফর্ম শূন্য নিতে দেয় না — তবু কেউ সরাসরি
         * ডাটাবেসে শূন্য বসালে এখানে ভাগ করতে গিয়ে গোটা বিক্রিটাই
         * ভাঙত। শূন্য মানে "রেসিপিটা অচল", তাই কিছুই কমে না।
         */
        if (bccomp($yield, '0', 4) <= 0) {
            return '0';
        }

        return bcdiv($line->grossQty(), $yield, 6);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\IsMasterRecord;
use App\Core\Contracts\Drillable;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * টাকার শ্রেণি — "কী ধরনের টাকা", আর তার উত্তরেই খাতটা।
 *
 * ── ⭐ এই তালিকাটা যা করে ────────────────────────────────────────────
 * আদায় ও প্রদান ভাউচারে ব্যবহারকারী আর হিসাবের খাত বাছেন না; তিনি
 * বাছেন কাজের নাম — "বিক্রয়ের বকেয়া আদায়", "ভাড়ার আয়", "ভুল
 * শোধরানো"। খাতটা শ্রেণির সাথে একবার বাঁধা থাকে, আর তারপর প্রতিটা
 * ভাউচারে নিজে থেকে বসে।
 *
 * ── এই তালিকাটা যা **নয়** ───────────────────────────────────────────
 *   কারণ-কোড নয়   → [[App\Modules\MasterData\Models\ReasonCode]] বলে **কেন উল্টানো হলো** — ফেরত,
 *                    বাতিল, ছাড়। এটা বলে **কী ধরনের টাকা এল/গেল**।
 *                    মিলিয়ে ফেললে কাউন্টারের লোক আদায়ের ড্রপডাউনে
 *                    "পণ্য নষ্ট হয়েছে" দেখতেন।
 *   পক্ষের ধরন নয় → [[App\Modules\MasterData\Models\PartyType]] বলে গ্রাহকটা পাইকারি না খুচরা।
 *   খাত নয়        → [[Account]] হিসাবের ভাষা; এটা ব্যবসার ভাষা, আর
 *                    এই তালিকাই দুইটার মাঝের অনুবাদক।
 *
 * ── Category ও Sub Category একই টেবিলে ───────────────────────────────
 * `parent_id` NULL মানে এটা একটা Category; ভরা মানে Sub Category।
 * পর্দায় দুইটা ড্রপডাউন, টেবিলে একটাই গাছ — কারণ তিন স্তর লাগলে
 * তখন কেবল আরেকটা সারি লাগবে, আরেকটা টেবিল নয়।
 *
 * ⚠️ গাছটা **দুই স্তরেই** সীমাবদ্ধ রাখা হয়েছে [[hasParent]]-এর পাহারায়
 * নয়, ফর্মের তালিকায়: Sub Category-র ড্রপডাউনে কেবল মা-হীন সারিগুলো
 * ওঠে, তাই নাতি বসানোর পথ পর্দায় নেই। ডাটাবেজ গভীরতা আটকায় না —
 * ইচ্ছাকৃত, কারণ কাল তিন স্তর লাগলে যেন মাইগ্রেশন না লাগে।
 */
class MoneyCategory extends Model implements Drillable
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use IsMasterRecord;
    use SoftDeletes;

    protected $table = 'acc_money_categories';

    /** আদায়ের শ্রেণি — টাকা ভেতরে আসে। */
    public const RECEIPT = 'receipt';

    /** প্রদানের শ্রেণি — টাকা বেরিয়ে যায়। */
    public const PAYMENT = 'payment';

    /**
     * দুইমুখী।
     *
     * ⓘ "ভুল শোধরানো" বা "আন্তঃশাখা সমন্বয়" সত্যিই দুইদিকেই লাগে, আর
     * দুইবার লিখতে বললে একই জিনিসের দুইটা সারি হত — যেটার পুরো কারণ
     * [[App\Modules\MasterData\Models\PartyType::BOTH]]-এ আগেই লেখা আছে।
     */
    public const BOTH = 'both';

    /** @var list<string> */
    public const CONTEXTS = [self::RECEIPT, self::PAYMENT, self::BOTH];

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn',
        'context', 'parent_id', 'account_id',
        'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * ⓘ জেনেরিকটা আলঙ্কারিক নয়, **দরকারি**: ছাড়া গেলে স্ট্যাটিক
     * বিশ্লেষণে `$this->parent` কেবল একটা `Model`, আর তখন নিচের
     * [[resolvedAccountId()]]-এ `->account_id` "অজানা ঘর" বলে ধরা পড়ে।
     *
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * এই শ্রেণিতে টাকা বসলে কোন খাতে — সন্তানের নিজের, নয়তো মায়ের।
     *
     * ── কেন মায়ের খাতে ফিরে যাওয়া ───────────────────────────────────
     * বেশিরভাগ ডিপোতে "বিক্রয়ের বকেয়া আদায়" শ্রেণির নিচে দশটা উপ-শ্রেণি
     * থাকবে (নগদ গ্রাহক, ডিলার, প্রতিষ্ঠান…) আর সবগুলোই একই প্রাপ্য
     * খাতে যায়। দশ জায়গায় একই খাত বসাতে বললে নয় জায়গায় ঠিক বসত আর
     * দশম জায়গায় কেউ একদিন অন্যটা বাছত — আর সেটা কেউ ধরত না।
     *
     * ⚠️ `null` ফিরতে পারে, আর সেটা ফাঁক নয়: খাত না বাঁধা থাকলে
     * ব্যবহারকারী ভাউচারেই নিজে বাছেন। ডাকার জায়গায় সেটা সামলাতে হবে।
     */
    public function resolvedAccountId(): ?int
    {
        if ($this->account_id !== null) {
            return (int) $this->account_id;
        }

        return $this->parent?->account_id === null
            ? null
            : (int) $this->parent->account_id;
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'money_category';
    }

    public function drillDocumentNo(): string
    {
        return $this->code;
    }

    public function drillLabel(): string
    {
        return $this->name();
    }

    public function drillRoute(): array
    {
        return ['master_data.money_category.edit', ['id' => $this->id]];
    }
}

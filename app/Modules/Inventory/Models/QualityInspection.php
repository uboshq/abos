<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserBranch;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * একটা পরিদর্শনের কাগজ — কে দেখল, কী দেখল, আর কী সিদ্ধান্ত নিল।
 *
 * ── ⛔ এর আগে সিদ্ধান্তটা হত, কাগজটা হত না ────────────────────────────
 * মাল এলে গুদামের লোক দেখে নিতেন, খারাপ হলে আটকে দিতেন। ⚠️ তিন মাস
 * পরে সরবরাহকারী বলতেন *"আমার মাল খারাপ ছিল না"*, আর আমাদের হাতে
 * থাকত কেবল একটা আটকানোর সারি — কে দেখেছিল, কী দেখে বাতিল করেছিল,
 * কিছুই নয়।
 *
 * ── ⚠️ তিনটা পরিমাণ, একটা "ফল" নয় ───────────────────────────────────
 * ⓘ একই চালানে পঞ্চাশ বস্তার চল্লিশটা ভালো আর দশটা খারাপ হতে পারে।
 * ⛔ একটা ফল বসালে ঐ চল্লিশটাও আটকে যেত, আর যে মালটা ঠিক আছে সেটাও
 * বিক্রির বাইরে চলে যেত।
 */
class QualityInspection extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use ScopedToUserBranch;
    use SoftDeletes;

    protected $table = 'inv_quality_inspections';

    /**
     * ⭐ পরিদর্শনের ফল — মালিকের স্পেকের শব্দেই।
     *
     * ── ⓘ কেন এটা [[DocumentStatus]] নয় ─────────────────────────────
     * ওখানকার শব্দগুলো কাগজের **জীবনচক্রের** (খসড়া · নিশ্চিত · বাতিল),
     * ⚠️ আর এগুলো একটা **রায়**। ⛔ দুইটা এক জায়গায় রাখলে "বাতিল"
     * শব্দটার দুইটা মানে হত: কাগজটা বাতিল, নাকি মালটা বাতিল।
     */
    /**
     * ⭐ সংযুক্তির খাতায় এই কাগজটার নাম — ২৫ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ [[AttachmentEngine]] তিনটা জিনিস দিয়ে ফাইল খোঁজে: মডিউল, কাগজের
     * ধরন, আর id। ⚠️ বাকি মডিউলগুলো ধরনটা `drillSourceType()` থেকে নেয়,
     * কিন্তু পরিদর্শনের কাগজ [[Drillable]] নয় — ⛔ কেবল একটা নামের জন্য
     * ড্রিল-ডাউনের গোটা চুক্তি বসানো অপচয়।
     *
     * ⚠️ নামটা **কখনো বদলানো যাবে না**: বদলালে আগের সব সনদ ও ছবি
     * খাতায় থেকে যেত আর কোনো পর্দা ওগুলো খুঁজে পেত না — ⓘ ফাইল হারায়
     * না, কেবল পথটা হারায়, আর সেটা আরও খারাপ কারণ কেউ টের পায় না।
     */
    public const PAPER_ENTITY = 'quality_inspection';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const QUARANTINE = 'quarantine';

    public const REJECTED = 'rejected';

    /** @var list<string> */
    public const RESULTS = [
        self::PENDING,
        self::APPROVED,
        self::QUARANTINE,
        self::REJECTED,
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'inspected_on',
        'product_id', 'batch_id', 'warehouse_id',
        'inspected_qty', 'accepted_qty', 'rejected_qty', 'disposed_qty',
        'criteria', 'remarks', 'source_type', 'source_id',
        'status', 'inspected_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'inspected_on' => 'date',
            'inspected_qty' => 'decimal:4',
            'accepted_qty' => 'decimal:4',
            'rejected_qty' => 'decimal:4',
            'disposed_qty' => 'decimal:4',
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

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * এখনো রায় হয়নি এমন কাগজ।
     *
     * ⓘ এই একটাই প্রশ্ন নিয়ে মানুষ রোজ পর্দাটা খোলে: *"কোন মালটা
     * এখনো দেখা বাকি"* — কারণ ততক্ষণ ঐ মাল বিক্রির বাইরে।
     */
    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /**
     * ⚠️ যে পরিমাণটা এখনো কোনো দিকে যায়নি।
     *
     * ⓘ গৃহীত আর বাতিল যোগ করে পরিদর্শিত পরিমাণে না পৌঁছালে বাকিটা
     * ঝুলে আছে — ⛔ আর ঝুলে থাকা মাল কারও খাতায় নেই, তাই সেটাই
     * সবচেয়ে সহজে হারায়।
     */
    public function undecidedQty(): string
    {
        return bcsub(
            (string) $this->inspected_qty,
            bcadd((string) $this->accepted_qty, (string) $this->rejected_qty, 4),
            4,
        );
    }
}

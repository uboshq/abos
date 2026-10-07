<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\IsMasterRecord;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * গুদামের ভিতরের একটা জায়গা — তাক, র‍্যাক, ঠান্ডা ঘর, আলমারি।
 *
 * ── নামটা "তাক" নয় কেন ──────────────────────────────────────────────
 * ⚠️ এগারোটা শিল্প। ফার্মেসিতে এটা "নারকোটিক আলমারি", হিমাগারে "চেম্বার
 * ৩", গার্মেন্টে "ফ্লোর ২ / বান্ডিল এরিয়া", আর ছোট দোকানে জিনিসটাই নেই।
 * **`Rack` নাম দিলে ক্লাসটাই একটা শিল্পের কথা বলত**, আর বাকি দশটার
 * পর্দায় ভুল শব্দ ছাপা হত।
 *
 * ⓘ নামটা কোম্পানি নিজে লেখে (`name_en`/`name_bn`), তাই আমাদের শব্দটা
 * কেবল ভিতরের, কোথাও দেখা যায় না।
 *
 * ── কেন `ScopedToUserWarehouse` নেই ────────────────────────────────
 * ⛔ ওই স্কোপটা `warehouse_id` কলাম দেখে ব্যবহারকারীর গুদামে সীমাবদ্ধ
 * করে — এখানে কলামটা আছে, তাই লোভ হয়। কিন্তু তাকের তালিকা **সেটিংসের
 * পর্দায়ও** লাগে, যেখানে অ্যাডমিন সব গুদামের তাক সাজান। স্কোপটা বসালে
 * তিনি নিজের গুদামেরটা ছাড়া কিছুই দেখতেন না, আর কারণটা কোথাও লেখা
 * থাকত না।
 *
 * ⭐ বসানোর পর্দায় সীমাটা আসে **গুদাম ধরে**, ব্যবহারকারী ধরে নয়:
 * যে গুদামে মাল এসেছে, সেই গুদামের তাকগুলোই দেখানো হয় — আর সেটাই
 * সঠিক সীমা।
 */
class StorageLocation extends Model
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use IsMasterRecord;
    use SoftDeletes;

    protected $table = 'inv_storage_locations';

    /**
     * তিনটা গভীরতা — মালিকের ছবির Block ▸ Rack ▸ Shelf।
     *
     * ⚠️ নামগুলো এখানে **নেই**, ইচ্ছাকৃতভাবে — কেবল সংখ্যা। ফার্মেসিতে
     * এগুলো "ঘর ▸ আলমারি ▸ থাক", হিমাগারে "চেম্বার ▸ সারি ▸ স্তর"।
     * ⛔ ইংরেজি শব্দটা কোডে বসালে ওটা প্রতিটা গ্রাহকের পর্দায় ছাপা
     * হত। শব্দ ভাষার ফাইলে (`inventory::field.depth_1` …)।
     */
    public const BLOCK = 1;

    public const RACK = 2;

    public const SHELF = 3;

    /** @var list<int> */
    public const DEPTHS = [self::BLOCK, self::RACK, self::SHELF];

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'parent_id', 'depth',
        'code', 'name_en', 'name_bn', 'sort', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort' => 'integer',
            'depth' => 'integer',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * উপর থেকে নিচ পর্যন্ত পুরো পথ — "ব্লক ক ▸ র‍্যাক ২ ▸ শেলফ ৩"।
     *
     * ⚠️ গুদামের লোক তাকের নামটা একা শুনে কিছু বোঝেন না; "শেলফ ৩"
     * প্রতিটা র‍্যাকেই আছে। ⓘ খুঁজতে গেলে গোটা পথটাই দরকার, তাই
     * ড্রিল-ডাউন আর ছাপা কাগজে এটাই যায়।
     */
    public function path(string $separator = ' ▸ '): string
    {
        $parts = [];

        for ($node = $this; $node !== null; $node = $node->parent) {
            array_unshift($parts, $node->name());
        }

        return implode($separator, $parts);
    }

    /**
     * একটা গভীরতার সারিগুলো — Block, বা Rack, বা Shelf।
     *
     * @param  Builder<StorageLocation>  $query
     */
    public function scopeAtDepth(Builder $query, int $depth): Builder
    {
        return $query->where('depth', $depth);
    }

    /**
     * গুদামের লোক যে ক্রমে হাঁটেন।
     *
     * ⚠️ `sort` আগে, তারপর `code` — বর্ণানুক্রমে "র‍্যাক ১০" আসে
     * "র‍্যাক ২"-এর আগে, আর গুদামে ব্যাপারটা ঠিক উল্টো।
     *
     * @param  Builder<StorageLocation>  $query
     */
    public function scopeInWalkingOrder(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('code');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\IsMasterRecord;
use App\Core\Concerns\ScopedToUserWarehouse;
use App\Core\Contracts\Drillable;
use App\Core\Support\ViewedBranch;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * একটা গুদাম।
 *
 * শাখার নিচে, ইচ্ছাকৃতভাবে: একটা ডিপোর তিনটা শাখা থাকলে নেত্রকোনার মাল
 * ময়মনসিংহের তালিকায় দেখানো মানে সেলসম্যান এমন জিনিস বেচবেন যা তার
 * শাখায় নেই — আর সেটা ধরা পড়বে মাল দিতে গিয়ে, ক্রেতার সামনে।
 */
class Warehouse extends Model implements Drillable
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use IsMasterRecord;
    use ScopedToUserWarehouse;
    use SoftDeletes;

    /**
     * গুদামের নিজের তালিকায় ঘরটা `id`, `warehouse_id` নয়।
     *
     * এই একটা লাইন না থাকলে সীমাবদ্ধ ব্যবহারকারীর গুদামের
     * তালিকা ও প্রতিটা ড্রপডাউন সব গুদামই দেখাত — আর ছাঁকনিটা
     * কেবল মজুদের সংখ্যায় খাটত, নামের তালিকায় নয়।
     */
    public function warehouseScopeColumn(): string
    {
        return 'id';
    }

    /**
     * ⭐ কাঁচা মজুদ-কোয়েরির জন্য: এক শাখা বাছা থাকলে সেই শাখার গুদামগুলো, নইলে `null` ("সব শাখা")।
     *
     * ⓘ দেয়াল একটাই — [[ScopedToUserWarehouse]]; এটা কেবল কাঁচা টেবিল-কোয়েরির পথে তার ফল পৌঁছে দেয়,
     * কারণ ওই পথে গ্লোবাল স্কোপ চলে না। ⚠️ খালি তালিকা মানে "কিছুই নয়", "সব" নয়।
     *
     * @return list<int>|null
     */
    public static function idsInViewedBranch(): ?array
    {
        /*
         * ⭐ "সব শাখা"-তেও গুদাম-সীমিত মানুষের কেবল নিজের গুদাম — Inventory অডিট ম১৩, ৫ অক্টোবর ২০২৬।
         * ⛔ আগে "সব শাখা" মানেই `null` ("সব গুদাম"), মানুষটা গুদাম-সীমিত হলেও — কাঁচা মজুদ-কোয়েরিতে তিনি সব গুদামের
         * পরিমাণ আর মূল্য দেখতেন। ⓘ নিচের কোয়েরি নিজেই নাগালের দেয়াল মানে ([[ScopedToUserWarehouse]]), তাই তালিকাটা সেখান থেকেই।
         */
        $user = auth()->user();
        $limited = $user instanceof \App\Models\User
            && app(\App\Core\Services\DataScope::class)->idsFor($user, \App\Models\UserDataScope::WAREHOUSE) !== null;

        if (ViewedBranch::one() === null && ! $limited) {
            return null;
        }

        return static::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * ⭐ প্রধান গুদাম শাখা-প্রতি একটা — হেডারে যা-ই বাছা থাকুক — Inventory অডিট ম২২, ৫ অক্টোবর ২০২৬।
     *
     * ⛔ সাধারণ [[IsMasterRecord::makeDefault()]] অন্যদের পতাকা নামাত **দেখা যায় এমনগুলোর** মধ্যে, আর গুদাম দেখা হয়
     * হেডারের শাখা আর মানুষের গুদাম-সীমা দিয়ে: এক শাখা বাছা থাকলে প্রধান হত শাখা-প্রতি, "সব শাখা"-য় কোম্পানি-প্রতি,
     * আর গুদাম-সীমিত মানুষ বাছলে অন্য গুদামের পতাকা থেকেই যেত — দুইটা প্রধান। তখন কাগজ কোন গুদামে নামবে, তা নির্ভর করত
     * কে কবে কোন হেডারে টিক দিয়েছিলেন তার উপর।
     * ⓘ এখন নিয়ম একটাই: এই গুদামের নিজের শাখার অন্য প্রধানগুলো নামে (শাখাহীন হলে শাখাহীনগুলো), দেখার দেয়াল ছাড়া;
     * কোম্পানির দেয়াল থাকে। মালিকের *"প্রতিটা শাখা পুরোপুরি আলাদা"* (১ অক্টোবর ২০২৬)।
     */
    public function makeDefault(): static
    {
        \Illuminate\Support\Facades\DB::transaction(function () {
            static::query()
                ->withoutGlobalScopes(['user-warehouse', self::VIEWED_BRANCH])
                ->where('is_default', true)
                ->whereKeyNot($this->getKey())
                ->when($this->branch_id === null,
                    fn ($q) => $q->whereNull('branch_id'),
                    fn ($q) => $q->where('branch_id', $this->branch_id))
                ->get()
                ->each(fn ($other) => $other->forceFill(['is_default' => false])->save());

            $this->refresh()->forceFill(['is_default' => true, 'is_active' => true])->save();
        });

        return $this->fresh();
    }

    protected $table = 'inv_warehouses';

    protected $fillable = [
        'company_id', 'branch_id', 'code', 'name_en', 'name_bn',
        'address_en', 'address_bn', 'is_default', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'warehouse';
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
        return ['inventory.warehouse.index', []];
    }
}

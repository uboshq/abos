<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\IsMasterRecord;
use App\Core\Contracts\Drillable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * পক্ষের ধরন — খুচরা, পাইকারি, ডিলার, প্রতিষ্ঠান।
 *
 * enum নয়, সারি: প্রতিষ্ঠান নিজেই নতুন ধরন যোগ করতে পারবে। enum
 * লিখলে প্রতিটা নতুন ধরনের জন্য একটা রিলিজ লাগত, আর ততদিন কেউ
 * "অন্যান্য" লিখে কাজ চালাত।
 */
class PartyType extends Model implements Drillable
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use IsMasterRecord;
    use SoftDeletes;

    protected $table = 'mdm_party_types';

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn',
        'applies_to', 'is_default', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public const CUSTOMER = 'customer';

    public const SUPPLIER = 'supplier';

    public const BOTH = 'both';

    /** @var list<string> */
    public const APPLIES = [self::CUSTOMER, self::SUPPLIER, self::BOTH];

    /**
     * পরিবেশকের কোড — আর এই এক ধরনের নিজস্ব নিয়ম আছে।
     *
     * ── ⛔ কেন কেবল এই ধরনটার নাম কোডে লেখা, ১৫ সেপ্টেম্বর ২০২৬ ────────
     * বাকি ধরনগুলো (পাইকারি, খুচরা, ভোক্তা) প্রতিষ্ঠান ইচ্ছামতো যোগ ও
     * বদল করতে পারে, তাই ওদের কোনো নাম কোডে থাকা উচিত নয়। ⓘ কিন্তু
     * পরিবেশকের সাথে একটা **ব্যবসায়িক নিয়ম** জড়িয়ে আছে:
     *
     *     ⭐ এক পয়েন্টে একজনই সক্রিয় পরিবেশক।
     *
     * ⚠️ মালিকের কথা: *"এক এলাকায় একজনই পরিবেশক হয়"*। ⓘ নিয়মটা কোথাও
     * না কোথাও তো "কোন ধরনটা পরিবেশক" জানতেই হবে — আর কোড
     * (`DISTRIB`) নামের চেয়ে স্থিতিশীল, কারণ নাম বাংলায়-ইংরেজিতে
     * বদলায়, কোড বদলায় না।
     */
    public const DISTRIBUTOR = 'DISTRIB';

    /**
     * এটা কি পরিবেশক — অর্থাৎ এক-পয়েন্ট-এক-জনের নিয়মটা খাটে?
     */
    public function isDistributor(): bool
    {
        return strtoupper(trim((string) $this->code)) === self::DISTRIBUTOR;
    }

    /**
     * গ্রাহকের ধরন, বা সরবরাহকারীর।
     *
     * "both" সবসময় আসে: "প্রতিষ্ঠান" একইসাথে গ্রাহক ও সরবরাহকারী হতে
     * পারে, আর দুইবার লিখতে বলা মানে দুইটা আলাদা রেকর্ড যাদের নাম এক।
     */
    public function scopeFor(Builder $query, string $side): Builder
    {
        return $query->whereIn('applies_to', [$side, self::BOTH]);
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'party_type';
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
        return ['master_data.party_type.show', ['party_type' => $this->id]];
    }
}

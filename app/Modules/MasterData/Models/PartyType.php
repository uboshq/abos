<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
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

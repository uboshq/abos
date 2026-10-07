<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasActiveState;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\IsMasterRecord;
use App\Core\Concerns\ScopedToUserDealers;
use App\Core\Contracts\Drillable;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * দর তালিকা — খুচরা, পাইকারি, ডিলার।
 *
 * পণ্যের দর এখানে নয়: একটা তালিকায় হাজারটা পণ্য থাকে, আর পণ্যের
 * টেবিল আসবে Phase 6-এ। এই মডেলটা শুধু "কোন দরের সেট" বলে।
 */
class PriceList extends Model implements Drillable
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use IsMasterRecord;

    /*
     * ⛔ ডিলারের দেয়াল — কোনো ডিলারের নিজের দর কেবল তাঁর বিক্রয়কর্মী দেখেন (⛔১৬, ৬ অক্টোবর ২০২৬)।
     * ⓘ সাধারণ তালিকা (`customer_id` নেই) সবার — নিচের [[applyDealerWall()]] ওটাকে ছাড়ে।
     */
    use ScopedToUserDealers;
    use SoftDeletes;

    protected $table = 'mdm_price_lists';

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn',
        'party_type_id', 'is_default', 'is_active', 'created_by',
        // ⭐ তালিকা কার জন্য — একজন গ্রাহক বা এক এলাকা (বিক্রয়ের দর তালিকা, ৫ অক্টোবর ২০২৬); ঘর দুইটা বিক্রয়ের মাইগ্রেশনে
        'customer_id', 'location_id',
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

    public function partyType(): BelongsTo
    {
        return $this->belongsTo(PartyType::class, 'party_type_id');
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'price_list';
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
        return ['master_data.price_list.show', ['price_list' => $this->id]];
    }

    /** ⓘ সাধারণ তালিকা (কোনো ডিলারের নয়) দেয়ালের ভিতরেও দেখা যায় — কেবল অন্য ডিলারের বিশেষ দর লুকায়। */
    public function applyDealerWall(Builder $builder, QueryBuilder $dealerIds): void
    {
        $column = $this->getTable().'.'.$this->dealerScopeColumn();

        $builder->where(fn (Builder $q) => $q->whereNull($column)->orWhereIn($column, $dealerIds));
    }
}

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
 * একটা গাড়ি — বহরের একটা সারি।
 *
 * চালান ও গেটপাসে নম্বরপ্লেট ছাপা হয়, তাই সেটা এখানে বাধ্যতামূলক।
 * গাড়ি কখনো মোছা হয় না, নিষ্ক্রিয় হয় — পুরনো চালানে গাড়িটার নাম
 * থাকতে হবে, নাহলে "কোন গাড়িতে গিয়েছিল" প্রশ্নের উত্তর হারায়।
 */
class Vehicle extends Model implements Drillable
{
    use BelongsToCompany;
    use HasActiveState;
    use HasFactory;
    use HasPublicId;
    use IsMasterRecord;
    use SoftDeletes;

    protected $table = 'mdm_vehicles';

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_bn',
        'registration_no', 'vehicle_type_id', 'capacity_kg', 'owner_type',
        'driver_name', 'driver_phone',
        'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'capacity_kg' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    /**
     * নিজের না ভাড়ার।
     *
     * তালিকাটা সারি নয় বলে ধ্রুবক: গাড়ি হয় নিজের, নয় ভাড়ার — আর
     * পার্থক্যটা হিসাবের, পছন্দের নয় (ভাড়া হলে ভাড়ার খরচ বসে)।
     *
     * @var list<string>
     */
    public const OWNER_TYPES = ['own', 'rented'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<VehicleType, $this> */
    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }

    /** নম্বরপ্লেট দিয়েও খোঁজা যায় — কাগজে ওটাই লেখা থাকে। */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($term)).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('code', 'like', $like)
                ->orWhere('name_en', 'like', $like)
                ->orWhere('name_bn', 'like', $like)
                ->orWhere('registration_no', 'like', $like)
                ->orWhere('driver_name', 'like', $like);
        });
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'vehicle';
    }

    public function drillDocumentNo(): string
    {
        return (string) $this->registration_no;
    }

    public function drillLabel(): string
    {
        return $this->name();
    }

    public function drillRoute(): array
    {
        return ['master_data.vehicle.edit', ['id' => $this->id]];
    }
}

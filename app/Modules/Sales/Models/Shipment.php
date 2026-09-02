<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserBranch;
use App\Core\Contracts\Drillable;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * শিপমেন্ট — একটা গাড়ির একটা দিন।
 *
 * খসড়া মানে গাড়ি লোড হচ্ছে, নিশ্চিত মানে বেরিয়ে গেছে, সম্পন্ন মানে
 * ফিরেছে ও প্রতিটা চালানের হিসাব বুঝে নেওয়া হয়েছে।
 *
 * স্টকে বা খতিয়ানে এই কাগজ কিছুই বসায় না — মাল আগেই চালানে বেরিয়েছে।
 * এটার কাজ একটাই, আর সেটা কম নয়: **যা ফেরেনি তার নাম বলা**।
 */
class Shipment extends Model implements Drillable
{
    use BelongsToCompany;
    use HasDocumentStatus;
    use HasPublicId;
    use IsAudited;
    use ScopedToUserBranch;
    use SoftDeletes;

    protected $table = 'sal_shipments';

    protected $fillable = [
        'company_id', 'branch_id', 'financial_year_id', 'document_no',
        'trx_date', 'warehouse_id', 'vehicle_id', 'vehicle_no',
        'driver_name', 'helper_name',
        'route_location_id', 'opening_km', 'closing_km',
        'dispatched_at', 'returned_at',
        'status', 'narration', 'created_by',
        'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'dispatched_at' => 'datetime',
            'returned_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'opening_km' => 'decimal:4',
            'closing_km' => 'decimal:4',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ShipmentLine::class)->orderBy('line_no');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'route_location_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * কাগজে যে নম্বরটা ছাপা হবে।
     *
     * বহরের গাড়ির নম্বর মাস্টার থেকে, কারণ নম্বরপ্লেট বদলালে একবার
     * শোধরালেই পুরনো ট্রিপগুলোও ঠিক নম্বর দেখায় — চালানে ঠিক এই একই
     * যুক্তি।
     */
    public function vehiclePlate(): string
    {
        return $this->vehicle?->registration_no ?? (string) $this->vehicle_no;
    }

    /**
     * চালকের নাম।
     *
     * ── কেন কর্মী তালিকার সারি নয় ───────────────────────────────────
     * প্রথমে `hr_employees`-এ FK ছিল, যাতে চালক ধরে যোগ করা যায়।
     * সীমানার পরীক্ষা ধরল — বিক্রয় Hr-এর ভেতরে হাত দিচ্ছে, অঘোষিত।
     * ঘোষণা করলে সেটা একটা মিথ্যা কথা হত: বিক্রয় করতে বেতনের খাতা
     * লাগে না। আর ভাড়ার গাড়ির চালক কর্মীই নন।
     *
     * চালান ও গাড়ির মাস্টার দুইটাই চালককে নাম হিসেবেই রাখে; ট্রিপও
     * তাই রাখে।
     */
    public function driverName(): string
    {
        return (string) $this->driver_name;
    }

    /**
     * পথে থাকা গাড়ি — বেরিয়েছে, ফেরেনি।
     *
     * এটাই "এখন কোন কোন গাড়ি বাইরে" প্রশ্নটার উত্তর, আর ওটাই দিনের
     * মাঝামাঝি সবচেয়ে বেশি জিজ্ঞাসিত।
     */
    public function scopeOnTheRoad(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::CONFIRMED);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('document_no', 'like', "%{$term}%")
                ->orWhere('vehicle_no', 'like', "%{$term}%")
                ->orWhere('driver_name', 'like', "%{$term}%")
                ->orWhereHas('vehicle', fn (Builder $v) => $v->where('registration_no', 'like', "%{$term}%"));
        });
    }

    // ── Drillable ───────────────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'shipment';
    }

    public function drillDocumentNo(): string
    {
        return $this->document_no;
    }

    public function drillLabel(): string
    {
        $plate = $this->vehiclePlate();

        return $plate === '' ? $this->document_no : $plate;
    }

    public function drillRoute(): array
    {
        return ['sales.shipment.show', ['shipment' => $this->id]];
    }
}

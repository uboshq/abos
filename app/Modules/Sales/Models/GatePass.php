<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserBranch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * গেট পাস — মাল গেট পেরোয় যে কাগজ হাতে (মালিকের "Delivery Processing" নকশা, ২৮ সেপ্টেম্বর ২০২৬)।
 *
 * ⓘ জন্ম রওনার মুহূর্তে, নিজে ([[GatePassService::issueFor()]]); তারপর কেবল দেখা আর ছাপা।
 * ⛔ বদলানো যায় না — কেবল বাতিল, কারণসহ, আর বাতিলটাও নিরীক্ষায় ([[IsAudited]])।
 */
class GatePass extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    /*
     * ⛔ শাখার দেয়াল — চূড়ান্ত অডিট ⛔১৪, ৩০ সেপ্টেম্বর ২০২৬। ⓘ সারিতে `branch_id` বসত, কিন্তু ছাঁকনি ছিল
     * না: এক শাখার কর্মী অন্য শাখার গেট পাস দেখতেন আর বাতিল করতেন। ⭐ এখন তার চালান আর বিলের মতোই।
     */
    use ScopedToUserBranch;

    public const ISSUED = 'issued';

    public const CANCELLED = 'cancelled';

    protected $table = 'sal_gate_passes';

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'sale_no',
        'delivery_challan_id', 'delivery_event_id', 'shipment_id',
        'vehicle_no', 'driver_name', 'driver_phone',
        'issued_by', 'issued_at',
        'status', 'cancel_reason', 'cancelled_by', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function challan(): BelongsTo
    {
        return $this->belongsTo(DeliveryChallan::class, 'delivery_challan_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(DeliveryEvent::class, 'delivery_event_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    /** গেট পাস বা চালানের নম্বর, গ্রাহকের নাম, গাড়ির নম্বর — তালিকার খোঁজ। */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('document_no', 'like', "%{$term}%")
                ->orWhere('vehicle_no', 'like', "%{$term}%")
                ->orWhereHas('challan', fn (Builder $c) => $c->search($term));
        });
    }
}

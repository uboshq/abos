<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use App\Modules\Sales\Services\DeliveryStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * একটা চালানের ডেলিভারি — এখন কোন ধাপে।
 *
 * ⓘ প্রতি চালানে একটাই সারি (ডাটাবেসের ইউনিক সূচক)। ইতিহাস
 * [[DeliveryEvent]]-এ; এটা কেবল শেষ কথাটা, যাতে তালিকা ও গোনা
 * ইতিহাস না হেঁটেই চলে।
 *
 * ⛔ সরাসরি `update()` নয় — ধাপ বদলায় কেবল [[DeliveryStageService]]
 * দিয়ে, কারণ বদলের নিয়ম ও ইতিহাসের সারি ওখানেই একসাথে বসে।
 */
class DeliveryState extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'sal_delivery_states';

    protected $fillable = [
        'company_id', 'delivery_challan_id', 'stage', 'stage_at', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['stage_at' => 'datetime'];
    }

    public function challan(): BelongsTo
    {
        return $this->belongsTo(DeliveryChallan::class, 'delivery_challan_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeliveryEvent::class, 'delivery_challan_id', 'delivery_challan_id')
            ->orderBy('occurred_at')->orderBy('id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function label(): string
    {
        return DeliveryStage::label((string) $this->stage);
    }

    /**
     * ট্যাবের ছাঁকনি — `open` মানে হাতে থাকা কাজ, নাহলে একটা ধাপ।
     */
    public function scopeInTab(Builder $query, string $tab): Builder
    {
        if ($tab === 'open') {
            return $query->whereIn('stage', DeliveryStage::OPEN);
        }

        return $query->where('stage', $tab);
    }
}

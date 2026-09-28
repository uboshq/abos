<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Sales\Services\DeliveryStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ডেলিভারির একটা ধাপ-বদল — কে, কখন, কোথা থেকে কোথায়, কেন।
 *
 * ⓘ শুধু যোগ হয়, কখনো বদলায় না। ভুল হলে পরের সারিটাই শোধরায়
 * (যেমন ট্রিপের চালক সন্ধ্যায় কথা বদলালে), আগেরটা মোছে না — নাহলে
 * "সকালে কী বলা হয়েছিল" প্রশ্নের উত্তর হারাত।
 *
 * ⭐ প্রমাণের ঘর — কে মাল বুঝে নিলেন (নাম ও ফোন)। ডেলিভারি নিয়ে প্রশ্ন
 * উঠলে এটাই প্রথম কাগজ; সই করা চালানের ছবি থাকে চালানের সংযুক্তিতে।
 */
class DeliveryEvent extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'sal_delivery_events';

    protected $fillable = [
        'company_id', 'delivery_challan_id', 'from_stage', 'to_stage',
        'source', 'shipment_id', 'reason_code_id', 'note',
        'receiver_name', 'receiver_phone', 'occurred_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function challan(): BelongsTo
    {
        return $this->belongsTo(DeliveryChallan::class, 'delivery_challan_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function reasonCode(): BelongsTo
    {
        return $this->belongsTo(ReasonCode::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryEventLine::class)->orderBy('id');
    }

    public function label(): string
    {
        return DeliveryStage::label((string) $this->to_stage);
    }
}

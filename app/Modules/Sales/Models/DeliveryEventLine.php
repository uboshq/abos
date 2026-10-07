<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * আংশিক ডেলিভারির একটা সারি — চালানের কোন সারির কতটা ক্রেতা নিলেন।
 *
 * ⓘ বাকিটা ফেরে বিক্রয় ফেরতের কাগজে; এই সারি কেবল **বলে** কতটা
 * গেল, স্টকে কিছুই বসায় না।
 */
class DeliveryEventLine extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'sal_delivery_event_lines';

    protected $fillable = [
        'company_id', 'delivery_event_id', 'delivery_challan_line_id', 'delivered_qty',
        // ⭐ ভাঙা পৌঁছানো পরিমাণ — ধাপ ৭, ৬ অক্টোবর ২০২৬; আটকে রাখা মজুদে ফেরত ([[ShortDeliveryReturn]])
        'damaged_qty',
    ];

    protected function casts(): array
    {
        return ['delivered_qty' => 'decimal:4', 'damaged_qty' => 'decimal:4'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(DeliveryEvent::class, 'delivery_event_id');
    }

    public function challanLine(): BelongsTo
    {
        return $this->belongsTo(DeliveryChallanLine::class, 'delivery_challan_line_id');
    }
}

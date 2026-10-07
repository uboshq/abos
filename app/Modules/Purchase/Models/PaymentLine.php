<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** পরিশোধের একটা লাইন — কোন বিলের বিপরীতে কত। */
class PaymentLine extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'pur_payment_lines';

    protected $fillable = [
        'company_id', 'payment_id', 'purchase_bill_id', 'amount', 'line_no',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class, 'purchase_bill_id');
    }
}

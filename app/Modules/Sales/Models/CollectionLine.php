<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** আদায়ের একটা লাইন — কোন বিলের বিপরীতে কত। */
class CollectionLine extends Model
{
    use HasPublicId;
    use IsAudited;

    protected $table = 'sal_collection_lines';

    protected $fillable = [
        'collection_id', 'sales_invoice_id', 'amount', 'line_no',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }
}

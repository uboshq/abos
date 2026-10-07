<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ইস্যু হওয়া একটা নম্বরের রেকর্ড।
 *
 * এটা না রাখলে "৪৭ নম্বর ভাউচার কোথায়" প্রশ্নের উত্তর কেবল অনুমান হত।
 * থাকলে উত্তর হয়: কে কখন নিয়েছিল, কোন ডকুমেন্টে বসেছিল, আর বাতিল হলে কেন।
 */
class IssuedNumber extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;

    protected $fillable = [
        'company_id', 'number_series_id', 'document_no', 'sequence',
        'source_type', 'source_id', 'issued_by', 'issued_at',
        'is_voided', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'issued_at' => 'datetime',
            'is_voided' => 'boolean',
        ];
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(NumberSeries::class, 'number_series_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** একটা ডকুমেন্ট টাইপের নম্বর সিরিজ। NumberSeriesEngine ছাড়া কেউ next_number বদলায় না। */
class NumberSeries extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $table = 'number_series';

    protected $fillable = [
        'company_id', 'branch_id', 'financial_year_id', 'module', 'doc_type',
        'prefix', 'suffix', 'format', 'padding', 'next_number', 'start_number',
        'reset_yearly', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'padding' => 'integer',
            'next_number' => 'integer',
            'start_number' => 'integer',
            'reset_yearly' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}

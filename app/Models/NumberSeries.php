<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** একটা ডকুমেন্ট টাইপের নম্বর সিরিজ। NumberSeriesEngine ছাড়া কেউ next_number বদলায় না। */
class NumberSeries extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;

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

    /**
     * পরের নম্বরটা অডিটে যায় না।
     *
     * প্রতিটা ডকুমেন্ট তৈরিতে এটা এক বাড়ে। লগ করলে অডিট তালিকার অর্ধেক
     * সারি হত "next_number: ৬ → ৭", আর তার ফাঁকে কে দর বদলাল সেটা খুঁজে
     * পাওয়া যেত না। নম্বরটা কোথায় গেল তা এমনিতেই জানা — যে ডকুমেন্ট
     * সেটা নিল, সেটা নিজেই অডিটে আছে।
     *
     * উপসর্গ, বিন্যাস বা সিরিজ বন্ধ করা — এগুলো মানুষের সিদ্ধান্ত, আর
     * সেগুলো আগের মতোই লগ হয়।
     *
     * @return list<string>
     */
    public function auditIgnores(): array
    {
        return ['next_number'];
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * একটা নির্ধারিত রিপোর্ট — কোনটা, কখন, কার কাছে।
 *
 * ফাইলটা সূচিমতো নিজে থেকে তৈরি হয়, রোজ সকালে কারো হাতে চাওয়ার বদলে।
 * `created_by` অনুমতির মালিক — ক্রন এঁর প্রসঙ্গে রিপোর্ট রেন্ডার করে, তাই
 * যে ক্রয়মূল্য পর্দায় দেখতে পান না তা তাঁর ফাইলেও থাকে না।
 */
class ReportSchedule extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'report_key', 'filters', 'format',
        'frequency', 'at_time', 'day_of_week', 'day_of_month', 'on_month_end',
        'timezone', 'recipients', 'created_by', 'is_active',
        'next_run_at', 'last_run_at', 'last_status',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'recipients' => 'array',
            'on_month_end' => 'boolean',
            'is_active' => 'boolean',
            'day_of_week' => 'integer',
            'day_of_month' => 'integer',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * যে সূচিগুলোর সময় হয়েছে — সব কোম্পানি জুড়ে।
     *
     * ⚠️ ক্রনের নিজের কোম্পানি-প্রসঙ্গ নেই, তাই company global scope এখানে
     * সরানো হয়: নাহলে `company_id = null`-এ ছেঁকে কিছুই আসত না, আর একটা
     * সূচিও কখনো চলত না। runner প্রতিটা সারির নিজের company_id ধরে প্রসঙ্গ
     * বসিয়ে তবেই রিপোর্ট চালায়।
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->withoutGlobalScope('company')
            ->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * প্রাপক ব্যবহারকারীরা — অভ্যন্তরীণ, id ধরে।
     *
     * company-প্রসঙ্গ বসানো অবস্থায় ডাকা হয়, তাই অন্য কোম্পানির id ভুলেও
     * এলে global scope-ই ছেঁকে বাদ দেয়।
     *
     * @return Collection<int, User>
     */
    public function recipientUsers(): Collection
    {
        $ids = array_values(array_filter((array) $this->recipients));

        if ($ids === []) {
            return collect();
        }

        return User::query()->whereIn('id', $ids)->get();
    }
}

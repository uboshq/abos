<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserBranch;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * মাল গোনা — খাতায় যা লেখা, তাকে যা সত্যিই আছে।
 *
 * নগদ গণনার ([[CashCount]]) যমজ, শুধু টাকার বদলে মাল আর এক নোটের বদলে
 * বহু পণ্য (তাই লাইন আছে)। মিলে গেলে কোনো সমন্বয় হয় না; না মিললে
 * পার্থক্যটা অনুমোদনে একটা স্টক-সমন্বয় হয়ে বসে।
 *
 * ── অবস্থা, বিদ্যমান শব্দভাণ্ডারেই ──────────────────────────────────
 *     draft     — গোনা লেখা হয়েছে, খাতা এখনো বদলায়নি
 *     confirmed — অনুমোদিত, পার্থক্যগুলো সমন্বয় হয়ে বসেছে
 *
 * খসড়া অবস্থায় কিছুই নড়ে না — গণনাকারী নিশ্চিন্তে লেখেন, ভুল হলে
 * শুধরান। অনুমোদন আলাদা হাতে, কারণ পার্থক্য মানে মাল কম বা বেশি, আর
 * সেটা গণনাকারী নিজে নিষ্পত্তি করলে গোনার কোনো মানে থাকে না।
 */
class StockCount extends Model
{
    use BelongsToCompany;
    use HasDocumentStatus;
    use HasPublicId;
    use IsAudited;
    use ScopedToUserBranch;
    use SoftDeletes;

    protected $table = 'inv_stock_counts';

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'count_date',
        'warehouse_id', 'narration', 'status',
        'counted_by', 'approved_by', 'approved_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'count_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class)->orderBy('id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('document_no', 'like', $like)
            ->orWhere('narration', 'like', $like));
    }

    public function isApproved(): bool
    {
        return $this->status === DocumentStatus::CONFIRMED;
    }

    /**
     * গোনা সব লাইন খাতার সাথে মিলে গেছে — কোনো পার্থক্যই নেই।
     *
     * মিললে অনুমোদনে কোনো সমন্বয় বসে না, শুধু রেকর্ড থাকে যে ওই দিন
     * গুদামটা গোনা হয়েছিল আর সব ঠিক ছিল।
     */
    public function matches(): bool
    {
        foreach ($this->lines as $line) {
            if (bccomp((string) $line->difference, '0', 4) !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * শুধু যেসব লাইনে পার্থক্য আছে — অনুমোদনে এগুলোই সমন্বয় হবে।
     *
     * @return Collection<int, StockCountLine>
     */
    public function variances(): Collection
    {
        return $this->lines->filter(
            fn (StockCountLine $line) => bccomp((string) $line->difference, '0', 4) !== 0,
        )->values();
    }
}

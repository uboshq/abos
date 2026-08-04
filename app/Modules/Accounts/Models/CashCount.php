<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Contracts\Drillable;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * নগদ গণনা — খাতায় যা আছে আর হাতে যা আছে, দুইটা মিলিয়ে দেখা।
 *
 * মিলে গেলে কোনো হিসাব হয় না। না মিললে পার্থক্যটা একটা জাবেদা হয়ে বসে,
 * কারণ খাতার সংখ্যাটা তখন মিথ্যা — আর মিথ্যা সংখ্যা রেখে দিলে পরদিনের
 * গণনাও ভুল হবে, আর কোন দিনের ভুল তা আর বলা যাবে না।
 */
class CashCount extends Model implements Drillable
{
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    /**
     * বাংলাদেশি নোট ও কয়েন, বড় থেকে ছোট।
     *
     * গণনার ফর্ম এই ক্রমেই সাজে — ক্যাশিয়ার বড় নোট আগে গোনে, আর
     * ফর্মের ক্রম হাতের ক্রমের সাথে না মিললে প্রতিটা গণনায় চোখ
     * এদিক-ওদিক করতে হয়।
     *
     * @var list<int>
     */
    public const DENOMINATIONS = [1000, 500, 200, 100, 50, 20, 10, 5, 2, 1];

    protected $fillable = [
        'company_id', 'branch_id', 'financial_year_id', 'document_no', 'trx_date',
        'cash_till_id', 'counted_amount', 'expected_amount', 'difference',
        'denominations', 'adjustment_voucher_id', 'narration', 'status',
        'counted_by', 'approved_by', 'approved_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'counted_amount' => 'decimal:4',
            'expected_amount' => 'decimal:4',
            'difference' => 'decimal:4',
            'denominations' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    public function till(): BelongsTo
    {
        return $this->belongsTo(CashTill::class, 'cash_till_id');
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'adjustment_voucher_id');
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($term)).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('document_no', 'like', $like)
            ->orWhere('narration', 'like', $like));
    }

    public function isApproved(): bool
    {
        return $this->status === DocumentStatus::CONFIRMED;
    }

    public function matches(): bool
    {
        return bccomp((string) $this->difference, '0', 4) === 0;
    }

    /** হাতে বেশি পাওয়া গেছে — খাতার চেয়ে বেশি। */
    public function isSurplus(): bool
    {
        return bccomp((string) $this->difference, '0', 4) > 0;
    }

    /**
     * নোটের হিসাব থেকে মোট।
     *
     * ফর্মে যা টাইপ করা হয়েছে তা থেকেই গোনা হয়, ব্যবহারকারীর লেখা মোট
     * থেকে নয় — নাহলে নোটের হিসাব আর মোট দুইটা আলাদা হতে পারত, আর
     * কাগজটা নিজের সাথেই অসঙ্গত হত।
     *
     * @param  array<int|string, int|string|null>  $counts
     */
    public static function totalOf(array $counts): string
    {
        $total = '0';

        foreach (self::DENOMINATIONS as $note) {
            $qty = (int) ($counts[$note] ?? 0);

            if ($qty > 0) {
                $total = bcadd($total, bcmul((string) $note, (string) $qty, 4), 4);
            }
        }

        return $total;
    }

    /**
     * শুধু যেগুলোর সংখ্যা আছে — ছাপা ও দেখানোর জন্য।
     *
     * @return array<int, int>
     */
    public function countedNotes(): array
    {
        $out = [];

        foreach (self::DENOMINATIONS as $note) {
            $qty = (int) (($this->denominations ?? [])[$note] ?? 0);

            if ($qty > 0) {
                $out[$note] = $qty;
            }
        }

        return $out;
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'cash_count';
    }

    public function drillDocumentNo(): string
    {
        return $this->document_no;
    }

    public function drillLabel(): string
    {
        return __('accounts::menu.cash_count').' — '.$this->document_no;
    }

    public function drillRoute(): array
    {
        return ['accounts.count.show', ['count' => $this->id]];
    }
}

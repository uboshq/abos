<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserBranch;
use App\Core\Contracts\Drillable;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * এক গুদাম থেকে আরেক গুদামে মাল সরানো।
 *
 * ── তিনটা অবস্থা, বিদ্যমান শব্দভাণ্ডারেই ────────────────────────────
 *     draft     — লেখা হয়েছে, ট্রাক ছাড়েনি
 *     confirmed — রওনা দিয়েছে, মাল উৎস গুদামে আটকানো
 *     closed    — পৌঁছেছে, বুঝে নেওয়া হয়েছে
 *
 * "রওনা" আর "পৌঁছানো" আলাদা রাখার কারণটা সরল: ওই দুইয়ের মাঝখানে মালটা
 * কোনো গুদামের তাকে নেই, অথচ কোম্পানির। ওই সময়টুকু কাগজে না থাকলে মাল
 * হারালে কেউ বলতে পারত না কোথায় হারাল।
 */
class StockTransfer extends Model implements Drillable
{
    use BelongsToCompany;
    use HasDocumentStatus;
    use HasPublicId;
    use IsAudited;
    use ScopedToUserBranch;
    use SoftDeletes;

    public const STOCK_SOURCE = 'stock_transfer';

    protected $table = 'inv_transfers';

    protected $fillable = [
        'company_id', 'branch_id', 'financial_year_id', 'document_no',
        'from_warehouse_id', 'to_warehouse_id', 'trx_date',
        'dispatched_at', 'received_at',
        'status', 'narration', 'created_by',
        'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class)->orderBy('line_no');
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** রাস্তায় আছে — রওনা দিয়েছে, পৌঁছায়নি। */
    public function scopeOnTheWay(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::CONFIRMED);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where('document_no', 'like', "%{$term}%");
    }

    // ── Drillable ───────────────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'stock_transfer';
    }

    public function drillDocumentNo(): string
    {
        return $this->document_no;
    }

    public function drillLabel(): string
    {
        return ($this->fromWarehouse?->name() ?? '').' → '.($this->toWarehouse?->name() ?? '');
    }

    public function drillRoute(): array
    {
        return ['inventory.transfer.show', ['transfer' => $this->id]];
    }
}

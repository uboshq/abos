<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\Drillable;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * একটা স্থায়ী সম্পদ — ভ্যান, ফ্রিজ, আসবাব।
 *
 * ── কেন দাম আর ক্ষয় আলাদা থাকে ──────────────────────────────────────
 * সম্পদের খাত ধরে রাখে কেনার দাম, আর সঞ্চিত অবচয়ের খাত ধরে রাখে কতটা
 * ক্ষয়ে গেছে। সরাসরি সম্পদের খাত থেকে কাটলে দুইটার একটাই থাকত, আর
 * "গাড়িটা কত দিয়ে কেনা হয়েছিল" প্রশ্নের উত্তর হারিয়ে যেত — অথচ বিমা,
 * বিক্রি ও কর, তিন জায়গাতেই ওই সংখ্যাটা লাগে।
 */
class FixedAsset extends Model implements Drillable
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    /** সমান কিস্তিতে ক্ষয় — প্রতি মাসে একই অঙ্ক। */
    public const STRAIGHT_LINE = 'straight';

    /** অবশিষ্ট দামের উপর হার — প্রথম বছরগুলোয় বেশি, পরে কম। */
    public const REDUCING = 'reducing';

    public const ACTIVE = 'active';

    public const DISPOSED = 'disposed';

    protected $table = 'acc_fixed_assets';

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'name', 'tag_no',
        'asset_account_id', 'accumulated_account_id', 'expense_account_id',
        'cost', 'salvage', 'acquired_on', 'method', 'life_months', 'rate',
        'status', 'disposed_on', 'disposal_amount', 'narration', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:4',
            'salvage' => 'decimal:4',
            'disposal_amount' => 'decimal:4',
            'rate' => 'decimal:4',
            'acquired_on' => 'date',
            'disposed_on' => 'date',
            'life_months' => 'integer',
        ];
    }

    public function depreciation(): HasMany
    {
        return $this->hasMany(DepreciationEntry::class)->orderBy('period_end');
    }

    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    public function accumulatedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_account_id');
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function isDisposed(): bool
    {
        return $this->status === self::DISPOSED;
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    /** এ পর্যন্ত মোট কতটা ক্ষয় ধরা হয়েছে। */
    public function accumulated(?Carbon $upTo = null): string
    {
        $query = $this->depreciation();

        if ($upTo !== null) {
            $query->where('period_end', '<=', $upTo->toDateString());
        }

        return (string) ($query->sum('amount') ?: '0');
    }

    /**
     * খাতায় এখন জিনিসটার দাম।
     *
     * কেনার দাম বিয়োগ এ পর্যন্তকার ক্ষয়। এটাই ব্যালেন্স শিটে বসে, আর
     * বিক্রির দিন লাভ-লোকসান এই সংখ্যাটার সাথে তুলনা করেই বেরোয়।
     */
    public function bookValue(?Carbon $upTo = null): string
    {
        return bcsub((string) $this->cost, $this->accumulated($upTo), 4);
    }

    /**
     * আর কতটা ক্ষয় ধরা বাকি।
     *
     * বাতিল মূল্যের নিচে নামা যায় না — ওটুকু দামে জিনিসটা আয়ু শেষেও
     * বিক্রি হবে বলে ধরা হয়েছে। না আটকালে খাতায় দাম শূন্য হয়ে যেত,
     * অথচ ভাঙারির দোকানে ওটার এখনো দাম আছে।
     */
    public function depreciableLeft(?Carbon $upTo = null): string
    {
        $floor = bcsub((string) $this->cost, (string) $this->salvage, 4);
        $done = $this->accumulated($upTo);
        $left = bcsub($floor, $done, 4);

        return bccomp($left, '0', 4) > 0 ? $left : '0.0000';
    }

    public function isFullyDepreciated(): bool
    {
        return bccomp($this->depreciableLeft(), '0', 4) <= 0;
    }

    public static function drillSourceType(): string
    {
        return 'fixed_asset';
    }

    public function drillDocumentNo(): string
    {
        return $this->document_no;
    }

    public function drillLabel(): string
    {
        return $this->name.' — '.$this->document_no;
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    public function drillRoute(): array
    {
        return ['accounts.asset.show', ['asset' => $this->id]];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserBranch;
use App\Core\Contracts\Drillable;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ⭐ মাসের অবচয়ের দৌড় — এক শাখার এক মাসের একটা কাগজ (DEP-…), সারি খাতের জোড়া ধরে (স্থায়ী সম্পদ ধাপ ২)।
 *
 * ⓘ খাতায় একটা দাখিলা, ভেতরে প্রতিটা সম্পদের নিজের অবচয়ের সারি ([[DepreciationEntry]]) — খাতা পড়েন হিসাবরক্ষক,
 * সম্পদের পাতা পড়েন মালিক; দুইজনেই একই অঙ্ক দেখেন।
 */
class DepreciationRun extends Model implements Drillable
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use ScopedToUserBranch;

    public const SERIES = 'DEP';

    protected $table = 'acc_depreciation_runs';

    protected $fillable = [
        'company_id', 'branch_id', 'branch_key', 'period_end', 'document_no', 'total', 'assets_count', 'created_by',
    ];

    protected function casts(): array
    {
        return ['period_end' => 'date', 'total' => 'decimal:4', 'assets_count' => 'integer'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(DepreciationEntry::class, 'run_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public static function drillSourceType(): string
    {
        return 'depreciation_run';
    }

    public function drillDocumentNo(): string
    {
        return (string) $this->document_no;
    }

    public function drillLabel(): string
    {
        return __('accounts::asset.run_label', ['month' => $this->period_end?->format('M Y')]);
    }

    public function drillRoute(): array
    {
        return ['accounts.asset.run.show', ['run' => $this->id]];
    }
}

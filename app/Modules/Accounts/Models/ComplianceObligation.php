<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ListedInViewedBranch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ করণীয় — আইনের একটা দায়, শেষ তারিখসহ (টাকা দেওয়ার মিল-তালিকা #৩; fe, ২২ অক্টোবর ২০২৬)।
 *
 * ⓘ বসায় [[ComplianceObligations]]; কোন কাগজ থেকে এল তা `source_type` + `source_id` (+ পড়ার জন্য `source_no`)। খোলা = করা হয়নি আর
 * বাতিলও নয়; সময় পেরোনো = খোলা আর শেষ তারিখ আজকের আগে।
 */
class ComplianceObligation extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use ListedInViewedBranch;

    /** সরকারি কোষাগারে জমা — উৎসে কাটা কর বা ভ্যাট */
    public const KOSAGAR_DEPOSIT = 'kosagar_deposit';

    /** সরবরাহকারীকে মূসক-৬.৬ (উৎসে ভ্যাট কর্তনের সনদ) দেওয়া */
    public const MUSHAK_66_ISSUE = 'mushak_6_6_issue';

    public const KINDS = [self::KOSAGAR_DEPOSIT, self::MUSHAK_66_ISSUE];

    public const AIT = 'ait';

    public const VDS = 'vds';

    protected $table = 'compliance_obligations';

    protected $fillable = [
        'company_id', 'branch_id', 'source_type', 'source_id', 'source_no', 'kind', 'subject', 'amount', 'due_on',
        'done_at', 'done_by', 'ref_no', 'note', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_on' => 'date',
            'done_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('done_at'))->whereNull($this->qualifyColumn('cancelled_at'));
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereDate($this->qualifyColumn('due_on'), '<', now()->toDateString());
    }

    public function isOpen(): bool
    {
        return $this->done_at === null && $this->cancelled_at === null;
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_on !== null && $this->due_on->lt(now()->startOfDay());
    }
}

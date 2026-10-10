<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserBranch;
use App\Core\Contracts\Drillable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ সম্পদের জীবনের একটা ঘটনা — নিজের কাগজ (FAE-…), নিজের সই (স্থায়ী সম্পদ ধাপ ৩; IAS 16, IAS 36)।
 *
 * ⓘ চার রকম:
 *   · সংযোজন — দাম বাড়ে, চাইলে আয়ুও (IAS 16.13);
 *   · মেরামত — খরচে যায়, দামে নয় (IAS 16.12);
 *   · পুনর্মূল্যায়ন — মালিকের সুইচ চালু থাকলেই (IAS 16.31);
 *   · দাম পড়ে যাওয়া — মালিকের দেওয়া ফেরতযোগ্য দামে (IAS 36.59)।
 *
 * ⚠️ পাকা ঘটনার `cost_change` আর `accumulated_change` সম্পদের দাম আর সঞ্চিত ক্ষয়ে ঢোকে ([[FixedAsset::accumulated()]])।
 * সইয়ের অপেক্ষায় থাকা ঘটনা কিছুই ছোঁয় না।
 */
class AssetEvent extends Model implements Drillable
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use ScopedToUserBranch;

    public const SERIES = 'FAE';

    public const ADDITION = 'addition';

    public const REPAIR = 'repair';

    public const REVALUATION = 'revaluation';

    public const IMPAIRMENT = 'impairment';

    /** @var list<string> */
    public const KINDS = [self::ADDITION, self::REPAIR, self::REVALUATION, self::IMPAIRMENT];

    public const AWAITING = 'awaiting';

    public const POSTED = 'posted';

    protected $table = 'acc_asset_events';

    protected $fillable = [
        'company_id', 'branch_id', 'fixed_asset_id', 'kind', 'document_no', 'happened_on', 'amount',
        'cost_change', 'accumulated_change', 'effect', 'surplus_change', 'account_id', 'charge_account_id',
        'supplier_id', 'extend_months', 'reason', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'happened_on' => 'date',
            'amount' => 'decimal:4',
            'cost_change' => 'decimal:4',
            'accumulated_change' => 'decimal:4',
            'effect' => 'decimal:4',
            'surplus_change' => 'decimal:4',
            'extend_months' => 'integer',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function chargeAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'charge_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param  Builder<self>  $query */
    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::POSTED);
    }

    public function isAwaiting(): bool
    {
        return $this->status === self::AWAITING;
    }

    public function kindLabel(): string
    {
        return __('accounts::asset.event_'.$this->kind);
    }

    public static function drillSourceType(): string
    {
        return 'asset_event';
    }

    public function drillDocumentNo(): string
    {
        return (string) $this->document_no;
    }

    public function drillLabel(): string
    {
        return $this->kindLabel().' · '.($this->asset?->name ?? '');
    }

    public function drillRoute(): array
    {
        return ['accounts.asset.show', ['asset' => $this->fixed_asset_id]];
    }
}

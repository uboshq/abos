<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Contracts\Drillable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * এক মাসের ক্ষয়, এক সম্পদের।
 *
 * SoftDeletes নেই ইচ্ছাকৃতভাবে: একটা বসানো অবচয় মুছে ফেলা যায় না,
 * কারণ ওটা খতিয়ানে বসে গেছে। ভুল হলে সংশোধনী ভাউচার লাগে — মুছে
 * দিলে খাতা আর সারিগুলো আলাদা কথা বলত।
 */
class DepreciationEntry extends Model implements Drillable
{
    use BelongsToCompany;

    protected $table = 'acc_depreciation_entries';

    protected $fillable = [
        'company_id', 'fixed_asset_id', 'period_end', 'amount',
        'document_no', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'period_end' => 'date',
            'amount' => 'decimal:4',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function drillSourceType(): string
    {
        return 'depreciation';
    }

    public function drillDocumentNo(): string
    {
        return (string) ($this->document_no ?? $this->id);
    }

    public function drillLabel(): string
    {
        return trim(($this->asset?->name ?? '').' — '.$this->period_end?->format('M Y'), ' —');
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    public function drillRoute(): array
    {
        return ['accounts.asset.show', ['asset' => $this->fixed_asset_id]];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\Drillable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ গোনার অভিযানে একটা সম্পদ — পাওয়া গেল, গেল না, ভাঙা, না ভুল জায়গায় (স্থায়ী সম্পদ ধাপ ৪)।
 */
class AssetVerificationLine extends Model implements Drillable
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    public const FOUND = 'found';

    public const NOT_FOUND = 'not_found';

    public const DAMAGED = 'damaged';

    public const WRONG_LOCATION = 'wrong_location';

    /** @var list<string> */
    public const RESULTS = [self::FOUND, self::NOT_FOUND, self::DAMAGED, self::WRONG_LOCATION];

    /** ⓘ ছবিটা সংযুক্তির ইঞ্জিনে এই নামে ([[AttachmentEngine]]) */
    public const ATTACHMENT_ENTITY = 'asset_verification_line';

    protected $table = 'acc_asset_verification_lines';

    protected $fillable = [
        'company_id', 'verification_id', 'fixed_asset_id', 'expected_location', 'expected_custodian_id',
        'result', 'found_location', 'note', 'checked_by', 'checked_at',
    ];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime'];
    }

    public function verification(): BelongsTo
    {
        return $this->belongsTo(AssetVerification::class, 'verification_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function resultLabel(): string
    {
        return $this->result === null ? __('accounts::asset.verify_unchecked') : __('accounts::asset.verify_'.$this->result);
    }

    /** ⓘ ছবির দরজা এখান দিয়ে — সংযুক্তি নিজের কাগজ এই নামে খোঁজে ([[AttachmentController]]) */
    public static function drillSourceType(): string
    {
        return self::ATTACHMENT_ENTITY;
    }

    public function drillDocumentNo(): string
    {
        return (string) $this->loadMissing('verification')->verification?->document_no;
    }

    public function drillLabel(): string
    {
        return (string) $this->loadMissing('asset')->asset?->name;
    }

    public function drillRoute(): array
    {
        return ['accounts.asset.verify.show', ['verification' => $this->verification_id]];
    }
}

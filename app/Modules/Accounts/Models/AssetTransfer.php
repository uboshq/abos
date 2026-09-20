<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\Drillable;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একটা সম্পদ এক শাখা থেকে আরেক শাখায় গেল — মানচিত্র §১৫।
 *
 * ── ⚠️ কেন এটার নিজের সারি ───────────────────────────────────────────
 * এক সম্পদ বহুবার সরতে পারে। ⓘ কেবল সম্পদের `branch_id` বদলালে ইতিহাস
 * হারাত, আর **পোস্টিং ইঞ্জিন দ্বিতীয় স্থানান্তরটা আটকে দিত**: এক
 * `(উৎস, আইডি)` জোড়ায় একবারই পোস্ট হয়।
 *
 * ⛔ ঠিক ঐ ফাঁদে সম্পদের **বিদায়** পড়েছিল (২০ সেপ্টেম্বর ২০২৬) — আর
 * ফলটা ছিল নীরব: বিক্রির টাকা খাতায় উঠত না। ⭐ নিজের সারি মানে নিজের
 * আইডি, তাই প্রতিটা স্থানান্তরের নিজের চাবি।
 */
class AssetTransfer extends Model implements Drillable
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'acc_asset_transfers';

    protected $fillable = [
        'company_id', 'asset_id', 'from_branch_id', 'to_branch_id',
        'moved_on', 'note', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['moved_on' => 'date'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function drillSourceType(): string
    {
        return 'asset_transfer';
    }

    public function drillDocumentNo(): string
    {
        return $this->asset?->document_no ?? ('#'.$this->id);
    }

    public function drillLabel(): string
    {
        return $this->asset?->name ?? $this->drillDocumentNo();
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    public function drillRoute(): array
    {
        return ['accounts.asset.show', ['asset' => $this->asset_id]];
    }
}

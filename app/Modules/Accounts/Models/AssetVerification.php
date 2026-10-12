<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserBranch;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ⭐ শাখার সম্পদ সরেজমিন গোনার একটা অভিযান — FAV-… (স্থায়ী সম্পদ ধাপ ৪)।
 *
 * ⓘ খোলার মুহূর্তে শাখার খাতায় থাকা প্রতিটা সম্পদের একটা সারি বসে ([[AssetVerificationLine]]); গোনা শেষে বন্ধ। বন্ধ
 * অভিযানের সারি আর বদলায় না — নইলে পার্থক্যের প্রতিবেদন পেছন থেকে বদলাত।
 */
class AssetVerification extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use ScopedToUserBranch;

    public const SERIES = 'FAV';

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $table = 'acc_asset_verifications';

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'title', 'started_on', 'closed_on', 'status', 'created_by', 'closed_by',
    ];

    protected function casts(): array
    {
        return ['started_on' => 'date', 'closed_on' => 'date'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AssetVerificationLine::class, 'verification_id')->orderBy('id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }
}

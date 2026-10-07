<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * উল্টো কাগজ — পাকা ভাউচার বা নোটের, নিজের নম্বরে (REV-xxxx), মূলের সূত্রসহ (মালিকের সংস্করণ ২, ৪ অক্টোবর ২০২৬)।
 *
 * ⓘ মূল কাগজ থাকে, "বাতিল" হয়ে; এর কাজ খাতার উল্টো সারিগুলো নিজের নম্বরে বসানো আর "কে, কখন, কেন" রাখা
 * ([[AccountsReversalService]])।
 */
class Reversal extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    public const VOUCHER = 'voucher';

    public const NOTE = 'note';

    protected $table = 'acc_reversals';

    protected $fillable = [
        'company_id', 'branch_id', 'reversible_type', 'reversible_id', 'reversed_no',
        'document_no', 'trx_date', 'reason', 'amount', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'amount' => 'decimal:4',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** একটা কাগজের উল্টো কাগজ — না থাকলে null */
    public static function of(string $type, int $id): ?self
    {
        return self::query()->where('reversible_type', $type)->where('reversible_id', $id)->first();
    }
}

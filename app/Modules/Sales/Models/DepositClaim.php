<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ডিলারের জমার দাবি — "এই তারিখে এত টাকা দিয়েছি"।
 *
 * ── এটা আদায় নয়, দাবি ───────────────────────────────────────────────
 * ডিলারের কথায় খাতায় টাকা বসানো যায় না। ব্যাংকে টাকাটা সত্যিই এসেছে
 * কি না সেটা ডিপো দেখে, আর ওই যাচাইটাই আদায় ব্যবস্থার ভিত্তি। দাবি
 * সরাসরি বসলে যে কেউ বসে বসে নিজের বকেয়া শূন্য করে ফেলতে পারতেন।
 *
 * কিন্তু দাবিটা **লেখা থাকে**, তারিখসহ — আর সেটাই পুরো পার্থক্য।
 * হোয়াটসঅ্যাপের ছবি হারিয়ে যায়, সারি হারায় না।
 */
class DepositClaim extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    /** ডিলার তুলেছেন, ডিপো এখনো দেখেনি। */
    public const PENDING = 'pending';

    /** যাচাই হয়েছে, আদায় বসে গেছে। */
    public const ACCEPTED = 'accepted';

    /** ব্যাংকে পাওয়া যায়নি, বা ভুল দাবি। */
    public const REJECTED = 'rejected';

    public const BANK = 'bank';

    public const MFS = 'mfs';

    public const CASH = 'cash';

    protected $table = 'sal_deposit_claims';

    protected $fillable = [
        'company_id', 'branch_id', 'customer_id',
        'claimed_on', 'amount', 'method', 'reference', 'bank_account_id',
        'status', 'note', 'collection_id',
        'decided_by', 'decided_at', 'decision_reason',
    ];

    protected function casts(): array
    {
        return [
            'claimed_on' => 'date',
            'amount' => 'decimal:4',
            'decided_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class, 'collection_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::ACCEPTED;
    }

    /** @param  Builder<self>  $query */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }
}

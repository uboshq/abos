<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Contracts\Drillable;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * এক হাত থেকে আরেক হাতে টাকা।
 *
 * দুই ধাপ, ইচ্ছাকৃতভাবে: দেওয়া আর নেওয়া আলাদা ঘটনা। গ্রহণ নিশ্চিত না
 * হওয়া পর্যন্ত টাকাটা দাতার হিসাবেই থাকে, তাই পথে কিছু হলে সেটা কার
 * দায়িত্বে তা নিয়ে তর্ক থাকে না।
 */
class MoneyTransfer extends Model implements Drillable
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'branch_id', 'financial_year_id', 'document_no', 'trx_date',
        'from_till_id', 'to_till_id', 'to_account_id',
        'given_by', 'received_by', 'amount', 'narration',
        'status', 'confirmed_at', 'confirmed_by',
        'cancelled_by', 'cancelled_at', 'cancel_reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'amount' => 'decimal:4',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function fromTill(): BelongsTo
    {
        return $this->belongsTo(CashTill::class, 'from_till_id');
    }

    public function toTill(): BelongsTo
    {
        return $this->belongsTo(CashTill::class, 'to_till_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function giver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'given_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** যে খাতে টাকাটা যাবে — কাউন্টার হোক বা ব্যাংক। */
    public function destinationAccountId(): ?int
    {
        return $this->to_account_id ?? $this->toTill?->account_id;
    }

    public function destinationName(): string
    {
        return $this->toTill?->name() ?? $this->toAccount?->name() ?? '—';
    }

    public function isPending(): bool
    {
        return $this->status === DocumentStatus::DRAFT;
    }

    public function isConfirmed(): bool
    {
        return $this->status === DocumentStatus::CONFIRMED;
    }

    public function isCancelled(): bool
    {
        return $this->status === DocumentStatus::CANCELLED;
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::DRAFT);
    }

    /** যেগুলো এই ব্যবহারকারীর গ্রহণের অপেক্ষায়। */
    public function scopeAwaiting(Builder $query, int $userId): Builder
    {
        return $query->pending()->where('received_by', $userId);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($term)).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('document_no', 'like', $like)
            ->orWhere('narration', 'like', $like));
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'money_transfer';
    }

    public function drillDocumentNo(): string
    {
        return $this->document_no;
    }

    public function drillLabel(): string
    {
        return __('accounts::menu.money_transfer').' — '.$this->document_no;
    }

    public function drillRoute(): array
    {
        return ['accounts.transfer.show', ['transfer' => $this->id]];
    }
}

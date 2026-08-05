<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\Drillable;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * আদায় — টাকা এসেছে।
 *
 * টাকাটা কোথায় ঢুকল তা বলতেই হবে (account_id): কারও টিলে, নাকি ব্যাংকে।
 * না বললে "আজ কার হাতে কত আছে" প্রশ্নের উত্তর থাকত না, অথচ দিনশেষে ওই
 * প্রশ্নটাই সবচেয়ে বেশি করা হয়।
 */
class Collection extends Model implements Drillable
{
    use BelongsToCompany;
    use HasDocumentStatus;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    protected $table = 'sal_collections';

    protected $fillable = [
        'company_id', 'branch_id', 'financial_year_id', 'document_no',
        'customer_id', 'account_id', 'trx_date', 'amount',
        'instrument', 'instrument_no', 'instrument_date',
        'status', 'narration', 'created_by',
        'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'trx_date' => 'date',
            'instrument_date' => 'date',
            'cancelled_at' => 'datetime',
            'amount' => 'decimal:4',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CollectionLine::class)->orderBy('line_no');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('document_no', 'like', "%{$term}%")
                ->orWhere('instrument_no', 'like', "%{$term}%")
                ->orWhereHas('customer', fn (Builder $c) => $c->search($term));
        });
    }

    public static function drillSourceType(): string
    {
        return 'collection';
    }

    public function drillDocumentNo(): string
    {
        return $this->document_no;
    }

    public function drillLabel(): string
    {
        return $this->customer?->name() ?? $this->document_no;
    }

    public function drillRoute(): array
    {
        return ['sales.collection.show', ['collection' => $this->id]];
    }
}

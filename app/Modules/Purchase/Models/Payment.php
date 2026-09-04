<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserBranch;
use App\Core\Contracts\Drillable;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * সরবরাহকারীকে পরিশোধ — টাকা গেছে।
 *
 * টাকাটা কোথা থেকে গেল তা বলতেই হবে (account_id): কোন টিল থেকে, নাকি
 * কোন ব্যাংক থেকে। না বললে দিনশেষে "কার হাতে কত রইল" মিলত না।
 */
class Payment extends Model implements Drillable
{
    use BelongsToCompany;
    use HasDocumentStatus;
    use HasPublicId;
    use IsAudited;
    use ScopedToUserBranch;
    use SoftDeletes;

    protected $table = 'pur_payments';

    protected $fillable = [
        'company_id', 'branch_id', 'financial_year_id', 'document_no',
        'supplier_id', 'account_id', 'trx_date', 'amount',
        'instrument', 'instrument_no', 'instrument_date',

        /*
         * ⓘ চেকে দেওয়া হলে কোন চেকটা — বাকি সব পরিশোধে `null`।
         *
         * ⚠️ `$fillable`-এ না থাকলে `create()` চাবিটা ফেলে দিত, আর
         * পরিশোধ থেকে চেকে যাওয়ার পথটা চুপচাপ হারাত। ⓘ `local` ও
         * `testing`-এ ওটা ব্যতিক্রম হয়ে ধরা পড়ে
         * ([[AppServiceProvider]]), কিন্তু লাইভে নীরব — তাই ভরসা
         * পরিবেশের উপর নয়, তালিকাটার উপর।
         */
        'cheque_id',

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
        return $this->hasMany(PaymentLine::class)->orderBy('line_no');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * চেকে দেওয়া হলে কাগজটা — নাহলে `null`।
     *
     * ⓘ জোড়াটা এই দিকে বসে, চেকের টেবিলে নয়: **Accounts কারও উপর
     * দাঁড়ায় না**, সবাই তার উপর দাঁড়ায়। `acc_cheques`-এ `payment_id`
     * বসালে Accounts-কে Purchase-এর নাম জানতে হত।
     */
    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class);
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
                ->orWhereHas('supplier', fn (Builder $s) => $s->search($term));
        });
    }

    // ── Drillable ───────────────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'purchase_payment';
    }

    public function drillDocumentNo(): string
    {
        return $this->document_no;
    }

    public function drillLabel(): string
    {
        return $this->supplier?->name() ?? $this->document_no;
    }

    public function drillRoute(): array
    {
        return ['purchase.payment.show', ['payment' => $this->id]];
    }
}

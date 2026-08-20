<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * একটা ব্যাংক হিসাবের একটা তারিখের মিলকরণ।
 *
 * ── এটা কী করে, আর কী করে না ────────────────────────────────────────
 * করে: ব্যাংকের কাগজে যে লাইনগুলো আছে সেগুলোতে টিক দেয়, আর বলে বাকি
 * তফাতটা কোন কোন টুকরো দিয়ে ব্যাখ্যা হয়।
 *
 * করে না: খাতা বদলায় না। জের ঠিক করে না, সমন্বয় এন্ট্রি বসায় না।
 *
 * ── কেন ওই দ্বিতীয় অংশটা এত জরুরি ───────────────────────────────────
 * মিলকরণকে খাতা বদলাতে দিলে তফাতটাই হারিয়ে যায়, অথচ তফাতই একমাত্র
 * জিনিস যেটা ভুল বা চুরির দিকে আঙুল তোলে। যে যন্ত্র অমিলটাকে গিলে ফেলে,
 * সে অমিল ধরার যন্ত্র নয় — সে অমিল লুকানোর যন্ত্র।
 *
 * ব্যাংক চার্জ খাতায় না থাকলে সেটা একটা ভাউচার হয়ে বসবে, স্বাভাবিক
 * দরজা দিয়ে, নিজের তারিখে, নিজের অনুমোদন নিয়ে।
 */
class BankReconciliation extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    /** কাজ চলছে — টিক বসানো ও তোলা দুইটাই চলে। */
    public const DRAFT = 'draft';

    /** মিলে গেছে ও বন্ধ — টিকগুলো এখন তালাবদ্ধ। */
    public const CONFIRMED = 'confirmed';

    protected $table = 'acc_bank_reconciliations';

    protected $fillable = [
        'company_id', 'branch_id', 'bank_account_id',
        'statement_date', 'statement_balance',
        'status', 'confirmed_by', 'confirmed_at',
        'narration', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'statement_balance' => 'decimal:4',
            'confirmed_at' => 'datetime',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** যে লাইনগুলোতে এই মিলকরণ টিক দিয়েছে। */
    public function lines(): HasMany
    {
        return $this->hasMany(VoucherLine::class, 'reconciliation_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::CONFIRMED;
    }

    /** @param  Builder<self>  $query */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', self::CONFIRMED);
    }
}

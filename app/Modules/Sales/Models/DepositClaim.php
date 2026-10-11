<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ListedInViewedBranch;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * গ্রাহকের জমার দাবি — "এই তারিখে এত টাকা দিয়েছি"।
 *
 * ── এটা আদায় নয়, দাবি ───────────────────────────────────────────────
 * গ্রাহকের কথায় খাতায় টাকা বসানো যায় না। ব্যাংকে টাকাটা সত্যিই এসেছে
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
    // ⭐ বিক্রয়কর্মী কেবল নিজের বাঁধা ডিলারের কাগজ দেখেন — ⛔১৬, ২ অক্টোবর ২০২৬ ([[DealerScope]])
    use \App\Core\Concerns\ScopedToUserDealers;
    use ListedInViewedBranch;
    use SoftDeletes;

    /** পাঠানো (Submitted) — গ্রাহক বা কর্মী পাঠিয়েছেন, ডিপো এখনো দেখেনি। */
    public const PENDING = 'pending';

    /**
     * ⭐ যাচাই চলছে (Under Verification) — হিসাবরক্ষক ব্যাংকের কাগজে মেলাতে শুরু করেছেন (টাকার পরিকল্পনা ১, ৭ অক্টোবর ২০২৬)।
     * ⓘ চাবিটা ছোট, কারণ `status` ঘর ১৬ অক্ষরের; ফোন একই চাবি পড়ে (a4, 8e39aaa5)। গ্রহণ আর প্রত্যাখ্যান এখান থেকেও চলে।
     */
    public const VERIFYING = 'verifying';

    /** ⓘ এখনো সিদ্ধান্ত হয়নি — পাঠানো বা যাচাই চলছে */
    public const OPEN = [self::PENDING, self::VERIFYING];

    /** যাচাই হয়েছে, আদায় বসে গেছে। */
    public const ACCEPTED = 'accepted';

    /** ব্যাংকে পাওয়া যায়নি, বা ভুল দাবি। */
    public const REJECTED = 'rejected';

    public const BANK = 'bank';

    public const MFS = 'mfs';

    public const CASH = 'cash';

    protected $table = 'sal_deposit_claims';

    /** ⭐ পাঠানেওয়ালা নিজে গ্রহণ করেন না — কোম্পানির সুইচ, ডিফল্ট বন্ধ (টাকার পরিকল্পনা ৩; সমন্বয়ক, ১০ অক্টোবর ২০২৬) */
    public const FOUR_EYES = 'sales.advice_four_eyes';

    protected $fillable = [
        'company_id', 'branch_id', 'customer_id',
        // ⭐ কে পাঠালেন — কর্মী হলে তাঁর id, পোর্টালের দোকানি হলে খালি (টাকার পরিকল্পনা ৩, ৭ অক্টোবর ২০২৬)
        'submitted_by',
        'claimed_on', 'amount', 'method', 'reference', 'bank_account_id',
        'status', 'note', 'bills', 'collection_id',
        'decided_by', 'decided_at', 'decision_reason',
    ];

    /**
     * ⛔ যিনি পাঠালেন তিনি নিজে গ্রহণ করেন না — টাকার পরিকল্পনা ৩ (সমন্বয়ক, ৭ অক্টোবর ২০২৬)। সুইচ [[FOUR_EYES]], ডিফল্ট বন্ধ।
     *
     * ⓘ পাহারাটা সারির উপর — যে মুহূর্তে বিজ্ঞপ্তি "গৃহীত" হয় আর সিদ্ধান্তদাতা বসে — কোনো একটা দরজায় নয়: গ্রহণের সেবা
     * আদায় বানিয়ে নিশ্চিত করে তারপর সারিটা লেখে, সব এক লেনদেনে, তাই এখানে থামলে আদায়টাও ফিরে যায়। নতুন কোনো দরজাও এড়াতে পারে না।
     * ⓘ মালিক (সুপার অ্যাডমিন) একা করলে আটকায় না — নিরীক্ষায় "নিজের পাঠানো নিজে গ্রহণ" দাগ পড়ে ([[VoucherService::writerMayNotPost()]]-এর
     * একই নিয়ম)। সুইচ বন্ধে আজকের আচরণ অবিকল; পাঠানেওয়ালা অজানা (পোর্টাল, পুরনো সারি) হলে কিছু থামে না।
     */
    protected static function booted(): void
    {
        static::updating(function (self $claim): void {
            if (! $claim->isDirty('status') || $claim->status !== self::ACCEPTED || $claim->submitted_by === null) {
                return;
            }

            if ((int) $claim->decided_by !== (int) $claim->submitted_by
                || ! (bool) app(\App\Core\Services\SettingsService::class)->get(self::FOUR_EYES, false)) {
                return;
            }

            $user = auth()->user();
            $owner = $user instanceof User && $user->roles->contains('name', \App\Core\Services\PermissionSyncer::SUPER_ADMIN_ROLE);

            if (! $owner) {
                throw \Illuminate\Validation\ValidationException::withMessages(['status' => __('sales::portal.own_advice')]);
            }

            \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Core\Engines\Audit\AuditEngine::class)
                ->recordAction($claim, 'own_advice_accepted', __('sales::portal.own_advice')));
        });
    }

    protected function casts(): array
    {
        return [
            'claimed_on' => 'date',
            'amount' => 'decimal:4',
            'decided_at' => 'datetime',
            // ⭐ কোন বিলের বিপরীতে — `[{sales_invoice_id, amount}]`, ঐচ্ছিক ([[DepositClaimService::raise()]])
            'bills' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** ⭐ কে পাঠালেন — টাকার পরিকল্পনা ৩ */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
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

    /** ⓘ সিদ্ধান্ত বাকি — পাঠানো বা যাচাই চলছে ([[OPEN]]) */
    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
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

    /**
     * ⓘ সিদ্ধান্ত বাকি সব — ডেস্কের "অপেক্ষমাণ" ট্যাব আর গোনা এটাই নেয়, যাতে যাচাই শুরু হওয়া বিজ্ঞপ্তি তালিকা থেকে হারায় না।
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN);
    }

    /** ⓘ অবস্থার নাম, সার্ভারের ভাষায় — ওয়েব, পোর্টাল আর ফোন একই নাম দেখায় */
    public function statusLabel(): string
    {
        return (string) __('sales::portal.'.$this->status);
    }
}

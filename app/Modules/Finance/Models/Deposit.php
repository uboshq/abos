<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Accounts\Models\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * একটা জমা — ব্যাংক আমানত, সঞ্চয়পত্র বা বন্ড।
 *
 * ── কেন এক টেবিল, তিন মেনু ───────────────────────────────────────────
 * তিনটার ঘর একই: কোথায় রাখা, কত, কত হারে, কবে খোলা, কবে মেয়াদ শেষ।
 * তিনটা টেবিল করলে একই নয়টা কলাম তিনবার লিখতে হত, আর "মোট কত টাকা
 * সরিয়ে রাখা আছে" প্রশ্নের উত্তর দিতে তিনটা জোড়া লাগত।
 *
 * মেনুতে তিনটা সারি, কারণ মানুষ ওভাবেই ভাবেন — কেউ "জমা" খোঁজেন না,
 * খোঁজেন "সঞ্চয়পত্র"। ছাঁকনিটা `issuer` কলামে।
 *
 * ── সঞ্চয়পত্র কোম্পানির সম্পদ, নাকি মালিকের উত্তোলন? ─────────────────
 * **দুইটাই — আর সারিটাই বলে দেয় কোনটা।**
 *
 * সঞ্চয়পত্র আইনত ব্যক্তির জিনিস; ফার্ম বা কোম্পানি কিনতে পারে না।
 * তাই `held_by` ঘরটা অপরিহার্য, আর দাখিলা ওটাই ঠিক করে:
 *
 *   `business` — ব্যবসার নামে (ব্যাংক আমানত, প্রাইজ বন্ড)।
 *                Dr জমা (সম্পদ) · Cr নগদ/ব্যাংক।
 *                স্থিতিপত্রে সম্পদ হিসেবে বসে।
 *
 *   `owner`    — মালিকের নামে (সঞ্চয়পত্র, ডলার বন্ড)।
 *                Dr উত্তোলন (৩২০০) · Cr নগদ/ব্যাংক।
 *                **ব্যবসার সম্পদ নয়** — কেবল জানার জন্য রাখা।
 *
 * ── কেন দুইটাই দরকার, একটা বেছে নেওয়া নয় ───────────────────────────
 * শুধু সম্পদ ধরলে স্থিতিপত্রে এমন কিছু বসত যা ব্যবসার নয়, আর অডিটে
 * ধরা পড়ত। শুধু উত্তোলন ধরলে মালিক নিজের সঞ্চয়পত্রগুলো আর কোথাও
 * দেখতে পেতেন না — অথচ ওটাই তাঁর সবচেয়ে বড় সঞ্চয়, আর ওটা দেখার
 * জন্যই তিনি এটা চেয়েছেন।
 *
 * সারিটা থাকে দুই ক্ষেত্রেই; কেবল টাকার দাখিলাটা আলাদা।
 */
class Deposit extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;

    /** ব্যবসার নামে — স্থিতিপত্রে সম্পদ */
    public const BUSINESS = 'business';

    /** মালিকের নামে — ব্যবসার টাকা গেলে সেটা উত্তোলন */
    public const OWNER = 'owner';

    public const ACTIVE = 'active';

    /** মেয়াদ শেষ বা ভাঙা হয়েছে — একটা ব্যবসায়িক ঘটনা */
    public const CLOSED = 'closed';

    /**
     * ভুল করে বসানো হয়েছিল — উল্টো দাখিলা, সারিটা থেকে যায়।
     *
     * ── কেন এটা `CLOSED` থেকে আলাদা ─────────────────────────────────
     * ভাঙা মানে ব্যাংক টাকা ফেরত দিয়েছে; বাতিল মানে জমাটা কোনোদিন
     * ছিলই না। এক অবস্থায় মেলালে "কত টাকা ফেরত এসেছে" রিপোর্টে এমন
     * টাকা যোগ হত যা কেউ কোনোদিন পায়নি।
     */
    public const CANCELLED = 'cancelled';

    protected $table = 'fin_deposits';

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'kind_id', 'institution',
        'branch_name', 'reference_no', 'held_by', 'holder_name', 'principal',
        'profit_rate', 'return_word', 'opened_on', 'matures_on',
        'instalment_amount', 'instalment_day', 'payout_account_id', 'account_id',
        'funded_from_account_id', 'pledged_to_loan_id', 'status', 'closed_on',
        'note', 'cancel_reason', 'cancelled_at', 'cancelled_by', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'principal' => 'decimal:4',
            'profit_rate' => 'decimal:4',
            'instalment_amount' => 'decimal:4',
            'opened_on' => 'date',
            'matures_on' => 'date',
            'closed_on' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    public function kind(): BelongsTo
    {
        return $this->belongsTo(DepositKind::class, 'kind_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(DepositMovement::class);
    }

    public function payoutAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payout_account_id');
    }

    /** @param  Builder<Deposit>  $query */
    public function scopeIssuedBy(Builder $query, string $issuer): void
    {
        $query->whereHas('kind', fn (Builder $q) => $q->where('issuer', $issuer));
    }

    /** @param  Builder<Deposit>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', self::ACTIVE);
    }

    /**
     * বাতিল হওয়া জমা কোনো যোগফলে গোনা হয় না।
     *
     * সারিটা তালিকায় থাকে — অডিটে প্রশ্ন উঠলে উত্তরটা লাগে — কিন্তু
     * "কত টাকা সরিয়ে রাখা আছে" সংখ্যায় ওটা শূন্য, কারণ ওই টাকাটা
     * কোনোদিন কোথাও যায়নি।
     */
    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    /**
     * এটা কি ব্যবসার সম্পদ, নাকি মালিকের ব্যক্তিগত।
     *
     * পর্দায় দুইটার রং আলাদা, কারণ মোট যোগ করার সময় দুইটা একসাথে
     * করা যায় না — একটা স্থিতিপত্রে আছে, অন্যটা নেই।
     */
    public function isBusinessAsset(): bool
    {
        return $this->held_by === self::BUSINESS;
    }

    /**
     * মেয়াদ শেষ হতে আর কত দিন — শেষ হয়ে গেলে ঋণাত্মক।
     *
     * ── কেন এই সংখ্যাটা ─────────────────────────────────────────────
     * মেয়াদোত্তীর্ণ FD ব্যাংকে পড়ে থাকে, আর সাধারণ সঞ্চয়ী হারে সুদ
     * পায় — অর্থাৎ প্রতিদিন টাকা হারায়। কেউ তারিখ মনে রাখে না; পর্দা
     * রাখে।
     */
    public function daysToMaturity(): ?int
    {
        return $this->matures_on === null
            ? null
            : (int) now()->startOfDay()->diffInDays($this->matures_on, false);
    }
}

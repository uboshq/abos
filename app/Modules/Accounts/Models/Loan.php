<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\Drillable;
use App\Core\Support\DocumentStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * একটা ঋণ — টার্ম লোন বা CC।
 *
 * দুইটা এক টেবিলে, কারণ "কে দিল, কোন খাতে, কত সুদ, কী জামানত" —
 * এই সবটা এক। আলাদা হয় কেবল ফেরতের গড়ন, আর সেটা kind দেখে ঠিক হয়।
 */
class Loan extends Model implements Drillable
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    /** নির্দিষ্ট টাকা, নির্দিষ্ট মেয়াদ, মাসিক কিস্তি। */
    public const TERM = 'term';

    /** একটা সীমা, তার ভেতরে যত খুশি তোলা-জমা। */
    public const CC = 'cc';

    protected $table = 'acc_loans';

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'lender', 'account_no',
        'kind', 'interest_method', 'sanctioned', 'interest_rate',
        'tenure_months', 'start_date', 'first_instalment_on',
        'liability_account_id', 'interest_account_id',
        'security', 'narration', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'sanctioned' => 'decimal:4',
            'interest_rate' => 'decimal:4',
            'start_date' => 'date',
            'first_instalment_on' => 'date',
        ];
    }

    public function instalments(): HasMany
    {
        return $this->hasMany(LoanInstalment::class)->orderBy('no');
    }

    public function liabilityAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'liability_account_id');
    }

    public function interestAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'interest_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isTerm(): bool
    {
        return $this->kind === self::TERM;
    }

    public function isCc(): bool
    {
        return $this->kind === self::CC;
    }

    /**
     * এখন কত বাকি — খতিয়ান থেকে, আলাদা কোনো কলাম থেকে নয়।
     *
     * ── কেন জমা রাখা হয় না ─────────────────────────────────────────
     * বকেয়া একটা কলামে রাখলে সেটা আর খতিয়ানের সাথে বাঁধা থাকত না।
     * একটা পরিশোধ ভুল করে দুইবার বসলে, বা কেউ ভাউচার দিয়ে সরাসরি
     * ঋণের খাতে হাত দিলে, দুইটা সংখ্যা আলাদা হয়ে যেত — আর কোনটা
     * সত্যি তা বলার উপায় থাকত না।
     *
     * দায় ক্রেডিট প্রকৃতির, তাই ডেবিট − ক্রেডিট ঋণাত্মক আসে; বকেয়া
     * হিসেবে পড়তে চিহ্নটা উল্টে দেওয়া হয়।
     */
    public function outstanding(): string
    {
        $entries = LedgerEntry::query()
            ->where('account_id', $this->liability_account_id)
            ->where(function ($q) {
                $q->where('source_type', self::drillSourceType())
                    ->where('source_id', $this->id);
            })
            ->get();

        $balance = $entries->reduce(
            fn (string $sum, LedgerEntry $e) => bcadd($sum, bcsub((string) $e->debit, (string) $e->credit, 4), 4),
            '0',
        );

        return bcmul($balance, '-1', 4);
    }

    /** CC-তে সীমার আর কতটা খালি। */
    public function available(): string
    {
        return bcsub((string) $this->sanctioned, $this->outstanding(), 4);
    }

    public function isSettled(): bool
    {
        return bccomp($this->outstanding(), '0', 4) <= 0;
    }

    // ── Drillable — নিয়ম ১ ────────────────────────────────────────────

    public static function drillSourceType(): string
    {
        return 'loan';
    }

    public function drillDocumentNo(): string
    {
        return $this->document_no;
    }

    public function drillLabel(): string
    {
        return $this->lender.' — '.$this->document_no;
    }

    public function drillUrl(): string
    {
        return route('accounts.loan.show', $this->id);
    }

    public function isDraft(): bool
    {
        return $this->status === DocumentStatus::DRAFT;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\Drillable;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * একটা চেক — হাতে আসা, বা নিজের দেওয়া।
 *
 * ── কেন এটা নিজের কাগজ ──────────────────────────────────────────────
 * ভাউচারে চেকের নম্বর লেখার ঘর আগে থেকেই ছিল, কিন্তু ওটা কেবল একটা
 * লেখা — কোনো অবস্থা নেই, কোনো তারিখ নেই, ফেরত আসার কোনো পথ নেই।
 * চেকের একটা **জীবন** আছে: হাতে এল, জমা পড়ল, পাশ হলো — নয়তো ফেরত
 * এল। ওই জীবনটা একটা লেখার ঘরে ধরে না।
 */
class Cheque extends Model implements Drillable
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    /** ডিলার বা গ্রাহকের দেওয়া চেক। */
    public const RECEIVED = 'received';

    /** আমাদের নিজের দেওয়া চেক। */
    public const ISSUED = 'issued';

    /** হাতে আছে, এখনো ব্যাংকে যায়নি। */
    public const PENDING = 'pending';

    /** ব্যাংকে জমা পড়েছে, ফল জানা যায়নি। */
    public const DEPOSITED = 'deposited';

    /** পাশ হয়েছে — এখন এটা সত্যিকারের টাকা। */
    public const CLEARED = 'cleared';

    /** ফেরত এসেছে। */
    public const BOUNCED = 'bounced';

    /** বাতিল — ছেঁড়া হয়েছে বা বদলে দেওয়া হয়েছে। */
    public const CANCELLED = 'cancelled';

    public const STOCK_SOURCE = 'cheque';

    protected $table = 'acc_cheques';

    protected $fillable = [
        'company_id', 'branch_id', 'document_no', 'direction',
        'cheque_date', 'received_on', 'cheque_no', 'bank_name', 'amount',
        'party_type', 'party_id', 'bank_account_id',
        'status', 'deposited_on', 'cleared_on', 'bounce_reason',
        'narration', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'cheque_date' => 'date',
            'received_on' => 'date',
            'deposited_on' => 'date',
            'cleared_on' => 'date',
            'amount' => 'decimal:4',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** এখনো ফল জানা যায়নি এমন চেক — হাতে, বা ব্যাংকে। */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::PENDING, self::DEPOSITED], true);
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::PENDING, self::DEPOSITED]);
    }

    /**
     * আজ বা তার আগে ভাঙানোর তারিখ পেরিয়ে গেছে, অথচ এখনো ঝুলে আছে।
     *
     * ── কেন এটা আলাদা করে দরকার ─────────────────────────────────────
     * আগাম তারিখের চেক ফেলে রাখা স্বাভাবিক — তারিখ আসার আগে ওটা নিয়ে
     * কিছু করার নেই। কিন্তু তারিখ পেরিয়ে যাওয়ার পরেও ঝুলে থাকা মানে
     * হয় কেউ জমা দিতে ভুলে গেছে, নয় ব্যাংক থেকে খবর আসেনি — দুইটাই
     * টাকার ঝুঁকি, আর দুইটাই মানুষের চোখে পড়া দরকার।
     *
     * @param  Builder<self>  $query
     */
    public function scopeRipe(Builder $query, Carbon|string|null $asOf = null): Builder
    {
        $date = $asOf instanceof Carbon ? $asOf : Carbon::parse($asOf ?? now());

        return $query->open()->whereDate('cheque_date', '<=', $date->toDateString());
    }

    public static function drillSourceType(): string
    {
        return self::STOCK_SOURCE;
    }

    public function drillDocumentNo(): string
    {
        return (string) ($this->document_no ?? $this->cheque_no);
    }

    public function drillLabel(): string
    {
        return trim($this->cheque_no.' — '.($this->bank_name ?? ''), ' —');
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    public function drillRoute(): array
    {
        return ['accounts.cheque.index', []];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Customer\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Concerns\ScopedToUserDealers;
use App\Core\Support\Actor;
use App\Models\Approval;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ বাকির সীমা বা বাকির দিনের একটা বদল — কে, কখন, আগে, পরে, কেন, কোন সইয়ে (বাকি নিয়ন্ত্রণ ধাপ ১.৪, ১১ অক্টোবর ২০২৬)।
 *
 * ⓘ লেখে গ্রাহকের মডেল নিজে, ঘরটা বদলালেই ([[Customer::booted()]]) — তাই ফর্ম, ইমপোর্ট, সইয়ের শেষে নিজে বসা, যে পথেই
 * বদলাক, সারি বসে। কারণ আর সইয়ের সূত্র ডাকার পক্ষ বলে ([[because()]])।
 *
 * ⛔ কেবল যোগের: সারি বদলায় না, মোছে না।
 */
class CustomerCreditChange extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    // ⓘ সারি বসার দাগটা সাধারণ নিরীক্ষাতেও — বদল বিরল, তাই দাম নেই; বদলানো আর মোছা নিচে বন্ধ
    use IsAudited;

    // ⛔ বিক্রয়কর্মী কেবল নিজের ডিলারের ইতিহাস দেখেন (⛔১৬)
    use ScopedToUserDealers;

    /** যে ঘরগুলোর বদল এখানে ওঠে */
    public const FIELDS = ['credit_limit', 'credit_days'];

    public const UPDATED_AT = null;

    protected $table = 'customer_credit_changes';

    protected $fillable = [
        'company_id', 'customer_id', 'field', 'old_value', 'new_value', 'reason', 'approval_id', 'changed_by',
    ];

    /** @var list<array{reason: ?string, approval_id: ?int}> চলমান কাজের কারণ — ভিতরেরটা উপরে */
    private static array $context = [];

    /**
     * এই কাজের ভিতরে যে বদল হয়, তার কারণ আর সইয়ের সূত্র।
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function because(?string $reason, ?int $approvalId, callable $work): mixed
    {
        self::$context[] = ['reason' => $reason, 'approval_id' => $approvalId];

        try {
            return $work();
        } finally {
            array_pop(self::$context);
        }
    }

    /** গ্রাহকের সারি বদলের পরে — [[Customer::booted()]] ডাকে */
    public static function recordFor(Customer $customer): void
    {
        $context = self::$context === [] ? ['reason' => null, 'approval_id' => null] : self::$context[array_key_last(self::$context)];

        foreach (self::FIELDS as $field) {
            if (! $customer->wasChanged($field)) {
                continue;
            }

            $old = $customer->getOriginal($field);
            $new = $customer->getAttribute($field);

            // ⓘ "৫০০০০" আর "৫০০০০.০০০০" একই — অঙ্ক না বদলালে সারি নয়
            if ($field === 'credit_limit' && bccomp((string) ($old ?? '0'), (string) ($new ?? '0'), 4) === 0) {
                continue;
            }

            self::query()->create([
                'company_id' => (int) $customer->company_id,
                'customer_id' => (int) $customer->id,
                'field' => $field,
                'old_value' => $old === null ? null : (string) $old,
                'new_value' => $new === null ? null : (string) $new,
                'reason' => $context['reason'] === null ? null : mb_substr($context['reason'], 0, 500),
                'approval_id' => $context['approval_id'],
                'changed_by' => Actor::userId(),
            ]);
        }
    }

    protected static function booted(): void
    {
        static::updating(function (self $row): void {
            throw new \RuntimeException('সীমা বদলের ইতিহাস বদলানো যায় না — সারি #'.$row->id.'।');
        });

        static::deleting(function (self $row): void {
            throw new \RuntimeException('সীমা বদলের ইতিহাস মোছা যায় না — সারি #'.$row->id.'।');
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withoutGlobalScopes();
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}

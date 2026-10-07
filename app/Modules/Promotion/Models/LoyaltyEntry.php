<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Promotion\Support\LoyaltyKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * পয়েন্টের খাতার একটা সারি — স্পেক §৭-ঠ ও §১৩।
 *
 * ── ⛔ সারিটা কখনো বদলায় না, কখনো মোছে না ───────────────────────────
 * ⓘ পয়েন্ট আসলে টাকার দায়: ক্রেতা একদিন ওটা বিলে ছাড় হিসেবে চাইবেন।
 * ⚠️ একটা সারি বদলানো গেলে *"গত মাসে ওঁর ৫০০ পয়েন্ট ছিল"* কথাটা আর
 * প্রমাণ করা যেত না — খাতাটা যা বলত তা আজকের সংখ্যা, সেদিনের নয়।
 *
 * ⭐ তাই ভুল হলে উল্টো সারি ([[LoyaltyKind::REVERSE]]), আর এই মডেল
 * নিজেই বদলানো ও মোছা ফিরিয়ে দেয় — [[LoyaltyLedger]] ছাড়া অন্য কোনো
 * পথ লিখলেও।
 */
class LoyaltyEntry extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'promotion_loyalty_entries';

    protected $fillable = [
        'company_id', 'customer_id', 'promotion_application_id', 'kind', 'points',
        'expires_on', 'reverses_entry_id', 'source_type', 'source_id',
        'occurred_at', 'created_by',
    ];

    protected static function booted(): void
    {
        /*
         * ⛔ খাতার সারি হাতে বদলানো যায় না — কোনো পথেই।
         *
         * ⓘ সেবায় লিখে রাখলে যথেষ্ট হত না: টিঙ্কার, সিডার বা তাড়াহুড়োর
         * একটা কমান্ড সেবা এড়িয়ে যায়। ⚠️ মডেলে থাকলে এড়ানো কঠিন।
         */
        static::updating(function (): void {
            throw new LogicException('A loyalty ledger entry is never edited — write a reverse entry instead.');
        });

        static::deleting(function (): void {
            throw new LogicException('A loyalty ledger entry is never deleted — write a reverse entry instead.');
        });
    }

    protected function casts(): array
    {
        return [
            'kind' => LoyaltyKind::class,
            'points' => 'decimal:4',
            'expires_on' => 'date',
            'occurred_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(PromotionApplication::class, 'promotion_application_id');
    }

    /** ⓘ এই সারি কোন সারির ফেরত বা মেয়াদ-শেষ */
    public function target(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    /** ⭐ এই সারির ফেরত — থাকলে আর ফেরানো যায় না */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_entry_id')->where('kind', LoyaltyKind::REVERSE->value);
    }

    /** ⭐ এই অর্জনের মেয়াদ-শেষ — থাকলে আর ফুরোয় না */
    public function expiry(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_entry_id')->where('kind', LoyaltyKind::EXPIRE->value);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

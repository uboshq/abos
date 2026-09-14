<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\Drillable;
use App\Core\Contracts\SettledByAVoucher;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * জমার একটা চলাচল — খোলা, কিস্তি, মুনাফা তোলা, ভাঙা।
 *
 * ── কেন হাতধারের মতো কেবল চিহ্ন নয় ───────────────────────────────────
 * হাতধারে "ধার" আর "ফেরত" একই সম্পর্কের দুই দিক, তাই সেখানে চিহ্নই
 * যথেষ্ট। এখানে ঘটনাগুলো সত্যিই আলাদা: কিস্তি জমা দিলে মূলধন বাড়ে,
 * মুনাফা তুললে বাড়ে না — টাকাটা আয়। দুইটার খাতা-দাখিলাও আলাদা।
 *
 * চিহ্ন দিয়ে চালালে "এ পর্যন্ত কত জমেছে" প্রশ্নের উত্তরে মুনাফাও
 * যোগ হয়ে যেত, আর মেয়াদান্তে ব্যাংকের কাগজের সাথে মিলত না।
 */
class DepositMovement extends Model implements Drillable, SettledByAVoucher
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;

    /** টাকা রাখা হলো — প্রথম বার */
    public const OPENED = 'opened';

    /** মাসের কিস্তি — মূলধন বাড়ে */
    public const INSTALMENT = 'instalment';

    /** মুনাফা তোলা — মূলধন বাড়ে না, আয় হয় */
    public const PAYOUT = 'payout';

    /** ভাঙা বা মেয়াদপূর্তি — টাকা ফেরত */
    public const CLOSED = 'closed';

    /** @var list<string> */
    public const KINDS = [self::OPENED, self::INSTALMENT, self::PAYOUT, self::CLOSED];

    protected $table = 'fin_deposit_movements';

    protected $fillable = [
        'company_id', 'deposit_id', 'kind', 'amount', 'moved_on',
        'money_account_id', 'voucher_id', 'note', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'moved_on' => 'date',
        ];
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'money_account_id');
    }

    /**
     * যে দাখিলাটা হলো — প্রতিটা সংখ্যা ওখানেই খোলে (নিয়ম ১)।
     *
     * বাতিল দাখিলা মুছে গেলে জোড়াটা null হয়, আর সারিটা তবু থাকে:
     * ঘটনাটা সত্যিই ঘটেছিল।
     */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /** মূলধন বাড়ায় কি না — মুনাফা বাড়ায় না। */
    public function addsToPrincipal(): bool
    {
        return in_array($this->kind, [self::OPENED, self::INSTALMENT], true);
    }

    /**
     * ভাউচারটা পোস্ট হলো — এই গতিবিধির টাকাটা সত্যিই নড়েছে।
     *
     * ── ⚠️ এখানে ছাঁচটা [[CapitalEntry]]-র মতো **নয়**, আর কারণটা কলামে ─
     * ঐ নথিগুলোয় একটা `status` ঘর আছে, তাই নিষ্পত্তি মানে অবস্থা বদল।
     * ⛔ এই টেবিলে `status` **নেই** — এখানে সারিটা জন্মায়ই ঘটনা হিসেবে।
     *
     * ⭐ তাই নিষ্পন্ন হওয়ার একমাত্র চিহ্ন `voucher_id`, আর idempotency-র
     * পাহারা `whereNull()`। ⓘ চুক্তির শর্ত দুইটাই মানা হয়
     * ([[SettledByAVoucher]]), কেবল পথটা আলাদা।
     *
     * ⚠️ `whereNull` ছাড়া লিখলে একটা সারি দ্বিতীয় ভাউচারের সাথে বেঁধে
     * যেত — আর তখন আমানতের একই কিস্তি দুইটা দাখিলায় দাবি করা হত।
     */
    public function settleWith(int $voucherId): void
    {
        static::query()
            ->whereKey($this->getKey())
            ->whereNull('voucher_id')
            ->update(['voucher_id' => $voucherId]);

        $this->refresh();
    }

    /**
     * ভাউচারটা বাতিল হলো — জোড়াটা খুলে যায়, সারিটা থাকে।
     *
     * ⭐ সারিটা মোছা হয় না, আর সেটাই এই মডেলের নিজের নিয়ম: উপরের
     * টীকায় লেখা আছে *"ঘটনাটা সত্যিই ঘটেছিল"*। ⓘ টাকাটা ফেরত গেলেও
     * ইতিহাসে ঐ দিনটা থেকে যায়।
     */
    public function unsettle(int $voucherId): void
    {
        static::query()
            ->whereKey($this->getKey())
            ->where('voucher_id', $voucherId)
            ->update(['voucher_id' => null]);

        $this->refresh();
    }

    // ── Drillable — নিয়ম ১, "সংখ্যা থেকে কাগজে" ───────────────────────

    /**
     * ⛔ `drill_sources`-এ নাম বসানোই যথেষ্ট নয় — ক্লাসটাকেও ড্রিল করা
     * যেতে হবে।
     *
     * ── কী ধরা পড়েছিল, ১৪ সেপ্টেম্বর ২০২৬ ────────────────────────────
     * নিষ্পত্তির হুকের জন্য (`SettledByAVoucher`) নামটা মানচিত্রে বসানো
     * হলো, আর সেটা কাজও করল — কারণ [[VoucherService]] `map()` সরাসরি পড়ে।
     *
     * ⚠️ কিন্তু একই মানচিত্র [[DrillResolver::resolve()]]-ও পড়ে, আর সে
     * `Drillable` না পেলে **ব্যতিক্রম ছোঁড়ে**। ⓘ অর্থাৎ টাকা ঠিকই বসত,
     * সব সবুজ দেখাত, আর ভুলটা ধরা পড়ত সেদিন — মাস পরে — যেদিন কেউ
     * খতিয়ানের একটা সারি থেকে এই নথিতে ফিরতে চাইতেন।
     *
     * ⭐ ধরা পড়েছে [[EveryDrillSourceCanActuallyBeDrilledIntoTest]]-এ,
     * চোখে নয় — মানচিত্রের প্রতিটা নাম গুনে দেখে।
     *
     * আমানতের নিজের নম্বর নেই — গতিবিধিটা আমানতের নিচে, তাই ওটার নম্বরই মানুষ চেনে।
     */
    public static function drillSourceType(): string
    {
        return 'deposit_movement';
    }

    public function drillDocumentNo(): string
    {
        return $this->deposit?->document_no ?? (string) $this->id;
    }

    public function drillLabel(): string
    {
        return __('finance::menu.deposit_bank').' — '.$this->drillDocumentNo();
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    public function drillRoute(): array
    {
        return ['finance.deposit.show', ['issuer' => $this->deposit?->kind?->issuer ?? 'bank', 'deposit' => $this->deposit_id]];
    }
}

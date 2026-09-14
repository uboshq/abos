<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Core\Contracts\Drillable;
use App\Core\Contracts\SettledByAVoucher;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * এক মাসের ভাড়া — কতটা নগদে গেল, কতটা জামানত থেকে কাটল।
 *
 * ── কেন এই সারিটা আছে, যখন ভাউচারটাই সত্য ───────────────────────────
 * টাকার সত্য ভাউচারেই। ⚠️ কিন্তু *"এই চুক্তির কোন মাসগুলো করা হয়েছে"*
 * প্রশ্নটার উত্তর ভাউচার থেকে বের করা যায় না — খতিয়ানে মাসের নাম লেখা
 * থাকে না, আর একই খাতে পাঁচটা চুক্তির সারি মিশে থাকে।
 *
 * ⓘ তাই এই সারিটা টাকার দ্বিতীয় কপি নয়; সে কেবল বলে **কোন মাসটা করা
 * হয়েছে**, আর একই মাস দুইবার করা আটকায়।
 */
class RentalAdjustment extends Model implements Drillable, SettledByAVoucher
{
    use BelongsToCompany;
    use HasFactory;
    use HasPublicId;
    use IsAudited;
    use SoftDeletes;

    protected $table = 'fin_rental_adjustments';

    protected $fillable = [
        'company_id', 'branch_id', 'rental_contract_id',
        'for_month', 'rent', 'paid_cash', 'from_deposit',
        'voucher_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'for_month' => 'date',
            'rent' => 'decimal:4',
            'paid_cash' => 'decimal:4',
            'from_deposit' => 'decimal:4',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(RentalContract::class, 'rental_contract_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /** "সেপ্টেম্বর ২০২৬" — পর্দায় আর ছাপা কাগজে। */
    public function monthLabel(): string
    {
        return $this->for_month->translatedFormat('F Y');
    }

    /**
     * পরিশোধ ভাউচারটা পোস্ট হলো — এই মাসের ভাড়ার নগদ অংশটা গেছে।
     *
     * ── ⛔ চারটার মধ্যে এটাই সবচেয়ে আলাদা, আর কারণটা দুইটা ঘরে ─────
     * `paid_cash` ও `from_deposit` — অর্থাৎ **এক মাসের ভাড়ার দুইটা উৎস**
     * থাকতে পারে: কিছুটা হাত থেকে, কিছুটা জমা দেওয়া জামানত থেকে কাটা।
     *
     * ⚠️ ভাউচার কেবল `paid_cash` অংশটাই বহন করে। ⓘ জামানত থেকে কাটা
     * টাকা কারো হাত বদলায় না — ওটা দুইটা খাতের মধ্যে সমন্বয়, নগদের
     * চলাচল নয়। ⛔ ভাউচারে পুরো ভাড়া দাবি করলে **টাকাটা দুইবার বেরোত**:
     * একবার জামানত কমে, আরেকবার নগদ কমে।
     *
     * ⭐ তাই এখানে নিষ্পত্তি মানে কেবল **জোড়া লাগানো** — অঙ্ক নয়। কোন
     * অংশ কোথা থেকে গেল, সেটা সারিটাই ধরে রাখে।
     *
     * ⓘ এই টেবিলেও `status` নেই, তাই পাহারা `whereNull()` —
     * [[DepositMovement]] ও [[HandLoanMovement]]-এর মতোই।
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
     * ভাউচার বাতিল — নগদের জোড়াটা খোলে।
     *
     * ⚠️ `from_deposit` অংশটা অক্ষত থাকে, কারণ ওটা ভাউচারের কাজ ছিলই না।
     * ⓘ জামানত থেকে কাটা টাকা ফেরাতে হলে সেটা আলাদা সিদ্ধান্ত, আর
     * সেটার নিজের সারি থাকা দরকার।
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
     * ভাড়ার মাসিক সমন্বয় — চুক্তির নিচে, তাই চুক্তির নম্বর।
     */
    public static function drillSourceType(): string
    {
        return 'rental_adjustment';
    }

    public function drillDocumentNo(): string
    {
        return $this->contract?->document_no ?? (string) $this->id;
    }

    public function drillLabel(): string
    {
        return __('finance::menu.rental').' — '.$this->drillDocumentNo();
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    public function drillRoute(): array
    {
        return ['finance.rental.show', ['contract' => $this->rental_contract_id]];
    }
}

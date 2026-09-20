<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

use App\Core\Concerns\BelongsToCompanyThroughParent;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Modules\Purchase\Models\PurchaseBill;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * এক খরচ, একাধিক চালান — কার ঘাড়ে কতটা।
 *
 * ── ⭐ কেন সারিটা আলাদা টেবিলে ───────────────────────────────────────
 * মালিকের কথা: *"এক ট্রাকে একাধিক চালান এলে সবগুলোই বাছুন"*। ⓘ ভাউচারে
 * একটামাত্র `bill_id` রাখলে ঐ ট্রাকের দ্বিতীয় চালানটা কোনোদিন ভাড়া
 * পেত না, আর তার মালের দাম চিরকাল কম দেখাত।
 *
 * ── ⚠️ কেন ভাগটা লেখা থাকে, হিসাব করা হয় না ─────────────────────────
 * `share_amount` বসানোর সময়েই লেখা হয়। ⛔ প্রতিবার হিসাব করে বের করলে
 * অনুপাতের নিয়ম (পরিমাণ/মূল্য/ওজন) পরে বদলালে **পুরনো অনুমোদিত
 * ভাউচারের ভাগও বদলে যেত** — আর অনুমোদিত কাগজ নিজে থেকে বদলায় না।
 *
 * ⓘ `basis`-ও সেজন্যই সারিতে থাকে: সংখ্যাটা উপরে লেখা আছেই, এটা রাখা
 * হয় **কেন ঐ সংখ্যা** তার উত্তর দিতে। ছয় মাস পরে কেউ প্রশ্ন করলে
 * এটাই একমাত্র সাক্ষী।
 */
final class VoucherBillShare extends Model
{
    use BelongsToCompanyThroughParent;
    use HasPublicId;

    /*
     * ⭐ অডিট — ২১ সেপ্টেম্বর ২০২৬, অডিটে ধরা।
     *
     * ⛔ এই টেবিলটা অডিটের বাইরে ছিল, আর কেউ টের পায়নি: পাহারাটা
     * (`EveryChangeableRowRemembersWhoChangedIt`) নোঙর হিসেবে `
class`
     * খুঁজত, তাই `final class` কোনোদিন দেখতই না।
     *
     * ⚠️ আর জিনিসটা টাকার: কোন ক্রয় বিলের বিপরীতে কত বসল, সেটা এখানেই
     * লেখা। ⓘ ভাউচার সম্পাদনায় সারিগুলো প্রতিবার **মুছে নতুন করে**
     * লেখা হয় ([[VoucherService]]), অর্থাৎ সরবরাহকারীর বিলের মধ্যে টাকা
     * সরত আর কোনো হিসাব থাকত না।
     */
    use IsAudited;

    protected $table = 'acc_voucher_bill_shares';

    protected $fillable = [
        'voucher_id', 'purchase_bill_id', 'share_amount', 'basis',
    ];

    protected function casts(): array
    {
        return [
            'share_amount' => 'decimal:4',
        ];
    }

    /**
     * এই সারির কাগজ — আর তার `company_id`-ই এটাকে বাঁধে।
     *
     * ⓘ পুরো কারণটা [[BelongsToCompanyThroughParent]]-এ।
     */
    protected function companyParent(): string
    {
        return 'voucher';
    }

    /**
     * অডিটের সারিটা কার খাতায় — ভাউচারের, প্রসঙ্গের নয়।
     *
     * ── ⛔ কেন এটা নাম ধরে বলা দরকার, ২১ সেপ্টেম্বর ২০২৬ ─────────────
     * এই সারির নিজের `company_id` নেই; সে তার ভাউচারের মাধ্যমে বাঁধা।
     * ⓘ [[IsAudited]] তখন চলতি প্রসঙ্গ থেকে আইডিটা নিত, আর **প্রসঙ্গ
     * ঐ সারিটা সত্যিই আছে কি না জানে না**।
     *
     * ⚠️ আর অডিট লেখার চেষ্টা ব্যর্থ হলে সেটা চুপ করে থাকে না — **মূল
     * কাজটাকেই ফেলে দেয়**। ⛔ অর্থাৎ ভাউচার সংরক্ষণ করতে গিয়ে ৫০০,
     * আর ব্যবহারকারী বুঝতেই পারেন না কী হলো।
     *
     * ⭐ ভাউচারটাই একমাত্র সৎ উৎস: সারিটা যার, খাতাটাও তার।
     */
    public function auditCompanyId(): ?int
    {
        return $this->voucher?->company_id;
    }

    /** ⓘ একই কারণে শাখাটাও — ভাউচার কোন অফিসের কাগজ, সে-ই জানে। */
    public function auditBranchId(): ?int
    {
        return $this->voucher?->branch_id;
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function purchaseBill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class, 'purchase_bill_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Models;

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

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function purchaseBill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class, 'purchase_bill_id');
    }
}

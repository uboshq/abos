<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ট্রিপের একটা সারি — গাড়িতে ওঠা একটা চালান, আর তার পরিণতি।
 *
 * ── কেন সন্তান-সারিতেও [[BelongsToCompany]] (৬ সেপ্টেম্বর ২০২৬) ───────
 * টেবিলে `company_id` **আছে** আর ভরাও থাকে, অথচ গ্লোবাল স্কোপ ছিল না —
 * গোটা রিপোতে এটাই ছিল একমাত্র এমন মডেল (১২৪-এর ১টা)।
 *
 * আজ ফাঁস ছিল না: দুইটা কোয়েরিই বাবার মধ্য দিয়ে যায়
 * (`whereHas('shipment')`, আর [[Shipment]]-এ স্কোপ আছে), আর
 * [[ShipmentService::syncLines]] হাতে `company_id` বসায়।
 *
 * ⚠️ কিন্তু ট্রেইটটার নিজের মন্তব্যই এই ভরসার বিরুদ্ধে লেখা: *"হাতে
 * লিখলে একদিন কেউ একটা কোয়েরিতে লিখতে ভুলবে, আর সেই একটাই যথেষ্ট।"*
 * ⓘ কাল কেউ `ShipmentLine::query()->where('delivery_challan_id', $id)`
 * লিখলে **সব কোম্পানির সারি ফিরত**, আর কেউ টের পেত না।
 *
 * ⭐ বিদ্যমান দুইটা কোয়েরি এখন দুইবার ছাঁকা হয় — খরচ নেই, ভুলের পথ বন্ধ।
 */
class ShipmentLine extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'sal_shipment_lines';

    /**
     * পথে যা হতে পারে।
     *
     * ── কেন ঠিক এই চারটাই ───────────────────────────────────────────
     * ডিপোর সন্ধ্যায় চালক যা বলেন তার সবটাই এই চারটার একটা:
     *
     *   • `pending` — এখনো বলা হয়নি। গাড়ি ফেরেনি, বা সারিটা বুঝে
     *     নেওয়া বাকি। ট্রিপ বন্ধ করতে গেলে একটাও থাকা চলবে না।
     *   • `delivered` — ক্রেতা মাল বুঝে নিয়েছেন। স্বাভাবিক ঘটনা।
     *   • `returned` — মাল ফিরে এসেছে (দোকান বন্ধ, ক্রেতা নেননি,
     *     গাড়িই পৌঁছায়নি)। মালটা এখন গুদামে, কিন্তু খাতায় "গেছে" —
     *     তাই চালানটা বাতিল বা ফেরত না হওয়া পর্যন্ত ট্রিপ বন্ধ হয় না।
     *   • `short` — কিছুটা পৌঁছেছে, কিছুটা ফিরেছে। এটাও ফেরতের কাগজ
     *     চায়, কেবল অংশটুকুর।
     *
     * তালিকাটা এখানে বাঁধা, সেটিংসের সারি নয় — কারণ এগুলো ব্যবসার
     * পছন্দ নয়, খাতার অবস্থা: প্রতিটার সাথে একটা করে নিয়ম জোড়া, আর
     * নতুন একটা যোগ করা মানে নতুন একটা নিয়ম লেখা।
     */
    public const PENDING = 'pending';

    public const DELIVERED = 'delivered';

    public const RETURNED = 'returned';

    public const SHORT = 'short';

    public const OUTCOMES = [self::PENDING, self::DELIVERED, self::RETURNED, self::SHORT];

    /** যেগুলোর জন্য মাল ফেরানোর কাগজ লাগে। */
    public const NEEDS_GOODS_BACK = [self::RETURNED, self::SHORT];

    protected $fillable = [
        'company_id', 'shipment_id', 'delivery_challan_id', 'line_no',
        'outcome', 'outcome_note', 'settled_at',
    ];

    protected function casts(): array
    {
        return ['settled_at' => 'datetime'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function challan(): BelongsTo
    {
        return $this->belongsTo(DeliveryChallan::class, 'delivery_challan_id');
    }

    public function isSettled(): bool
    {
        return $this->outcome !== self::PENDING;
    }

    public function needsGoodsBack(): bool
    {
        return in_array($this->outcome, self::NEEDS_GOODS_BACK, true);
    }
}

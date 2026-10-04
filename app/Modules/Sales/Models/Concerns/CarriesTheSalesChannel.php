<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models\Concerns;

use App\Core\Support\DocumentStatus;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\SalesChannel;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * বিক্রয়ের কাগজ নিজের পথটা নিজের গায়ে লিখে রাখে — NEXUS §২৮।
 *
 * ── ⭐ কেন এটা কাগজের সারিতে, গ্রাহকের সারি থেকে join করে নয় ──────────
 * গ্রাহকের পথ বদলায়। ⛔ join করে পড়লে একদিন একজনকে "খুচরা" থেকে "ডিলার"
 * করলে তাঁর গত বছরের প্রতিটা বিক্রিও ডিলার-পথে সরে যেত, আর পথের রিপোর্টের
 * পুরনো মাসগুলো নীরবে বদলাত। ⓘ কাগজে লেখা পথটা বিক্রির মুহূর্তের সত্য।
 *
 * ── কোথা থেকে পথটা আসে ─────────────────────────────────────────────
 *   ⓵ চালান আদেশ থেকে, ফেরত বিল থেকে — একই বিক্রির পরের কাগজ আগের
 *      কাগজের পথই বয়; মাঝপথে গ্রাহক বদলালেও একটা বিক্রি দুই পথে ভাগ হয় না।
 *   ⓶ নাহলে গ্রাহকের আজকের পথ।
 *   ⓷ গ্রাহকের পথ বসানো না থাকলে খালি — ⛔ ডিফল্ট পথ আন্দাজে বসানো হয় না;
 *      রিপোর্ট খালিগুলো "পথ বসানো হয়নি" নামে আলাদা দেখায়, আর সেটাই কাজ।
 *
 * ── ⚠️ কখন আবার লেখা হয় ────────────────────────────────────────────
 * কেবল **খসড়ায়** গ্রাহক বদলালে — খসড়া এখনো বিক্রি নয়। ⛔ নিশ্চিত হওয়া
 * কাগজে কখনো নয়: ওটাই ইতিহাস।
 *
 * ⓘ ঘরটা `fillable`-এ নেই, ইচ্ছাকৃতভাবে: ফর্ম থেকে কেউ পথ পাঠিয়ে
 * গ্রাহকের পথ এড়াতে পারবেন না — একটাই উৎস, আর সেটা এই পদ্ধতি।
 *
 * @mixin Model
 */
trait CarriesTheSalesChannel
{
    protected static function bootCarriesTheSalesChannel(): void
    {
        static::saving(function (Model $document): void {
            $isNew = ! $document->exists;
            $customerChangedOnDraft = $document->exists
                && $document->isDirty('customer_id')
                && (string) $document->getAttribute('status') === DocumentStatus::DRAFT;

            if (! $isNew && ! $customerChangedOnDraft) {
                return;
            }

            $document->setAttribute('channel_id', static::channelAtThisMoment($document));
        });
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class, 'channel_id')->withTrashed();
    }

    /**
     * এই মুহূর্তে কাগজটার পথ কোনটা।
     *
     * ⓘ মডেলের কোম্পানি-স্কোপ খাটে (Eloquent), তাই অন্য কোম্পানির
     * কাগজ বা গ্রাহক থেকে পথ ধার করা সম্ভব নয়।
     */
    protected static function channelAtThisMoment(Model $document): ?int
    {
        foreach (static::channelParents() as $column => $parent) {
            $parentId = $document->getAttribute($column);

            if ($parentId === null) {
                continue;
            }

            $channel = $parent::query()->whereKey($parentId)->value('channel_id');

            if ($channel !== null) {
                return (int) $channel;
            }
        }

        $customerId = $document->getAttribute('customer_id');

        if ($customerId === null) {
            return null;
        }

        $channel = Customer::query()->whereKey($customerId)->value('channel_id');

        return $channel === null ? null : (int) $channel;
    }

    /**
     * আগের কাগজ — যার পথ এই কাগজ বয়ে নেয়। ঘরের নাম → মডেল।
     *
     * ⓘ আদেশ ও বিল নিজেরাই শুরু, তাই ওদের কিছু নেই। ⚠️ বিলের মাথায় চালানের
     * ঘর নেই (চালান বাঁধা থাকে সারিতে, আর সারি বসে মাথার পরে) — তাই বিল
     * গ্রাহকের আজকের পথ নেয়; বিলই বিক্রির মুহূর্ত, তাই ওটাই ঠিক।
     *
     * @return array<string, class-string<Model>>
     */
    protected static function channelParents(): array
    {
        return match (static::class) {
            DeliveryChallan::class => ['sales_order_id' => SalesOrder::class],
            SalesReturn::class => ['sales_invoice_id' => SalesInvoice::class],
            default => [],
        };
    }
}

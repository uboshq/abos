<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Contracts\SalesOffers;
use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryChallanLine;
use App\Modules\Sales\Models\SalesInvoiceLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ খসড়া চালানে অফার — দেখা, বসানো, তোলা (অডিট §১১, ২৯ সেপ্টেম্বর ২০২৬)।
 *
 * ── ⓘ কী করে আর কী করে না ────────────────────────────────────────────
 * অফারের হিসাব Promotion-এর ([[SalesOffers]] চুক্তি দিয়ে)। ⭐ এখানে কেবল চালানের দিক:
 * কোন সারির প্রসঙ্গ কী, কাগজটা এখনো বদলানো যায় কি না, আর বসানো ছাড় সারির
 * `promotion_discount`-এ লেখা। ⛔ সিস্টেম নিজে কিছু বসায় না — প্রতিটা বসানো একজন
 * মানুষের চাপা বোতাম (স্পেক §১০)।
 *
 * ── ⚠️ কেবল অফিসের খসড়া চালান ──────────────────────────────────────
 * ⓵ পাকা চালানে নয় — মাল বেরিয়ে গেছে, দর আর ছাড় তখন খাতার।
 * ⓶ কাউন্টারের চালানে নয় — ওগুলো কাউন্টারেই বদলায় (সমন্বয়কের নির্দেশ: দ্বিতীয়
 *   সম্পাদনার দরজা নয়)। ⓘ কাউন্টারের খসড়া চালানের সাথে একটা বিল একসাথে জন্মায়
 *   ([[DirectSaleService::hold()]]); অফিসের খসড়া চালানের কোনো বিল থাকে না — চেনার
 *   নিয়ম ঐটাই ([[isCounterDraft()]])।
 *
 * ── ⚠️ বাকির দেয়াল চালানের মোট দেখে — ইচ্ছাকৃতভাবে ─────────────────────
 * ⓘ চালানের `total`-এ অফারের ছাড় বাদ যায় না; ছাড়টা নামে বিলে। ⛔ তাই বাকির সীমা
 * চালানে একটু বেশি পাওনা ধরে — আর সেটাই নিরাপদ দিক: কম ধরলে ছাড়ের ভরসায় সীমা
 * পেরোনো মাল বেরিয়ে যেত।
 */
final class ChallanOffers
{
    public function __construct(private readonly SalesOffers $offers) {}

    public function enabled(): bool
    {
        return $this->offers->enabled();
    }

    /** অফিসের খসড়া চালান — অফার বসানো যায়; বাকিগুলো কেবল দেখা যায়। */
    public function editable(DeliveryChallan $challan): bool
    {
        return $challan->status === DocumentStatus::DRAFT && ! $this->isCounterDraft($challan);
    }

    /** ⓘ কাউন্টারের খসড়া চালান — তার সারির সাথে আগে থেকেই একটা বিলের সারি বাঁধা। */
    public function isCounterDraft(DeliveryChallan $challan): bool
    {
        return SalesInvoiceLine::query()
            ->whereIn('delivery_challan_line_id', $challan->lines()->select('id'))
            ->exists();
    }

    /**
     * পাতার প্যানেল — প্রতিটা সারির যোগ্য, প্রায়-যোগ্য আর বসানো অফার।
     *
     * @return list<array{line: DeliveryChallanLine, eligible: list<array<string, mixed>>, almost: list<array<string, mixed>>, applied: list<array<string, mixed>>}>
     */
    public function panel(DeliveryChallan $challan): array
    {
        $challan->loadMissing(['lines.product', 'customer']);

        $applied = collect($this->offers->appliedOn(DeliveryChallan::drillSourceType(), (int) $challan->id))
            ->groupBy('line_id');

        $rows = [];

        foreach ($challan->lines as $line) {
            $found = $this->offers->suggest($this->contextOf($challan, $line));

            $rows[] = [
                'line' => $line,
                'eligible' => $found['eligible'],
                'almost' => $found['almost'],
                'applied' => ($applied->get($line->id) ?? collect())->values()->all(),
            ];
        }

        return $rows;
    }

    public function apply(DeliveryChallan $challan, int $lineId, int $offerId): void
    {
        $this->assertEditable($challan);

        DB::transaction(function () use ($challan, $lineId, $offerId) {
            $line = $this->lineOf($challan, $lineId);

            $this->offers->apply(
                $offerId,
                $this->contextOf($challan, $line),
                DeliveryChallan::drillSourceType(),
                (int) $challan->id,
                (int) $line->id,
            );

            $this->resync($challan, $line);
        });
    }

    public function remove(DeliveryChallan $challan, int $lineId, int $offerId): void
    {
        $this->assertEditable($challan);

        DB::transaction(function () use ($challan, $lineId, $offerId) {
            $line = $this->lineOf($challan, $lineId);

            $this->offers->remove($offerId, DeliveryChallan::drillSourceType(), (int) $challan->id, (int) $line->id);

            $this->resync($challan, $line);
        });
    }

    /**
     * ⛔ কাগজের সব অফার উল্টানো — বাতিলে, আর খসড়ার সারি নতুন করে বসলে।
     *
     * ⚠️ খসড়া সম্পাদনায় সারিগুলো মুছে নতুন করে বসে ([[DeliveryChallanService::replaceLines()]]),
     * তাই পুরনো সারির আইডি ধরে বসানো অফার অনাথ হয়ে যেত — ছাড় বসে থাকত এমন সারিতে
     * যেটা আর নেই, আর বাজেট ঐ টাকা "খরচ" ধরে রাখত। ⭐ তাই সম্পাদনায় সব অফার উঠে
     * যায়, আর মানুষ নতুন সারিতে আবার বসান — কাগজ বদলালে প্রস্তাবও নতুন।
     */
    public function reverseAll(DeliveryChallan $challan): void
    {
        $this->offers->reverseAll(DeliveryChallan::drillSourceType(), (int) $challan->id);

        DeliveryChallanLine::query()
            ->where('delivery_challan_id', $challan->id)
            ->update(['promotion_discount' => '0']);
    }

    /**
     * ইঞ্জিনের সারি-প্রসঙ্গ — ক্রেতা, শাখা, গুদাম, পণ্য, পরিমাণ, মূল্য।
     *
     * @return array<string, mixed>
     */
    public function contextOf(DeliveryChallan $challan, DeliveryChallanLine $line): array
    {
        $customer = $challan->customer;
        $product = $line->product;

        return [
            'customer_id' => $challan->customer_id !== null ? (int) $challan->customer_id : null,
            'party_type_id' => $customer?->party_type_id !== null ? (int) $customer->party_type_id : null,
            'location_id' => $customer?->location_id !== null ? (int) $customer->location_id : null,
            'branch_id' => $challan->branch_id !== null ? (int) $challan->branch_id : null,
            'warehouse_id' => $challan->warehouse_id !== null ? (int) $challan->warehouse_id : null,
            'product_id' => (int) $line->product_id,
            'category_id' => $product?->category_id !== null ? (int) $product->category_id : null,
            'brand_id' => $product?->brand_id !== null ? (int) $product->brand_id : null,
            'qty' => (string) $line->delivered_qty,
            'value' => bcmul((string) $line->delivered_qty, (string) $line->rate, 4),
        ];
    }

    /**
     * ⭐ সারির ছাড় = এই সারিতে বসানো অফারগুলোর মোট — প্রতিবার নতুন করে গোনা।
     *
     * ⚠️ যোগ-বিয়োগ করে রাখলে দুইবার চাপা বা ব্যর্থ একটা চেষ্টায় অঙ্কটা সরে যেত;
     * অফারের খাতা থেকে গুনলে দুইটা কখনো আলাদা হয় না।
     */
    private function resync(DeliveryChallan $challan, DeliveryChallanLine $line): void
    {
        $sum = collect($this->offers->appliedOn(DeliveryChallan::drillSourceType(), (int) $challan->id))
            ->where('line_id', (int) $line->id)
            ->reduce(fn (string $carry, array $row) => bcadd($carry, (string) $row['worth'], 4), '0');

        $line->forceFill(['promotion_discount' => $sum])->save();
    }

    private function lineOf(DeliveryChallan $challan, int $lineId): DeliveryChallanLine
    {
        $line = DeliveryChallanLine::query()
            ->where('delivery_challan_id', $challan->id)
            ->whereKey($lineId)
            ->lockForUpdate()
            ->first();

        if ($line === null) {
            throw ValidationException::withMessages(['line' => __('sales::offers.line_not_here')]);
        }

        return $line;
    }

    private function assertEditable(DeliveryChallan $challan): void
    {
        // ⛔ দরজাতেও সুইচ — পাতায় অংশটা লুকানো থাকলেও সরাসরি অনুরোধে অফার যেন না বসে (৪ অক্টোবর ২০২৬)
        if (! $this->enabled()) {
            throw ValidationException::withMessages(['offer_id' => __('core.offers_off')]);
        }

        if ($challan->status !== DocumentStatus::DRAFT) {
            throw ValidationException::withMessages(['status' => __('sales::offers.only_draft', ['no' => $challan->document_no])]);
        }

        if ($this->isCounterDraft($challan)) {
            throw ValidationException::withMessages(['status' => __('sales::offers.counter_later')]);
        }
    }
}

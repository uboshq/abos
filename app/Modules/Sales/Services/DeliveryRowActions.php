<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Support\DocumentStatus;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryState;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\ShipmentLine;

/**
 * সারি থেকেই "পৌঁছেছে" — মালিক, ৩ অক্টোবর ২০২৬: "sob jaygathekei"।
 *
 * ── কী দেয় ────────────────────────────────────────────────────────────
 * পুরো পাতার চালানগুলোর জন্য, এক প্রশ্নে: কোন চালানে এখন হাতে "পৌঁছেছে" (আর তার পাশে আংশিক/পৌঁছায়নি)
 * বসানো যায়। বোতামটা আঁকে [[delivery/partials/row-action]], ফর্ম যায় পুরনো দরজায় (`sales.delivery.move`) —
 * নতুন কোনো দরজা নয়, নিয়ম সবই [[DeliveryStageService]]-এ।
 *
 * ── কেন [[DeliveryStageService::manualChoices()]] সরাসরি নয় ─────────────
 * ওটা `ensure()` ডাকে, যা ধাপের সারি না থাকলে **লেখে** — ইনভয়েস তালিকা খুললেই পঞ্চাশটা পুরনো চালানে
 * সারি বসত, আর সারি ধরে পঞ্চাশটা প্রশ্ন। ⓘ এখানে কেবল পড়া: ধাপের সারি নেই মানে চালানটা হাতে রওনা হয়নি
 * (রওনা নিজেই সারি লেখে), তাই "পৌঁছেছে"-র প্রশ্নই নেই।
 *
 * ⛔ কেবল রওনার পরে — যে ধাপ থেকে হাতে "পৌঁছেছে" খোলা ([[DeliveryStage::manualNext()]]); তার আগে বোতাম নেই।
 * ⛔ চলতি ট্রিপে থাকা চালানে নয় — ওর খবর ট্রিপ দেয় ([[DeliveryStage::TRIP_OWNED]])।
 * ⛔ চাবি: `sales.delivery.update` — `move` দরজা যেটা চায়; দেখার চাবিতে বোতাম আসে না।
 */
final class DeliveryRowActions
{
    /** সারিতে যে ধাপগুলো — রওনার পরের; তোলা/প্যাক/রওনা ডেলিভারির নিজের তালিকায় */
    public const AFTER_DISPATCH = [DeliveryStage::DELIVERED, DeliveryStage::PARTIALLY_DELIVERED, DeliveryStage::FAILED];

    /**
     * @param  iterable<int>  $challanIds
     * @return array<int, array{choices: list<string>, trip: null}>  চালানের id → সারির বোতাম
     */
    public function forChallans(iterable $challanIds): array
    {
        $ids = collect($challanIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty() || ! auth()->user()?->can('sales.delivery.update')) {
            return [];
        }

        $onTrip = ShipmentLine::query()
            ->whereIn('delivery_challan_id', $ids)
            ->whereHas('shipment', fn ($q) => $q->whereIn('status', [DocumentStatus::DRAFT, DocumentStatus::CONFIRMED]))
            ->pluck('delivery_challan_id')->map(fn ($id) => (int) $id)->flip();

        $out = [];

        /*
         * ⓘ কেবল "রওনা" ধাপ — তোলা/প্যাক থেকেও সরাসরি "পৌঁছেছে" খোলা (ডিপো থেকে হাতে দেওয়া), কিন্তু সেটা ডেলিভারির
         * নিজের তালিকার কাজ; এখানে চাওয়া কেবল রওনার পরেরটা (কোঅর্ডিনেটর, ৩ অক্টোবর ২০২৬)।
         */
        $dispatched = DeliveryState::query()->whereIn('delivery_challan_id', $ids)
            ->where('stage', DeliveryStage::DISPATCHED)
            ->pluck('stage', 'delivery_challan_id');

        foreach ($dispatched as $challanId => $stage) {
            $choices = array_values(array_intersect(DeliveryStage::manualNext((string) $stage), self::AFTER_DISPATCH));

            if (in_array(DeliveryStage::DELIVERED, $choices, true) && ! $onTrip->has((int) $challanId)) {
                $out[(int) $challanId] = ['choices' => $choices, 'trip' => null];
            }
        }

        return $out;
    }

    /**
     * ইনভয়েস তালিকার জন্য — বিলের প্রথম সারির চালান ([[ChallanTransportController::forInvoice()]]-এর একই নিয়ম),
     * পুরো পাতার এক প্রশ্নে; বাকিটা [[forChallans()]]।
     *
     * @param  iterable<int>  $invoiceIds
     * @return array<int, array{challan: DeliveryChallan, choices: list<string>, trip: null}>  ইনভয়েসের id → সারির বোতাম
     */
    public function forInvoices(iterable $invoiceIds): array
    {
        $ids = collect($invoiceIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty() || ! auth()->user()?->can('sales.delivery.update')) {
            return [];
        }

        $challanOf = SalesInvoiceLine::query()
            ->whereIn('sales_invoice_id', $ids)
            ->whereNotNull('delivery_challan_line_id')
            ->with('challanLine:id,delivery_challan_id')
            ->orderBy('id')
            ->get()
            ->groupBy('sales_invoice_id')
            ->map(fn ($lines) => (int) $lines->first()->challanLine?->delivery_challan_id)
            ->filter();

        $rows = $this->forChallans($challanOf->values());

        if ($rows === []) {
            return [];
        }

        $challans = DeliveryChallan::query()->whereKey(array_keys($rows))->with('customer')->get()->keyBy('id');
        $out = [];

        foreach ($challanOf as $invoiceId => $challanId) {
            if (isset($rows[$challanId], $challans[$challanId])) {
                $out[(int) $invoiceId] = ['challan' => $challans[$challanId]] + $rows[$challanId];
            }
        }

        return $out;
    }
}

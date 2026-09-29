<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Collection;

/**
 * কাগজের QR থেকে চালান — কে স্ক্যান করলেন তার চোখে।
 *
 * ── ⭐ মালিকের নির্দেশ, ৩০ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"ekta qr add korbe zate mobile scane korei delivery dap gulo complate r porer daper nirdes
 * dite pare"* — আর তারপর *"ekoi code dilar scane kore tar hisab r invoice dekte pare r confm
 * korte pare"*। একই QR, দুই রকম মানুষ ([[DeliveryScanController]])।
 *
 * ── ⚠️ কেন কোয়েরি এখানে, কন্ট্রোলারে নয় ──────────────────────────────
 * ডিলারের পাতা পোর্টালের দরজার পিছনে, আর [[EveryPortalScreenAsksTheNarrowPathTest]] পোর্টালের
 * কন্ট্রোলারে সরাসরি কোয়েরি গোনে। ⭐ তাই "কার চালান" প্রশ্নের উত্তর কেবল এখানে: কর্মীর জন্য
 * সাধারণ কোয়েরি (কোম্পানি আর শাখার দেয়াল যেমন আছে), ডিলারের জন্য গার্ডের গ্রাহক ধরে — ডাকার
 * জায়গা কোনো গ্রাহক আইডি পাঠায় না, তাই অন্যের চালান খোলার কোনো পথ থাকে না।
 *
 * ⓘ QR-এ কেবল চালানের `public_id` — ক্রমিক আইডি নয় (গুনে গুনে অন্যের কাগজ খোঁজা যেত), দাম নয়।
 */
final class ScannedPaper
{
    public function __construct(private readonly CustomerPapers $papers) {}

    /**
     * কর্মীর চোখে — কোম্পানি আর শাখার দেয়াল মডেলের নিজের স্কোপেই; বাইরের চালান ৪০৪।
     */
    public function forStaff(string $publicId): DeliveryChallan
    {
        return DeliveryChallan::query()->where('public_id', $publicId)->firstOrFail();
    }

    /**
     * ডিলারের চোখে — কেবল তাঁর নিজের চালান; অন্যেরটা ৪০৪, ৪০৩ নয় (থাকার খবরও ফাঁস নয়)।
     *
     * ⓘ গ্রাহকের শাখা নেই, তাই কর্মীর শাখা-ছাঁকনি সরে — কিন্তু একই লাইনে গ্রাহকের শর্ত বসে
     * ([[CustomerPapers]]-এর একই নিয়ম)।
     */
    public function forDealer(string $publicId): DeliveryChallan
    {
        return DeliveryChallan::query()
            ->withoutGlobalScope('user-branch')
            ->where('customer_id', $this->dealer()->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    /** যিনি পোর্টালে ঢুকেছেন — কোম্পানির প্রসঙ্গও এখানেই বসে */
    public function dealer(): Customer
    {
        return $this->papers->customer();
    }

    /**
     * এই চালানের বিলগুলো — সারির সূত্র ধরে (একটা চালান থেকে একাধিক বিল হতে পারে)।
     *
     * ⚠️ বাতিল বিল বাদ নয়: ডিলার যে কাগজ হাতে ধরে আছেন সেটা বাতিল হলে তাঁকে সেটাই দেখতে হবে।
     *
     * @return Collection<int, SalesInvoice>
     */
    public function invoicesOf(DeliveryChallan $challan): Collection
    {
        return SalesInvoice::query()
            ->withoutGlobalScope('user-branch')
            ->where('customer_id', $challan->customer_id)
            ->whereHas('lines.challanLine', fn ($q) => $q->where('delivery_challan_id', $challan->id))
            ->orderBy('id')
            ->get();
    }
}

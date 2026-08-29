<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Listeners;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\KitchenTicketService;
use App\Modules\Sales\Events\InvoiceConfirmed;
use App\Modules\Sales\Models\SalesInvoice;

/**
 * বিল নিশ্চিত হলে রান্নাঘরে টিকিট যায় — রেস্টুরেন্টের ধাপ ৪।
 *
 * ── কেন শোনার মাধ্যমে, `confirm()`-এর ভেতরে নয় ───────────────────────
 * বিলের ভেতরে লিখলে বিক্রয় মডিউলকে রান্নাঘরের কথা জানতে হত, আর তখন
 * Sales → Inventory → Sales একটা চক্র হত। এদিক থেকে শুনলে কোনো নতুন
 * নির্ভরতা লাগে না: Inventory বিক্রয়কে আগে থেকেই চেনে (রেসিপি বিক্রিতে
 * উপকরণ কমায়), আর বিক্রয় রান্নাঘরের অস্তিত্বও জানে না।
 *
 * ── কেন খাতা ইভেন্টে যায় না, কিন্তু টিকিট যায় ───────────────────────
 * `module.php`-তে লেখা আছে: দাখিলা ও স্টক চলাচল ইভেন্টে যায় না, কারণ
 * ইভেন্ট একদিন হারায় আর খাতা হারানো যায় না।
 *
 * টিকিট আলাদা। ওটা একটা **নির্দেশ**, হিসাব নয়। হারালে রাঁধুনি কাগজ
 * দেখে রাঁধেন — যেভাবে আজ পর্যন্ত রেঁধেছেন — আর কোনো টাকা বা মাল ভুল
 * হয় না। এই তফাতটাই ঠিক করে দেয় কোনটা ট্রানজেকশনে থাকবে আর কোনটা
 * ইভেন্টে।
 */
final class SendTheOrderToTheKitchen
{
    public function __construct(
        private readonly KitchenTicketService $kitchen,
    ) {}

    public function handle(InvoiceConfirmed $event): void
    {
        /*
         * ইভেন্ট `public_id` বয়, `id` নয় — আর সেটাই ঠিক।
         *
         * ভেতরের সংখ্যাটা মডিউলের নিজের ব্যাপার; শ্রোতা `public_id`
         * ধরে নিজের কোড দিয়ে খুঁজে নেয়। ইভেন্টের নথিতে ওটা লেখাই
         * আছে, আর আমি প্রথমে `$event->invoiceId` লিখে ধরা খেয়েছি।
         */
        $invoice = SalesInvoice::query()
            ->with('lines.product')
            ->where('public_id', $event->publicId)
            ->first();

        if ($invoice === null) {
            return;
        }

        /*
         * প্রতিটা সারি পাঠানো হয়; কোনটার টিকিট হবে সেটা সার্ভিস ঠিক
         * করে ([[KitchenTicketService::raise()]] — কেবল অর্ডারে-রান্না)।
         *
         * এখানে ছেঁকে দিলে নিয়মটা দুই জায়গায় থাকত, আর একদিন একটা
         * বদলাত: রান্নাঘরে হাঁড়ির খাবারের টিকিটও যেত, বা যেত না
         * সত্যিকারের অর্ডারের টিকিট।
         */
        $lines = $invoice->lines
            ->filter(fn ($line) => $line->product instanceof Product)
            ->map(fn ($line) => ['product' => $line->product, 'qty' => (string) $line->qty])
            ->values()
            ->all();

        if ($lines === []) {
            return;
        }

        $this->kitchen->raise(
            sourceType: SalesInvoice::STOCK_SOURCE,
            sourceId: $invoice->id,
            documentNo: $invoice->document_no,
            lines: $lines,
            branchId: $invoice->branch_id,
            note: $invoice->narration,
        );
    }
}

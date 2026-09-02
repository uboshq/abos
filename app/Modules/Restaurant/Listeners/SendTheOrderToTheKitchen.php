<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Listeners;

use App\Modules\Inventory\Models\Product;
use App\Modules\Restaurant\Services\KitchenTicketService;
use App\Modules\Sales\Events\InvoiceConfirmed;
use App\Modules\Sales\Models\SalesInvoice;

/**
 * বিল নিশ্চিত হলে রান্নাঘরে টিকিট যায় — রেস্টুরেন্টের ধাপ ৪।
 *
 * ── কেন এই ফাইলটা বিক্রয়ে, মজুদে নয় ─────────────────────────────────
 * প্রথমে এটা লেখা হয়েছিল `Inventory/Listeners/`-এ, আর মন্তব্যে দাবি
 * করা ছিল "Inventory বিক্রয়কে আগে থেকেই চেনে"। **কথাটা মিথ্যা ছিল।**
 * `Inventory/module.php`-এর `depends_on` ছিল `['master_data',
 * 'accounts']` — বিক্রয় ওখানে নেই, আর [[BoundariesTest]] সেটাই ধরল।
 *
 * ঘোষণা করে দেওয়া যেত না: বিক্রয় মজুদের উপর নির্ভর করে, তাই উল্টো
 * ঘোষণাটা একটা চক্র বানাত — আর [[ModuleRegistry::sortByDependency()]]
 * বুট-টাইমেই ছুড়ে ফেলত, অর্থাৎ পুরো অ্যাপ বন্ধ।
 *
 * নির্ভরতার তীরটা যেদিকে সত্যি, ফাইলটাও সেদিকে: **বিক্রয় মজুদকে
 * চেনে**, তাই রান্নাঘরের সার্ভিসটা ডাকার অধিকার বিক্রয়েরই আছে।
 *
 * ── তবু শোনার মাধ্যমে কেন, `confirm()`-এর ভেতরে নয় ──────────────────
 * টিকিটটা বিলের অংশ নয়। রান্নাঘরের সার্ভিস ব্যতিক্রম ছুড়লে বিলটা
 * ফিরে যাওয়া উচিত নয় — খাবারের অর্ডার আটকে দেওয়া আর টাকার হিসাব
 * ভুল হওয়া এক জিনিস নয়। ইভেন্ট দুইটাকে আলাদা রাখে।
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

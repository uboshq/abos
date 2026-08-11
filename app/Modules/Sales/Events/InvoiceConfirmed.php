<?php

declare(strict_types=1);

namespace App\Modules\Sales\Events;

use App\Core\Events\DomainEvent;
use App\Modules\Sales\Models\SalesInvoice;

/**
 * একটা বিক্রয় বিল খাতায় বসে গেল।
 *
 * ── কী ঘটে গেছে ──────────────────────────────────────────────────────
 * খসড়া ছিল, এখন নিশ্চিত। মাল গুদাম থেকে নেমেছে, দাখিলা খতিয়ানে বসেছে,
 * গ্রাহকের নামে পাওনা উঠেছে। **এই সবগুলো ইতিমধ্যেই হয়ে গেছে** — এই
 * ঘটনাটা ছোড়ার সময় লেনদেনটা শেষ।
 *
 * ── এই ঘটনায় কে কী করতে পারে, আর কী পারে না ──────────────────────────
 * করতে পারে: গ্রাহককে SMS, মালিকের পর্দায় সংখ্যা বাড়ানো, Governance-এ
 * সারি লেখা, ফুরিয়ে আসা পণ্যের সতর্কতা।
 *
 * **করতে পারে না: হিসাবের দাখিলা, স্টক চলাচল, নম্বর ইস্যু।** ওগুলো
 * confirm()-এর ভেতরে, একই ট্রানজেকশনে হয়ে গেছে — আর সেখানেই থাকবে।
 * ইভেন্ট একদিন হারায়; খাতা হারানো যায় না।
 */
final class InvoiceConfirmed extends DomainEvent
{
    public static function from(SalesInvoice $invoice): self
    {
        return new self(
            publicId: (string) $invoice->public_id,

            /*
             * পেলোডে ঠিক ততটুকু, যতটুকু না হলে শ্রোতা এক পা-ও এগোতে
             * পারে না।
             *
             * নম্বর ও মোট আছে, কারণ SMS বা বিজ্ঞপ্তি লিখতে ওই দুইটাই
             * লাগে — আর ওই দুইটার জন্য ডাটাবেজে ফেরত যাওয়াটা অপচয়।
             * গ্রাহকের নাম নেই: যার নাম লাগবে সে public_id ধরে নিজে
             * খুঁজে নেবে, নিজের মডিউলের কোড দিয়ে।
             */
            payload: [
                'document_no' => (string) $invoice->document_no,
                'total' => (string) $invoice->total,
                'trx_date' => (string) $invoice->trx_date?->toDateString(),
            ],
        );
    }
}

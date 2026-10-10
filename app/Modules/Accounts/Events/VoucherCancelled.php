<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Events;

use App\Core\Events\DomainEvent;
use App\Modules\Accounts\Models\Voucher;

/**
 * ⭐ একটা ভাউচার বাতিল হলো — বাতিলের **একই লেনদেনের ভেতরে** ছোড়া হয় (পুরো-ERP অডিট, অর্থ M22, ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ অন্য মডিউলের যে কাগজ এই ভাউচারে টাকা নিয়েছিল (উত্তোলন, লাভের ভাগ, ভাড়ার মাস), সে নিজের অবস্থা ফেরায়। ⛔ আগে Accounts থেকে
 * বাতিল করলে খাতা উল্টাত, কিন্তু কাগজটা "নিশ্চিত"-ই থাকত আর মূলধন/লাভ/জামানতের গোনায় চলতেই থাকত।
 * ⚠️ [[VoucherPosted]]-এর মতো afterCommit নয়: শোনার জন ব্যর্থ হলে বাতিলটাও ফেরে, কাগজ আর খাতা কখনো আলাদা হয় না।
 * ⓘ Accounts কারও উপর নির্ভর করে না — ঘটনাটা কেবল জানায়, কে শোনে তা জানে না।
 */
final class VoucherCancelled extends DomainEvent
{
    public static function from(Voucher $voucher): self
    {
        return new self(
            publicId: (string) $voucher->public_id,
            payload: [
                'voucher_id' => (int) $voucher->id,
                'type' => (string) $voucher->type,
                'document_no' => (string) $voucher->document_no,
            ],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Listeners;

use App\Core\Services\PartyAutoSend;
use App\Modules\Accounts\Events\VoucherPosted;
use App\Modules\Accounts\Models\Voucher;

/**
 * ⭐ রসিদ (RCV) পাকা হলো — পার্টিকে "টাকা পেয়েছি" নিজে থেকে (মালিকের "ok", ২৩ নভেম্বর ২০২৬; নমুনা HUXqpfhxng8823eEFrvH54,
 * ট্যাব ঙ "টাকা পাওয়ার খবর")।
 *
 * ⓘ কেবল শোনে আর সারিতে দেয় ([[PartyAutoSend::receiptPosted()]]) — রসিদের পোস্ট, খাতা বা কোনো হিসাব বদলায় না; `VoucherPosted`
 *   লেনদেন পাকা হওয়ার পরেই আসে ([[VoucherService::post()]]-এর `DB::afterCommit`)। ⓘ নিয়ম বন্ধ থাকলে কিছুই নয়।
 * ⓘ এখানে, Notification-এ নয়: বিজ্ঞপ্তি মডিউল কারও উপর নির্ভর করে না (`depends_on` খালি), আর হিসাবও কেবল কোরকে চেনে —
 *   [[CapitalFromReceipt]]-এর মতো, যে মডিউলের ঘটনা সে নিজেই শোনে।
 * ⛔ খবরের ব্যর্থতা কখনো রসিদকে থামায় না — ভুল হলে কেবল লগে।
 */
final class TellThePartyTheMoneyCame
{
    public function handle(VoucherPosted $event): void
    {
        if (($event->payload['type'] ?? null) !== Voucher::RECEIPT || empty($event->payload['party_id'])) {
            return;
        }

        try {
            // ⛔ শাখার দেয়াল ছাড়া — পোস্টকারীর মাথায় অন্য শাখা থাকলেও রসিদটা পাওয়া যায় ([[CapitalFromReceipt]]-এর একই কারণ)
            $voucher = Voucher::query()->withoutGlobalScope('user-branch')->where('public_id', $event->publicId)->first();

            if ($voucher !== null) {
                // ⓘ ভিতরে তোলা, কনস্ট্রাক্টরে নয় — সেবা গড়তে কিছু ভাঙলেও (ধরুন Cloud API-র সেটিং) রসিদের পোস্ট অক্ষত থাকে
                app(PartyAutoSend::class)->receiptPosted($voucher, 'accounts_voucher');
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}

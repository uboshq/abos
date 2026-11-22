<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ⭐ WhatsApp/SMS step 3 (owner "ok" 23 Nov 2026, sample HUXqpfhxng8823eEFrvH54, tab খ): three new starter texts —
 * SMS "বাকির তাগাদা", WhatsApp "হিসাব বিবরণী" (both about the party's account) and SMS "টাকা পেয়েছি (ছোট)" (receipt).
 *
 * ⓘ A company that has no text at all gets every starter the first day its box or texts page opens
 *   ([[PartyMessageTemplate::ensureStarters()]]) — so only companies that already have texts are touched here, and only
 *   with a name they do not have yet (an owner who renamed or switched one off keeps his choice).
 * ⓘ Literal rows, not the model's list: a migration must write the same thing on every machine, forever.
 */
return new class extends Migration
{
    private const ROWS = [
        ['sms', 'party_account', 'বাকির তাগাদা', 60,
            "প্রিয় {party},\n{company}-এ আপনার বাকি ৳{due} (সবচেয়ে পুরনো বিল {paper_no}, {date})।\nবিস্তারিত: {link}\nধন্যবাদ।",
            "Dear {party},\nYour balance with {company} is Tk {due} (oldest bill {paper_no}, {date}).\nDetails: {link}\nThank you."],
        ['whatsapp', 'party_account', 'হিসাব বিবরণী', 70,
            "প্রিয় {party},\n{company}-এ আপনার হিসাব বিবরণী — এখনকার বাকি ৳{due}।\nদেখতে ও নামাতে: {link}\nধন্যবাদ।",
            "Dear {party},\nYour account statement with {company} — balance now Tk {due}.\nView and download: {link}\nThank you."],
        ['sms', 'sales_collection', 'টাকা পেয়েছি (ছোট)', 80,
            "প্রিয় {party},\nআপনার ৳{amount} পেয়েছি — রসিদ {paper_no} ({date})। বাকি এখন ৳{due}।\nধন্যবাদ, {company}",
            "Dear {party},\nWe received Tk {amount} — receipt {paper_no} ({date}). Your balance now is Tk {due}.\nThank you, {company}"],
    ];

    public function up(): void
    {
        $now = Carbon::now();

        $companies = DB::table('party_message_templates')->distinct()->pluck('company_id');

        foreach ($companies as $companyId) {
            $have = DB::table('party_message_templates')->where('company_id', $companyId)->pluck('name')->all();

            foreach (self::ROWS as [$channel, $type, $name, $sort, $bn, $en]) {
                if (in_array($name, $have, true)) {
                    continue;
                }

                DB::table('party_message_templates')->insert([
                    'public_id' => (string) Str::uuid7(),
                    'company_id' => $companyId,
                    'channel' => $channel,
                    'document_type' => $type,
                    'name' => $name,
                    'body_bn' => $bn,
                    'body_en' => $en,
                    'is_active' => true,
                    'sort' => $sort,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // ⓘ Nothing to undo safely: a starter may have been edited or used by the log since — the rows stay.
    }
};

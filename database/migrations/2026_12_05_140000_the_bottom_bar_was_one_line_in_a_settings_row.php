<?php

declare(strict_types=1);

use App\Core\Support\NoticePriority;
use App\Core\Support\NoticeStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * নিচের বারটা ছিল সেটিংসের একটা সারিতে লেখা একটা লাইন।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬, ধারা ১২ ────────────────────
 * বারটা হবে নোটিশ-চালিত: অগ্রাধিকার, একাধিক নোটিশ, ঘূর্ণন, View
 * Details, Acknowledge।
 *
 * ── ⓘ আগে যা ছিল ────────────────────────────────────────────────────
 * `system.notice` — একটা সেটিংস সারিতে একটা লেখা। ⚠️ ওটা একটা লাইনই
 * ছিল: কোনো লেখক নেই, তারিখ নেই, কে দেখবে তার হিসাব নেই, আর কে পড়েছে
 * তারও নয়।
 *
 * ── ⚠️ কেন লেখাটা ফেলে দেওয়া যায় না ─────────────────────────────────
 * ⓘ লাইভে ওখানে মালিকের নিজের লেখা বসে থাকতে পারে — *"ওভার ডিউ আছে
 * ও যাদের লেনদেন খারাপ তাদের বাকি দেওয়া নিষেধ"*। ⛔ মাইগ্রেশনে ওটা
 * মুছে দিলে পরদিন সকালে গোটা অফিসের চোখের সামনে থেকে একটা নিয়ম
 * উধাও হয়ে যেত, আর কেউ বলতে পারত না কেন।
 *
 * ⭐ তাই লেখাটা একটা সত্যিকারের নোটিশ হয়ে যায় — প্রকাশিত, বারে, আর
 * সবার জন্য। ⓘ সেটিংসের সারিটা তোলা হয় তার **পরে**, আর কেবল যদি
 * নোটিশটা সত্যিই বসে থাকে।
 */
return new class extends Migration
{
    private const OLD_KEY = 'system.notice';

    public function up(): void
    {
        $rows = DB::table('settings')->where('key', self::OLD_KEY)->get();

        foreach ($rows as $row) {
            $text = trim((string) ($row->value ?? ''));

            /* ⓘ খালি সারিটা কেবল তুলে দেওয়া — বলার মতো কিছু নেই */
            if ($text === '') {
                DB::table('settings')->where('id', $row->id)->delete();

                continue;
            }

            /*
             * ⚠️ শিরোনামটা লেখা থেকে কেটে নেওয়া, আর কাটাটা **শব্দের
             * সীমানায়**।
             *
             * ⛔ মাঝপথে কাটলে অর্থ উল্টে যেতে পারে: *"বাকি দেওয়া যাবে"*
             * … *"না"*। ⓘ গোটা লেখাটা `body`-তে অক্ষত থাকে, তাই ক্লিক
             * করলে পুরোটাই পড়া যায়।
             */
            $title = Str::limit($text, 90, '…');

            $id = DB::table('notices')->insertGetId([
                'public_id' => (string) Str::uuid7(),
                'company_id' => $row->company_id,
                'document_no' => null,
                'status' => NoticeStatus::PUBLISHED->value,

                /*
                 * ⓘ `IMPORTANT`, `CRITICAL` নয় — আর এটা ইচ্ছাকৃত।
                 *
                 * ⚠️ `CRITICAL` হলে নোটিশটা সরানো যেত না আর সই চাইত
                 * ([[NoticePriority]])। ⛔ পুরনো লেখাটা ঐ শর্তে লেখা
                 * হয়নি, তাই ওটার উপর নতুন কড়াকড়ি চাপানো অন্যায় হত।
                 *
                 * ⓘ `IMPORTANT` যথেষ্ট: বারে ওঠার জন্য এটাই সর্বনিম্ন।
                 */
                'priority' => NoticePriority::IMPORTANT->value,

                'title' => $title,
                'body' => $text,
                'summary' => null,
                'starts_on' => null,
                'ends_on' => null,
                'published_at' => now(),
                'is_active' => true,
                'in_ticker' => true,
                'read_required' => false,
                'ack_required' => false,
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            /*
             * ⛔ সেটিংসের সারিটা মোছা হয় **নোটিশটা বসার পরে**, আগে নয়।
             *
             * ⚠️ উল্টো করলে মাঝপথে কিছু ভাঙলে লেখাটা দুই জায়গার
             * কোথাও থাকত না। ⓘ এই ক্রমে সবচেয়ে খারাপ ফল হলো লেখাটা
             * দুই জায়গায় থাকা — যা পড়ে নেওয়া যায়, হারানো যায় না।
             */
            if ($id > 0) {
                DB::table('settings')->where('id', $row->id)->delete();
            }
        }
    }

    /**
     * ⓘ ফেরার পথ নেই, আর সেটা বলে দেওয়াই সৎ।
     *
     * ⚠️ নোটিশটা ইতিমধ্যে প্রকাশিত — মানুষ পড়ে ফেলেছে, কেউ হয়তো
     * মন্তব্যও করেছে। ⛔ `down()`-এ ওটাকে আবার একটা সেটিংসের লাইনে
     * চেপে বসানো মানে ঐ ইতিহাসটা মুছে ফেলা।
     */
    public function down(): void
    {
        // ইচ্ছাকৃতভাবে খালি — উপরের কারণ দেখুন।
    }
};

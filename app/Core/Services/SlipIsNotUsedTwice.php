<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Support\CompanyContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * একই স্লিপ দুইবার নয় — টাকার কাগজে নকল ধরার একমাত্র সৎ উপায়।
 *
 * ── মালিকের যুক্তি, ৫ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
 * *"গত সপ্তাহে একটা অর্ডার দিয়েছে, সে সব প্রোডাক্ট নিবে — তাহলে
 * quantity তো same, date same না। অনলাইন টাকা payment same হতে পারে,
 * slip same হবে না, slip no same হবে না।"*
 *
 * ⭐ অর্থাৎ **অঙ্ক দিয়ে নকল ধরা যায় না।** একই গ্রাহক পরপর দুই সপ্তাহে
 * হুবহু একই মাল নিতে পারেন, হুবহু একই টাকা দিতে পারেন — ওটা নকল নয়,
 * ওটাই ব্যবসা। ⛔ অঙ্ক ধরে আটকালে সফটওয়্যার সৎ লেনদেনই আটকাত।
 *
 * ⓘ যা কখনো দুইবার হয় না তা হলো **রেফারেন্স নম্বর** — বিকাশের TrxID,
 * ব্যাংকের স্লিপ নম্বর, চেকের নম্বর। সেটাই একমাত্র জায়গা যেখানে
 * "দুইবার" কথাটার অর্থ আছে।
 *
 * ── ⚠️ কী ভাঙা ছিল ──────────────────────────────────────────────────
 * ঘরটা (`instrument_no`) আদায় ও পরিশোধ দুইটাতেই আছে, কিন্তু কোনো
 * পাহারা ছিল না — একই স্লিপ দশবার বসানো যেত। ফল:
 *
 *     গ্রাহকের পাওনা যতবার বসানো হয় ততবার কমে (টাকা এসেছে একবার)
 *     নগদ/ব্যাংকের ব্যালেন্স বেশি দেখায়
 *     আর ধরা পড়ে মাস শেষে ব্যাংক মেলাতে গিয়ে — তখন কোনটা আসল বলা কঠিন
 *
 * ⓘ ব্যাংক ট্রান্সফারে এই পাহারাটা আগে থেকেই আছে
 * (`vouchers_bank_reference_unique`, ২৯ আগস্ট)। এটা সেই একই সিদ্ধান্ত,
 * বাকি দুইটা দরজায়।
 *
 * ── কেন একটাই ক্লাস, দুই সার্ভিসে দুইবার নয় ─────────────────────────
 * নিয়মটা এক — খালি হলে ছেড়ে দাও, নাহলে খুঁজে দেখো — কিন্তু দুই
 * জায়গায় লিখলে একদিন একটায় বাতিল কাগজ বাদ যেত, অন্যটায় নয়। ⚠️ আর
 * তখন একই স্লিপ এক দরজায় আটকাত, অন্য দরজায় ঢুকত।
 */
final class SlipIsNotUsedTwice
{
    /**
     * একই স্লিপ নম্বর এই কোম্পানিতে আগে বসেছে কি না।
     *
     * @param  string  $table  কোন টেবিলে খোঁজা হবে
     * @param  string  $party  কোন কলাম পক্ষকে বোঝায় (supplier_id / customer_id)
     * @param  string  $message  ব্যবহারকারী যে বাক্যটা পড়বেন
     */
    public function check(
        string $table,
        ?string $slipNo,
        ?int $partyId,
        string $party,
        string $message,
        ?int $exceptId = null,
    ): void {
        /*
         * ⛔ খালি হলে কিছুই করা হয় না — আর এটা ছাড় নয়, নিয়ম।
         *
         * ⚠️ হাতে হাতে নগদ নিলে কোনো স্লিপই থাকে না, আর ডিপোর
         * বেশিরভাগ আদায় ঠিক তা-ই। খালি ঘরকে "নকল" গণ্য করলে দিনের
         * দ্বিতীয় নগদ আদায়টাই আটকে যেত।
         */
        if (blank($slipNo)) {
            return;
        }

        $slipNo = trim((string) $slipNo);

        $exists = $this->query($table, $slipNo, $partyId, $party, $exceptId)->exists();

        if ($exists) {
            throw ValidationException::withMessages(['instrument_no' => $message]);
        }
    }

    /**
     * ── ⚠️ কেন পক্ষ ধরে, গোটা কোম্পানি ধরে নয় ─────────────────────────
     * দুইটা ব্যাংকের স্লিপ নম্বর একই হতেই পারে — ওরা আলাদা প্রতিষ্ঠান,
     * নিজের নিজের ক্রম। ⛔ কোম্পানি ধরে অনন্য করলে দ্বিতীয় ব্যাংকের
     * `000123` স্লিপটা আটকে যেত, অথচ ওটা সম্পূর্ণ বৈধ।
     *
     * ⓘ পক্ষ ধরলে প্রশ্নটা সঠিক হয়: *"এই সরবরাহকারীকে এই স্লিপে আগে
     * টাকা দিয়েছি কি না"* — আর ঐ প্রশ্নের উত্তর "হ্যাঁ" হলে সেটা
     * সত্যিই নকল।
     */
    private function query(
        string $table,
        string $slipNo,
        ?int $partyId,
        string $party,
        ?int $exceptId,
    ): Builder {
        return DB::table($table)
            ->where('company_id', CompanyContext::id())
            ->whereRaw('LOWER(TRIM(instrument_no)) = ?', [mb_strtolower($slipNo)])
            ->when($partyId !== null, fn (Builder $q) => $q->where($party, $partyId))
            ->when($exceptId !== null, fn (Builder $q) => $q->where('id', '!=', $exceptId))

            /*
             * ⭐ বাতিল করা কাগজ গোনা হয় না।
             *
             * ⚠️ ভুল করে বসানো একটা আদায় বাতিল করে **আবার ঠিকভাবে
             * বসানো** সবচেয়ে স্বাভাবিক কাজ। বাতিলটা গুনলে সফটওয়্যার
             * বলত "এই স্লিপ আগে বসেছে" — আর ব্যবহারকারীর হাতে তখন
             * কোনো পথ থাকত না।
             */
            ->whereNull('deleted_at')
            ->where('status', '!=', 'cancelled');
    }
}

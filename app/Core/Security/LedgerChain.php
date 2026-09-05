<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Models\LedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * খাতাটা কেউ বদলায়নি — দাবি নয়, প্রমাণ।
 *
 * ── কী ছিল না ────────────────────────────────────────────────────────
 * খতিয়ান শুধু-যোগের: সারি সম্পাদনা হয় না, মোছা হয় না, ভুল হলে উল্টো
 * সারি বসে। **কিন্তু ওটা অ্যাপের নিয়ম, ডাটাবেজের নয়।** একটা `mysql`
 * প্রম্পট, একটা ব্যাকআপ ফাইল সম্পাদনা করে ফেরত আনা, বা DBA-র একটা
 * `UPDATE` — তিনটার যেকোনোটাই একটা অঙ্ক বদলে দিতে পারত, আর
 * **কোথাও কোনো চিহ্ন থাকত না**।
 *
 * তাই "আমাদের খাতা কেউ বদলায়নি" কথাটা বলা যেত, প্রমাণ করা যেত না। আর
 * নিরীক্ষায় ওই দুইটার পার্থক্যই সব।
 *
 * ── চেইনটা কীভাবে কাজ করে ────────────────────────────────────────────
 * প্রতিটা সারি নিজের আগের সারির ছাপ ধরে রাখে:
 *
 *     row_hash = HMAC(prev_hash + এই সারির অপরিবর্তনীয় ঘরগুলো)
 *
 * একটা অঙ্ক বদলালে ওই সারির `row_hash` আর মেলে না; আর সেটা ঠিক করতে
 * হলে **তার পরের প্রতিটা সারিও** নতুন করে গুনতে হয়, শেষ সারি পর্যন্ত।
 * চাবিটা ছাড়া সেটা করা যায় না, আর চাবি থাকলে ডাম্পটাও খোলা যায় —
 * অর্থাৎ এটা নতুন কোনো গোপনীয়তা দাবি করে না, কেবল **নীরব সম্পাদনাকে
 * সরব করে তোলে**।
 *
 * ── কোম্পানি ধরে আলাদা চেইন ──────────────────────────────────────────
 * একটাই চেইন হলে এক কোম্পানির সারি বদলালে **বাকি তিন কোম্পানির খাতাও
 * "ভাঙা" দেখাত**, আর তারা কিছুই করেনি। বহু-টেন্যান্ট পণ্যে ওটা
 * অগ্রহণযোগ্য: একজনের ঘটনা অন্যজনের রিপোর্টে দেখা যায় না।
 *
 * ── কেন আলাদা একটা মাথার টেবিল ───────────────────────────────────────
 * শেষ সারিটা `ORDER BY id DESC LIMIT 1` দিয়ে খুঁজলে দুইটা একসাথে চলা
 * পোস্টিং **একই আগের সারি** পড়ত, আর চেইনটা দুই ভাগ হয়ে যেত। মাথার
 * সারিটা `lockForUpdate()`-এ ধরা হয়, তাই দ্বিতীয়জন অপেক্ষা করে —
 * ঠিক যেভাবে নম্বর সিরিজ কাজ করে ([[IssuedNumber]])।
 *
 * খালি টেবিলে লক করার মতো সারি থাকে না, আর সেটাই একটা আলাদা টেবিল
 * রাখার দ্বিতীয় কারণ: প্রথম সারিটা বসানোর সময়েও একটা কিছু ধরার থাকে।
 */
final class LedgerChain
{
    /** একটা সারির অঙ্ক বদলেছে। */
    public const ROW = 'row';

    /** শেষ থেকে সারি সরানো হয়েছে — বাকিটা নিখুঁত, কিন্তু ছোট। */
    public const TAIL = 'tail';

    /**
     * যে ঘরগুলো চেইনে ঢোকে।
     *
     * ── কেন এগুলোই, আর কেন বাকিগুলো নয় ──────────────────────────────
     * যা বদলালে **টাকার অঙ্ক বা তার অর্থ বদলায়** — কেবল সেগুলো। বিবরণ
     * (`narration`) বাইরে: ওটা মানুষের লেখা, আর বানান ঠিক করলে গোটা
     * চেইন ভাঙা অর্থহীন।
     *
     * `created_at` ভেতরে, কারণ একটা সারি **কখন লেখা হয়েছিল** সেটাও
     * ইতিহাসের অংশ — পিছিয়ে বসানো একটা এন্ট্রি আর সত্যিই সেদিন লেখা
     * একটা এন্ট্রি এক জিনিস নয়।
     *
     * @var list<string>
     */
    private const SIGNED = [
        'company_id', 'branch_id', 'financial_year_id', 'account_id',
        'party_type', 'party_id', 'cost_center_id',
        'trx_date', 'debit', 'credit',
        'source_type', 'source_id', 'source_line_id',
        'created_at',
    ];

    /**
     * পরের সারির ছাপ — মাথাটা ধরে রেখে।
     *
     * পোস্টিং ইঞ্জিনের ট্রানজেকশনের ভেতরে ডাকা হয়, তাই লকটা পুরো
     * ডকুমেন্টের জন্য একবারই নেওয়া হয় — প্রতি সারিতে নয়।
     *
     * @return array{0: ?string, 1: string} [আগের ছাপ, এই সারির ছাপ]
     */
    public static function next(LedgerEntry $entry): array
    {
        $companyId = (int) $entry->company_id;

        $previous = DB::table('ledger_chain_heads')
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->value('last_hash');

        if ($previous === null && ! DB::table('ledger_chain_heads')->where('company_id', $companyId)->exists()) {
            DB::table('ledger_chain_heads')->insert([
                'company_id' => $companyId,
                'last_hash' => null,
                'entries' => 0,
            ]);

            $previous = DB::table('ledger_chain_heads')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->value('last_hash');
        }

        $hash = self::hash($previous, $entry->getAttributes());

        DB::table('ledger_chain_heads')->where('company_id', $companyId)->update([
            'last_hash' => $hash,
            'entries' => DB::raw('entries + 1'),
        ]);

        return [$previous, $hash];
    }

    /**
     * টাকার ঘর — চার ঘর দশমিক, সবসময়।
     *
     * @var list<string>
     */
    private const MONEY = ['debit', 'credit'];

    /**
     * সময়ের ঘর, আর প্রত্যেকটার নিজের চেহারা।
     *
     * @var array<string, string>
     */
    private const MOMENTS = ['trx_date' => 'Y-m-d', 'created_at' => 'Y-m-d H:i:s'];

    /**
     * একটা সারির ছাপ গোনা।
     *
     * ── কেন প্রতিটা মান আগে একটা চেহারায় আনা হয় ──────────────────────
     * প্রথম চেষ্টায় শুধু `(string)` করা হয়েছিল, আর তাতে চেইনটা **নিজের
     * লেখা সারিও চিনতে পারেনি**। কারণ একই অঙ্ক দুই জায়গায় দুই রকম:
     *
     *     লেখার সময় (মডেল)      408000.0        `408000`
     *     পড়ার সময় (ডাটাবেজ)    decimal(18,4)   `408000.0000`
     *
     * তারিখেও তাই — মডেলে `Carbon`, ডাটাবেজে `2026-07-01`। আর
     * `created_at` তো লেখার সময় **শূন্যই** থাকত ([[LedgerEntry::booted()]]
     * দেখুন)। তিনটাই আলাদা কারণ, কিন্তু ফল একটাই: প্রতিটা সারি "বদলে
     * গেছে" দেখাত, আর একদিন কেউ সত্যিকারের ভাঙাটাকেও ওই কোলাহলের
     * অংশ ধরে নিত।
     *
     * তাই ছাপটা কাঁচা মানের উপর নয়, **ঘোষিত চেহারার উপর** — যেটা
     * মডেল থেকে এলেও এক, ডাটাবেজ থেকে এলেও এক।
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function hash(?string $previous, array $attributes): string
    {
        $parts = [$previous ?? ''];

        foreach (self::SIGNED as $field) {
            $parts[] = self::canonical($field, $attributes[$field] ?? null);
        }

        return hash_hmac('sha256', implode('|', $parts), (string) config('app.key'));
    }

    /**
     * চেইনটা আবার সিল করা — **কেবল আমাদের নিজের, ইচ্ছাকৃত বদলের পরে**।
     *
     * ── ⛔ এটা কোনো "সারানোর" যন্ত্র নয় ──────────────────────────────
     * চেইন ভাঙা মানে দুইটার একটা: হয় কেউ অ্যাপের বাইরে দিয়ে খাতা
     * বদলেছে, নয় **আমরা নিজেরাই একটা মাইগ্রেশনে সারি সরিয়েছি**।
     *
     * ⚠️ প্রথমটার উত্তর কখনোই "আবার সিল দাও" নয় — তাতে প্রমাণটাই মুছে
     * যায়। ⭐ এটা কেবল দ্বিতীয়টার জন্য: যে বদলটা আমরা জেনেশুনে, কোডের
     * ভিতর দিয়ে, একটা ঘোষিত মাইগ্রেশনে করেছি। ⓘ উপরে `canonical()`-এর
     * মন্তব্যে এই কাজটার কথা আগেই লেখা ছিল — *"সব সারি নতুন করে ছাপ
     * দিতে হবে, একটা মাইগ্রেশনে, ঘোষণা করে"*। এটা সেটাই।
     *
     * ── কেন এটা লাগল, ৫ সেপ্টেম্বর ২০২৬ ─────────────────────────────
     * `one_payable_head_held_three_different_debts` মাইগ্রেশনটা
     * `ledger_entries.account_id` **UPDATE** করে (দলে বসে থাকা টাকা
     * নিচের খাতে সরায় — কাজটা ঠিক)। কিন্তু `account_id` `SIGNED`-এর
     * ভিতরে, তাই প্রতিটা সরানো সারির ছাপ ভুল হয়ে যায়, আর চেইন ধরে
     * তার পরের সবগুলোও।
     *
     * ⛔ ফল: `abos:books-check` চারটা কোম্পানিতে বলত *"কেউ অ্যাপের
     * বাইরে দিয়ে খাতা বদলেছে"* — একটা **মিথ্যা অভিযোগ** — আর
     * `deploy.sh` ঠিক কাজই করেছে: ডিপ্লয় ফিরিয়ে দিয়েছে। ⚠️ গ্রাহকের
     * পর্দাতেও ওই একই মিথ্যা উঠত, আর হিসাবের সফটওয়্যার তার চেয়ে খারাপ
     * কথা বলতে পারে না।
     *
     * ── কেন `verify()`-এর হুবহু একই ক্রম ────────────────────────────
     * ⚠️ দুইজন আলাদা করে হাঁটলে একদিন দুই কথা বলত — সিল বসত এক ক্রমে,
     * যাচাই হত আরেক ক্রমে, আর কেউ ধরতে পারত না কেন। তাই
     * `withoutGlobalScopes()` · `orderBy('id')` · `row_hash` না থাকলে
     * এড়িয়ে যাওয়া — তিনটাই এক।
     *
     * @return int কয়টা সারিতে নতুন সিল বসল
     */
    public static function reseal(int $companyId): int
    {
        $previous = null;
        $sealed = 0;
        $last = null;

        LedgerEntry::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$previous, &$sealed, &$last): void {
                foreach ($rows as $row) {
                    if ($row->row_hash === null) {
                        continue;
                    }

                    $hash = self::hash($previous, $row->getAttributes());

                    /*
                     * ⓘ যে সারির ছাপ আগে থেকেই ঠিক, তাকে ছোঁয়া হয় না —
                     * অকারণে লেখা হত, আর "কয়টা সারি বদলাতে হলো" সংখ্যাটাও
                     * মিথ্যা বলত।
                     */
                    if (! hash_equals($hash, (string) $row->row_hash)
                        || (string) $row->prev_hash !== (string) $previous) {
                        DB::table('ledger_entries')
                            ->where('id', $row->id)
                            ->update(['prev_hash' => $previous, 'row_hash' => $hash]);

                        $sealed++;
                    }

                    $previous = $hash;
                    $last = $hash;
                }
            });

        /*
         * ⚠️ মাথাটাও বসাতে হয়, নাহলে **পরের সারিটা** পুরনো ছাপ ধরে লেখা
         * হত আর চেইন সাথে সাথে আবার ভাঙত। ⓘ `verify()` মাথাটা আলাদা
         * করেই দেখে (শেষ ছাপ ও গোনা সংখ্যা দুইটাই), তাই বাদ দিলে ধরা
         * পড়ত — কিন্তু ততক্ষণে আরও কয়েকটা সারি লেখা হয়ে গেছে।
         */
        DB::table('ledger_chain_heads')->updateOrInsert(
            ['company_id' => $companyId],
            [
                'last_hash' => $last,
                'entries' => LedgerEntry::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->whereNotNull('row_hash')
                    ->count(),
            ],
        );

        return $sealed;
    }

    /**
     * একটা ঘরের একটাই চেহারা।
     *
     * ⚠️ **এটা বদলালে আগের প্রতিটা সারির ছাপ অকেজো হবে** — অর্থাৎ
     * পুরো চেইন ভাঙা দেখাবে অথচ কেউ কিছু বদলায়নি। বদলাতে হলে সব
     * সারি নতুন করে ছাপ দিতে হবে, একটা মাইগ্রেশনে, ঘোষণা করে।
     */
    /**
     * টাকার একটাই চেহারা — আর কোথাও `float` নয়।
     *
     * ── কেন `(float)` এখান থেকে সরানো হলো ────────────────────────────
     * প্রথম লেখায় ছিল `sprintf('%.4F', (float) $value)`, আর সেটা
     * [[MoneyIsNeverAFloatTest]] ধরে ফেলে। ছাড়ের তালিকায় লিখে দেওয়া
     * যেত, কিন্তু সেটা ভুল হত: ওই তালিকার তিনটা বৈধ কারণ — তুলনা,
     * ব্রাউজারে পাঠানো, আর টাকা-নয় — তিনটার একটাও এখানে খাটে না।
     *
     * ⚠️ **আর এখানে ক্ষতিটা গার্ডের প্রশ্নের চেয়েও বড়।** `(string)`
     * করা float-এর রূপ `precision` ini-র উপর নির্ভর করে, অর্থাৎ **দুই
     * মেশিনে একই অঙ্কের দুই রকম লেখা** হতে পারে। তখন একই সারির ছাপ
     * দুই রকম হত, আর চেইনটা "ভাঙা" দেখাত যদিও কেউ কিছু বদলায়নি —
     * ঠিক যে আস্থাটা এই চেইনের একমাত্র কাজ, সেটাই নষ্ট হত।
     *
     * তাই string আর int সরাসরি, আর float এলে **নির্ধারিত** চার-দশমিক
     * রূপ — কোনো cast ছাড়া, কারণ মানটা তখন এমনিতেই float।
     */
    private static function money(mixed $value): string
    {
        $number = match (true) {
            is_string($value) => trim($value),
            is_int($value) => (string) $value,
            /*
             * float এখানে আসার কথা নয় — এলে নিয়মটা আরও উপরে ভাঙা
             * হয়েছে। তবু ছাপটা বসাতেই হবে, আর সেটা নির্ধারিতভাবে।
             */
            is_float($value) => sprintf('%.4F', $value),
            default => '',
        };

        /*
         * bcmath কেবল সাধারণ দশমিক লেখা বোঝে। সূচক লেখা বা অন্য
         * কিছু এলে সেটা যেমন আছে তেমনই ছাপে যায় — ভুল অঙ্ক গোনার
         * চেয়ে অচেনা লেখাটা হুবহু ধরে রাখা ভালো।
         */
        return preg_match('/^-?\d+(\.\d+)?$/', $number) === 1
            ? bcadd($number, '0', 4)
            : $number;
    }

    private static function canonical(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (in_array($field, self::MONEY, true)) {
            return self::money($value);
        }

        if (isset(self::MOMENTS[$field])) {
            try {
                return CarbonImmutable::parse($value)->format(self::MOMENTS[$field]);
            } catch (\Throwable) {
                /*
                 * পড়া গেল না — তবু ছাপটা বসাতে হবে, নাহলে একটা বিকৃত
                 * তারিখ চেইনটাকে **নীরবে** থামিয়ে দিত।
                 */
                return (string) $value;
            }
        }

        return (string) $value;
    }

    /**
     * একটা কোম্পানির পুরো চেইন হেঁটে দেখা।
     *
     * ── কেন `id` ধরে, `trx_date` ধরে নয় ─────────────────────────────
     * চেইনটা লেখার ক্রমে বাঁধা, ব্যবসার তারিখের ক্রমে নয়। পিছিয়ে বসানো
     * এন্ট্রি একদম স্বাভাবিক, আর তারিখ ধরে হাঁটলে ওই সারিগুলো ভুল
     * জায়গায় পড়ত আর প্রতিটা চেইন ভাঙা দেখাত।
     *
     * ── আর শেষ থেকে সারি মুছে ফেললে? ────────────────────────────────
     * কেবল সারি ধরে ধরে হাঁটলে ওটা ধরা পড়ত **না**। শেষের তিনটা সারি
     * মুছে ফেললে বাকি চেইনটা নিখুঁতই থাকে — প্রতিটা সারি তার আগেরটার
     * সাথে মেলে, কারণ যে সারিগুলো নেই তারা তো কিছু ভাঙেনি।
     *
     * আর ওটাই সবচেয়ে সহজ কারচুপি: মাস শেষের কয়েকটা দাখিলা তুলে দিলে
     * খরচ কমে যায়, আর চেইন সবুজ থাকে।
     *
     * তাই মাথার সারিটাও মেলানো হয় — **শেষ ছাপ ও গোনা সংখ্যা দুইটাই**।
     * সংখ্যাটা আলাদা করে দেখা হয় কারণ কেউ মাথাটাও একই সাথে বদলে
     * দিলে ছাপ মিলে যেত; দুইটা জায়গা একসাথে ঠিক রাখা অনেক কঠিন।
     *
     * @return array{ok: bool, checked: int, expected: int, broken_at: ?int, reason: ?string}
     */
    public static function verify(int $companyId): array
    {
        $previous = null;
        $checked = 0;
        $hashed = 0;
        $brokenAt = null;

        LedgerEntry::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$previous, &$checked, &$hashed, &$brokenAt): bool {
                foreach ($rows as $row) {
                    $checked++;

                    /*
                     * চেইনের আগের সারি ছাড়া রেখে দেওয়া সারি — পুরনো
                     * তথ্য, মাইগ্রেশনের আগে বসানো। ওগুলো এড়ানো হয় না,
                     * কারণ ব্যাকফিল ওদেরও চেইনে টেনে এনেছে; কিন্তু
                     * `row_hash` না থাকলে যাচাই করার কিছু নেই।
                     */
                    if ($row->row_hash === null) {
                        continue;
                    }

                    $expected = self::hash($previous, $row->getAttributes());

                    if (! hash_equals($expected, (string) $row->row_hash)) {
                        $brokenAt = (int) $row->id;

                        return false;
                    }

                    $previous = (string) $row->row_hash;
                    $hashed++;
                }

                return true;
            });

        if ($brokenAt !== null) {
            return ['ok' => false, 'checked' => $checked, 'expected' => $checked, 'broken_at' => $brokenAt, 'reason' => self::ROW];
        }

        $head = DB::table('ledger_chain_heads')->where('company_id', $companyId)->first();

        /*
         * মাথা নেই মানে এই কোম্পানি কোনোদিন কিছু পোস্ট করেনি — ভাঙা
         * নয়, কেবল খালি।
         */
        if ($head === null) {
            return ['ok' => true, 'checked' => $checked, 'expected' => $checked, 'broken_at' => null, 'reason' => null];
        }

        $tailIntact = ($head->last_hash ?? null) === $previous
            && (int) $head->entries === $hashed;

        return [
            'ok' => $tailIntact,
            'checked' => $hashed,
            'expected' => (int) $head->entries,
            'broken_at' => null,
            'reason' => $tailIntact ? null : self::TAIL,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Models\LedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
     * ⭐ সিল ছাড়া সারি — কেউ অ্যাপের বাইরে দিয়ে ঢুকিয়েছে।
     *
     * ── ⛔ কেন এই তৃতীয় কারণটা লাগল, ২১ সেপ্টেম্বর ২০২৬ ─────────────
     * একটা বিরুদ্ধ-পাঠ এই ফাইলটা ভাঙার চেষ্টা করে **সফল হয়েছিল**।
     * `verify()` খালি `row_hash`-ওয়ালা সারি **এড়িয়ে যেত**, আর শেষে
     * মাথার গোনা সংখ্যাটা মেলাত সে যতটা **হ্যাশ করেছে** তার সাথে —
     * যতগুলো সারি **আছে** তার সাথে নয়।
     *
     * ⚠️ ফল: কাঁচা SQL দিয়ে একটা ভুয়া সারি ঢুকিয়ে `row_hash` ঘরটা
     * খালি রেখে দিলে চেইন জোড়া থাকত, মাথার সংখ্যাও মিলত, আর রোজকার
     * যাচাই বলত **"সব ঠিক আছে"**। ⛔ অর্থাৎ ঠিক যে কারচুপিটা আটকানোর
     * জন্য সিলটা বসানো — ভুয়া দাখিলা — সেটাই ধরা পড়ত না।
     *
     * ⓘ এখন খালি সিল মানেই ব্যর্থতা। যে সারি সিল ছাড়া বসেছে, সে
     * নিজেই প্রমাণ যে কেউ ইঞ্জিনের বাইরে দিয়ে লিখেছে।
     */
    public const UNSEALED = 'unsealed';

    /**
     * ⛔ সারিটা যে চাবিতে সিল করা, সেটা এই সার্ভারে নেই — ২৮ সেপ্টেম্বর ২০২৬।
     *
     * ⭐ এটা *"খাতা ভাঙা"* নয়, আর দুইটাকে আলাদা রাখাই এই
     * সংস্করণ-ব্যবস্থার পুরো কারণ। ⓘ *"চাবি নেই"* মানে **যাচাই করতে
     * পারছি না**; *"ভাঙা"* মানে **কেউ খাতা বদলেছে**।
     *
     * ⚠️ এক করে দেখালে একদিন সত্যিকারের কারচুপিটাও *"আবার চাবির
     * ঝামেলা"* বলে উড়িয়ে দেওয়া হত — আর সেটাই সবচেয়ে দামি ক্ষতি।
     */
    public const NO_KEY = 'no_key';

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

        /*
         * ⭐ সংস্করণটা এখানেই একবার পড়া হয়, আর সারির সাথেই ফেরত যায় —
         * ২৮ সেপ্টেম্বর ২০২৬।
         *
         * ⛔ সারিটা লেখার সময় আর যাচাইয়ের সময় আলাদা করে সংস্করণ বের
         * করলে দুইটা একদিন আলাদা হত — যেমন ঠিক এই লেখার মুহূর্তে কেউ
         * `LEDGER_SEAL_KEY` বসাল। ⚠️ তখন সারিটা লেখা হত এক চাবিতে আর
         * পড়া হত আরেকটায়, আর খাতাটা ভাঙা দেখাত।
         */
        $version = self::sealVersion();
        $hash = self::hash($previous, $entry->getAttributes(), $version);

        $entries = (int) DB::table('ledger_chain_heads')->where('company_id', $companyId)->value('entries') + 1;

        DB::table('ledger_chain_heads')->where('company_id', $companyId)->update([
            'last_hash' => $hash,
            'entries' => $entries,
            ...self::headSealColumns($companyId, $hash, $entries),
        ]);

        return [$previous, $hash, $version];
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
    public static function hash(?string $previous, array $attributes, ?int $version = null): string
    {
        $parts = [$previous ?? ''];

        foreach (self::SIGNED as $field) {
            $parts[] = self::canonical($field, $attributes[$field] ?? null);
        }

        return hash_hmac('sha256', implode('|', $parts), self::keyFor($version ?? self::sealVersion()));
    }

    /**
     * ⭐ `APP_KEY`-তে সিল করা — যা আজ পর্যন্ত খাতায় আছে সব এই সংস্করণের।
     */
    public const SEAL_APP_KEY = 1;

    /** ⭐ খতিয়ানের নিজের চাবিতে সিল করা (`LEDGER_SEAL_KEY`)। */
    public const SEAL_OWN_KEY = 2;

    /**
     * নতুন সারি কোন সংস্করণে সিল হবে।
     *
     * ── ⚠️ চাবি না বসানো থাকলে পুরোনো সংস্করণ, আর সেটা ইচ্ছাকৃত ────────
     * ⛔ "চাবি নেই তো থেমে যাও" করা যায় না — তাতে একটা ডিপ্লয়ে খাতা
     * লেখা বন্ধ হয়ে গোটা ব্যবসা থামত। ⓘ তাই খালি চাবিতে আচরণ হুবহু
     * আজকেরটাই, আর অবস্থাটা [[self::verify()]] খোলাখুলি জানায়।
     */
    public static function sealVersion(): int
    {
        return self::ownKey() === '' ? self::SEAL_APP_KEY : self::SEAL_OWN_KEY;
    }

    /**
     * একটা সংস্করণের চাবি।
     *
     * ⛔ অজানা সংস্করণে ব্যতিক্রম, খালি স্ট্রিং নয়। ⚠️ খালি চাবি দিয়ে
     * `hash_hmac` চুপচাপ একটা ছাপ বানিয়ে দিত, আর সেটা দেখতে **বৈধ
     * ছাপের মতোই** — অর্থাৎ পাহারাটা নীরবে অকেজো হয়ে যেত।
     */
    public static function keyFor(int $version): string
    {
        $key = match ($version) {
            self::SEAL_APP_KEY => (string) config('app.key'),
            self::SEAL_OWN_KEY => self::ownKey(),
            default => throw new RuntimeException("Unknown ledger seal version: {$version}."),
        };

        if ($key === '') {
            throw new RuntimeException("The ledger seal key for version {$version} is not configured.");
        }

        return $key;
    }

    /** ⓘ এক জায়গায়, কারণ "খালি কি না" প্রশ্নটা তিন জায়গায় লাগে। */
    private static function ownKey(): string
    {
        return trim((string) config('abos.ledger_seal.key'));
    }

    /**
     * এই সংস্করণের চাবি হাতে আছে কি না — ব্যতিক্রম ছাড়া জিজ্ঞেস করার পথ।
     *
     * ⭐ [[self::verify()]] এটা দিয়েই *"চাবি নেই"* আর *"খাতা ভাঙা"*
     * আলাদা করে বলে। ⓘ আর ঐ পার্থক্যটাই এই গোটা কাজের কারণ।
     */
    public static function hasKeyFor(int $version): bool
    {
        try {
            self::keyFor($version);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * ⭐ মাথার নিজের সিল — অডিট গ৮, ৪ অক্টোবর ২০২৬।
     *
     * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────
     * প্রতিটা সারি সিল করা, কিন্তু মাথা (শেষ ছাপ আর সারির সংখ্যা) নয়। ডাটাবেজে ঢুকতে পারা কেউ শেষের কয়েকটা
     * সারি মুছে মাথার দুইটা ঘর মিলিয়ে দিলে [[self::verify()]] বলত "অক্ষত" — চেইনের বাকিটা তো সত্যিই ঠিক।
     * মাথাটা মুছে দিলে বলত "এই কোম্পানি কিছু পোস্টই করেনি"।
     * ⭐ এখন মাথার তিনটা কথা (কোম্পানি · শেষ ছাপ · সংখ্যা) সারির মতোই চাবিতে সিল — চাবি ছাড়া মাথা বদলানো যায় না।
     */
    public static function headSeal(int $companyId, ?string $lastHash, int $entries, int $version): string
    {
        return hash_hmac('sha256', 'head|'.$companyId.'|'.($lastHash ?? '').'|'.$entries, self::keyFor($version));
    }

    /**
     * মাথার সিলের ঘর — কলামটা থাকলে। ⓘ ডিপ্লয়ের মাঝের কয়েক সেকেন্ড (নতুন কোড, পুরনো টেবিল) খাতায় লেখা যেন না থামে।
     *
     * @return array<string, mixed>
     */
    public static function headSealColumns(int $companyId, ?string $lastHash, int $entries): array
    {
        // ⓘ কেবল "আছে" মনে রাখা — মাইগ্রেশনের আগের কোনো পোস্টিং "নেই" মনে রাখলে পরের সব মাথা সিল ছাড়া থাকত
        static $hasColumn = false;
        $hasColumn = $hasColumn || \Illuminate\Support\Facades\Schema::hasColumn('ledger_chain_heads', 'head_seal');

        if (! $hasColumn) {
            return [];
        }

        $version = self::sealVersion();

        return ['head_seal' => self::headSeal($companyId, $lastHash, $entries, $version), 'head_seal_version' => $version];
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
     * ── ⛔ কেন `chunk()` নয়, `chunkById()` ──────────────────────────
     * ⓘ `chunk()` পাতা গোনে `OFFSET`/`LIMIT` দিয়ে — অর্থাৎ "৫০০টা সারি
     * বাদ দিয়ে পরের ৫০০টা"। ⚠️ হাঁটার মাঝপথে সেটের একটা সারি সরে গেলে
     * (মুছে ফেলা, বা `company_id` বদলে যাওয়া) পরের পাতার জানালাটা
     * **এক ঘর পিছিয়ে যায়**, আর ঠিক ওই সীমানার সারিটা কেউ কোনোদিন
     * দেখে না।
     *
     * ⛔ এখানে একটা সারি এড়িয়ে যাওয়া মানে তার পরের **প্রতিটা** সারির
     * `previous` ভুল — চেইনটা নীরবে ভুল হয়ে বসে, আর কোথাও কিছু লাল
     * হয় না। ⭐ `chunkById()` জানালা গোনে না, শেষ `id`-র পর থেকে ধরে
     * (`where id > :last`), তাই সেটটা নড়লেও একটা সারিও বাদ পড়ে না।
     *
     * ⓘ দুইটা ক্রম এক: এখানে আগে থেকেই `orderBy('id')`, আর
     * `chunkById()` নিজেও `id` ধরে আরোহী ক্রমেই হাঁটে — কোনো join নেই,
     * তাই চাবির নামও দ্ব্যর্থ নয়। ⓘ পুরনো সারিগুলোকে চেইনে টানা
     * ব্যাকফিলটা — `database/migrations/2026_10_13_100000_the_books_`
     * `could_be_edited_and_nobody_would_know.php`-এর ৭৮ লাইন — প্রথম
     * দিন থেকেই `chunkById()` ধরে হাঁটে; এই ফাইলের দুই জায়গা পিছিয়ে
     * ছিল।
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
            ->chunkById(500, function ($rows) use (&$previous, &$sealed, &$last): void {
                foreach ($rows as $row) {
                    if ($row->row_hash === null) {
                        continue;
                    }

                    /*
                     * ⭐ সারিটার **নিজের** সংস্করণেই আবার সিল — ২৮ সেপ্টেম্বর ২০২৬।
                     *
                     * ⛔ এখানে চলতি সংস্করণ বসানো যেত, আর সেটা দেখতে
                     * "আধুনিক" লাগত। ⚠️ কিন্তু তাতে একটা ঘোষিত
                     * মাইগ্রেশনের সারাই চুপচাপ একটা **চাবি ঘোরানোও** হয়ে
                     * যেত — দুইটা সম্পূর্ণ আলাদা কাজ, একটা ডাকে।
                     *
                     * ⓘ আর চাবি বদলানোর নিজের কোনো দরকার নেই: পুরোনো
                     * সারি পুরোনো চাবিতে যাচাই হতেই থাকে, আর সেটার জন্যই
                     * সংস্করণটা রাখা।
                     *
                     * ⚠️ যে সংস্করণের চাবি সার্ভারে নেই, তাতে
                     * [[self::keyFor()]] ব্যতিক্রম ছোঁড়ে আর কাজটা থামে —
                     * নীরবে একটা ভুল ছাপ বসিয়ে যাওয়ার চেয়ে থামা ভালো।
                     */
                    $hash = self::hash(
                        $previous,
                        $row->getAttributes(),
                        (int) ($row->seal_version ?? self::SEAL_APP_KEY),
                    );

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
        $entries = LedgerEntry::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNotNull('row_hash')
            ->count();

        DB::table('ledger_chain_heads')->updateOrInsert(
            ['company_id' => $companyId],
            ['last_hash' => $last, 'entries' => $entries, ...self::headSealColumns((int) $companyId, $last, $entries)],
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
     * ── ⛔ আর কেন `chunkById()` ──────────────────────────────────────
     * ⚠️ যাচাই চলে **অ্যাপ চালু অবস্থায়** — রোজকার `abos:books-check`,
     * আর প্রতিটা ডিপ্লয়ে। `chunk()`-এর `OFFSET` জানালা সেটের নড়াচড়ায়
     * পিছিয়ে যায়, আর তখন একটা সারি কেউ দেখে না; ⛔ পাহারার কাছে
     * "দেখিনি" আর "ঠিক আছে" এক কথা হয়ে যায়। ⓘ কারণটা পুরোটা
     * [[LedgerChain::reseal()]]-এর মন্তব্যে।
     *
     * @return array{ok: bool, checked: int, expected: int, broken_at: ?int, reason: ?string, seal_version?: int}
     */
    public static function verify(int $companyId): array
    {
        $previous = null;
        $checked = 0;
        $hashed = 0;
        $brokenAt = null;
        $unsealedAt = null;

        /** @var array{0: int, 1: int}|null ⓘ [সারি, সংস্করণ] — চাবিটা হাতে নেই */
        $keylessAt = null;
        $newest = 0; // ⓘ এ পর্যন্ত দেখা সবচেয়ে নতুন সিল-সংস্করণ — অডিট গ৮

        LedgerEntry::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$previous, &$checked, &$hashed, &$brokenAt, &$unsealedAt, &$keylessAt, &$newest): bool {
                foreach ($rows as $row) {
                    $checked++;

                    /*
                     * ⛔ সিল ছাড়া সারি — এটাই থামার কারণ।
                     *
                     * ⚠️ আগে এখানে `continue` ছিল, যুক্তিটা ছিল *"হ্যাশ না
                     * থাকলে যাচাই করার কিছু নেই"*। ⓘ কথাটা আক্ষরিকভাবে
                     * সত্যি, আর ঠিক সেই কারণেই এটা একটা গর্ত ছিল:
                     * সারিটা এড়িয়ে যাওয়া মানে সারিটা **গোনার বাইরে**
                     * চলে যাওয়া, আর তখন নিচের সংখ্যা মেলানোটাও তাকে
                     * দেখতে পেত না।
                     *
                     * ⓘ ইঞ্জিন দিয়ে বসানো প্রতিটা সারির সিল থাকে
                     * ([[LedgerEntry]]-র `creating` হুক), আর ব্যাকফিল
                     * পুরনোগুলোকেও টেনে এনেছে। তাই সিল ছাড়া একটা সারি
                     * থাকার একটাই মানে: কেউ অ্যাপের বাইরে দিয়ে লিখেছে।
                     */
                    if ($row->row_hash === null) {
                        $unsealedAt = (int) $row->id;

                        return false;
                    }

                    /*
                     * ⭐ সারিটা যে সংস্করণে সিল হয়েছিল, সেই চাবিতেই যাচাই —
                     * ২৮ সেপ্টেম্বর ২০২৬, নিরীক্ষা §৪।
                     *
                     * ⛔ চাবিটা হাতে না থাকলে এখানে থামা হয়, আর সেটা
                     * **"ভাঙা" বলে নয়**। ⚠️ ঐ পার্থক্যটাই এই গোটা কাজের
                     * কারণ: চাবি না থাকা মানে *আমরা যাচাই করতে পারছি না*,
                     * আর ভাঙা মানে *কেউ খাতা বদলেছে*। ⓘ দুইটাকে এক
                     * করে দেখালে একদিন সত্যিকারের কারচুপিটাও "আবার চাবির
                     * ঝামেলা" বলে উড়িয়ে দেওয়া হত।
                     */
                    $version = (int) ($row->seal_version ?? self::SEAL_APP_KEY);

                    /*
                     * ⛔ সংস্করণ কখনো পেছায় না — অডিট গ৮। নতুন চাবিতে সিল হওয়া সারির পরে পুরনো চাবির সারি মানে কেউ
                     * পুরনো (হয়তো ফাঁস হওয়া) চাবিতে ফিরে গিয়ে সিল বানিয়েছে।
                     */
                    if ($version < $newest) {
                        $brokenAt = (int) $row->id;

                        return false;
                    }

                    $newest = $version;

                    if (! self::hasKeyFor($version)) {
                        $keylessAt = [(int) $row->id, $version];

                        return false;
                    }

                    $expected = self::hash($previous, $row->getAttributes(), $version);

                    if (! hash_equals($expected, (string) $row->row_hash)) {
                        $brokenAt = (int) $row->id;

                        return false;
                    }

                    $previous = (string) $row->row_hash;
                    $hashed++;
                }

                return true;
            });

        /*
         * ⓘ সিল ছাড়া সারিটা আগে জানানো হয়, ভাঙা চেইনের আগে — কারণ
         * দুইটা আলাদা ঘটনা আর মানুষ দুই জায়গায় খুঁজবেন। ⚠️ একটায়
         * বিদ্যমান সারি বদলেছে, অন্যটায় **নতুন সারি ঢুকেছে**।
         */
        if ($unsealedAt !== null) {
            return [
                'ok' => false,
                'checked' => $checked,
                'expected' => $checked,
                'broken_at' => $unsealedAt,
                'reason' => self::UNSEALED,
            ];
        }

        /*
         * ⭐ চাবি না থাকার কথাটা ভাঙা খাতার **আগে** — দুইটাই হলে
         * প্রথমটাই সত্যিকারের কারণ, আর দ্বিতীয়টা কেবল তার লক্ষণ।
         */
        if ($keylessAt !== null) {
            [$id, $version] = $keylessAt;

            return [
                'ok' => false,
                'checked' => $checked,
                'expected' => $checked,
                'broken_at' => $id,
                'reason' => self::NO_KEY,
                'seal_version' => $version,
            ];
        }

        if ($brokenAt !== null) {
            return ['ok' => false, 'checked' => $checked, 'expected' => $checked, 'broken_at' => $brokenAt, 'reason' => self::ROW];
        }

        $head = DB::table('ledger_chain_heads')->where('company_id', $companyId)->first();

        /*
         * মাথা নেই মানে এই কোম্পানি কোনোদিন কিছু পোস্ট করেনি — ভাঙা
         * নয়, কেবল খালি।
         */
        if ($head === null) {
            /* ⛔ সারি আছে অথচ মাথা নেই — মাথাটা মুছে ফেলা হয়েছে (অডিট গ৮) */
            return $hashed > 0
                ? ['ok' => false, 'checked' => $hashed, 'expected' => 0, 'broken_at' => null, 'reason' => self::TAIL]
                : ['ok' => true, 'checked' => $checked, 'expected' => $checked, 'broken_at' => null, 'reason' => null];
        }

        $tailIntact = ($head->last_hash ?? null) === $previous
            && (int) $head->entries === $hashed
            && self::headHolds($companyId, $head);

        return [
            'ok' => $tailIntact,
            'checked' => $hashed,
            'expected' => (int) $head->entries,
            'broken_at' => null,
            'reason' => $tailIntact ? null : self::TAIL,
        ];
    }

    /**
     * মাথার সিল মেলে কি না — অডিট গ৮। ⓘ কলামটা না থাকলে (পুরনো টেবিল) আগের আচরণ; থাকলে সিল খালি মানেও ভাঙা,
     * কারণ মাইগ্রেশন প্রতিটা মাথা সিল করে দেয়।
     */
    private static function headHolds(int $companyId, object $head): bool
    {
        if (! property_exists($head, 'head_seal')) {
            return true;
        }

        $version = (int) ($head->head_seal_version ?? self::SEAL_APP_KEY);

        if ($head->head_seal === null || ! self::hasKeyFor($version)) {
            return false;
        }

        return hash_equals(self::headSeal($companyId, $head->last_hash, (int) $head->entries, $version), (string) $head->head_seal);
    }
}

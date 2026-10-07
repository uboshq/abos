<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Security\LedgerChain;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * খতিয়ানের সিল একই চাবি ব্যবহার করত যেটা কুকি আর সেশন খোলে।
 *
 * ── ⛔ যা ঘটত, নিরীক্ষা §৪ ────────────────────────────────────────────
 * [[LedgerChain::hash()]] সিল বানাত `config('app.key')` দিয়ে — অর্থাৎ
 * `APP_KEY`, যেটা সেশন, কুকি আর এনক্রিপ্ট করা ঘরও খোলে।
 *
 * ⚠️ `APP_KEY` ঘোরানো একটা **স্বাভাবিক** নিরাপত্তা-কাজ: ফাঁস হলে, কেউ
 * চলে গেলে, বা নিয়ম মেনে। ⛔ ঘোরানোর পরদিন খাতার প্রতিটা সিল ভুল হত,
 * আর যাচাই বলত পুরো খাতা ভাঙা।
 *
 * ── ⭐ আসল ক্ষতিটা মিথ্যা লাল নয় ──────────────────────────────────────
 * ক্ষতিটা হলো তখন *"কেউ খাতা বদলেছে"* আর *"আমরা চাবি ঘুরিয়েছি"* — এই
 * দুইটা **একই চেহারায়** আসত। ⓘ একটা টেম্পার-প্রমাণ শিকলের পুরো মূল্যই
 * ঐ পার্থক্যটুকু। ⚠️ আর একবার দুইটা এক হয়ে গেলে, একদিন সত্যিকারের
 * কারচুপিটাও *"আবার চাবির ঝামেলা"* বলে উড়িয়ে দেওয়া হত।
 *
 * ⭐ তাই এই ফাইলের প্রধান দাবি *"নতুন চাবি কাজ করে"* নয় —
 * **"চাবি নেই" আর "ভাঙা" আলাদা করে বলা হয়**।
 *
 * ── ⓘ সংস্করণ কেন লাগে ────────────────────────────────────────────────
 * প্রতিটা সারি মনে রাখে কোন সংস্করণে তাকে সিল করা হয়েছিল
 * (`ledger_entries.seal_version`)। ⓘ তাই পুরোনো সারি পুরোনো চাবিতেই
 * যাচাই হয়, আর চাবি বসানো কোনো *"সব নতুন করে সিল দাও"* কাজ নয়।
 *
 * ── ⓘ এখানে যা মাপা হয় না ────────────────────────────────────────────
 * ⛔ শিকল হাঁটার নিজের শুদ্ধতা — সেটা
 * [[OurOwnMigrationBrokeTheSealTest]]-এর কাজ।
 */
final class TheLedgerSealSharedTheKeyThatOpensTheCookiesTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * ⚠️ চাবিটা প্রতিটা পরীক্ষার শুরুতে **খালি** করা হয়, আর সেটা
         * ইচ্ছাকৃত: যে সারিগুলো সিডার বসায় সেগুলো তখন সংস্করণ ১-এ সিল
         * হয় — অর্থাৎ ঠিক আজকের লাইভ খাতার মতো।
         */
        config(['abos.ledger_seal.key' => '']);

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->companyId = (int) $company->id;
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    // ── ⭐ আসল দাবি: দুইটা ঘটনা দুইভাবে জানানো হয় ─────────────────────

    public function test_a_missing_key_is_not_reported_as_a_tampered_book(): void
    {
        $this->assertTrue(LedgerChain::verify($this->companyId)['ok'],
            'শুরুতেই খাতা মেলেনি — তাহলে নিচের দাবিটা অন্য কিছু মাপছে।');

        /*
         * ⓘ একটা সারিকে সংস্করণ ২-তে সিল করা হয়, তারপর চাবিটা সরিয়ে
         * নেওয়া হয় — ঠিক যা ঘটে যখন কেউ `LEDGER_SEAL_KEY` ছাড়া একটা
         * সার্ভারে খাতা পুনরুদ্ধার করেন।
         */
        $this->rowSealedWithOwnKey();
        config(['abos.ledger_seal.key' => '']);

        $result = LedgerChain::verify($this->companyId);

        $this->assertFalse($result['ok']);

        $this->assertSame(LedgerChain::NO_KEY, $result['reason'], implode(PHP_EOL, [
            'চাবি না থাকার কথাটা "খাতা ভাঙা" বলে জানানো হয়েছে।',
            '',
            '⛔ এটাই সেই ভুল যা টেম্পার-প্রমাণ শিকলটার পুরো মূল্য কেড়ে নেয়:',
            'কারচুপি আর চাবি-বদল এক চেহারায় এলে, একদিন সত্যিকারের',
            'কারচুপিটাও উড়িয়ে দেওয়া হবে।',
        ]));

        $this->assertSame(LedgerChain::SEAL_OWN_KEY, $result['seal_version'],
            'কোন সংস্করণের চাবি নেই, সেটা বলা হয়নি — মানুষ কী বসাবেন জানবেন কীভাবে?');
    }

    public function test_a_real_change_to_a_row_is_still_reported_as_tampering(): void
    {
        /*
         * ⚠️ পাল্টা-দাবি, আর এটাই উপরের দাবিটাকে অর্থ দেয়। ⛔ এটা ছাড়া
         * সারাইটা "সব লালকেই চাবির সমস্যা বলো" হয়ে যেতে পারত, আর তখন
         * পাহারাটা চিরতরে চুপ হয়ে যেত।
         */
        $row = LedgerEntry::withoutGlobalScopes()
            ->where('company_id', $this->companyId)->orderBy('id')->firstOrFail();

        DB::table('ledger_entries')->where('id', $row->id)
            ->update(['debit' => bcadd((string) $row->debit, '1', 4)]);

        $result = LedgerChain::verify($this->companyId);

        $this->assertFalse($result['ok']);
        $this->assertSame(LedgerChain::ROW, $result['reason'],
            'সারি বদলানোর কথাটা আর "ভাঙা" বলে জানানো হচ্ছে না।');
        $this->assertSame((int) $row->id, $result['broken_at']);
    }

    // ── ⭐ চাবিটা সত্যিই আলাদা ─────────────────────────────────────────

    public function test_the_seal_no_longer_uses_the_key_that_opens_the_cookies(): void
    {
        config(['abos.ledger_seal.key' => 'a-ledger-key-of-its-own']);

        $attributes = ['company_id' => $this->companyId, 'debit' => '100.0000', 'credit' => '0.0000'];

        $withOwnKey = LedgerChain::hash(null, $attributes, LedgerChain::SEAL_OWN_KEY);
        $withAppKey = LedgerChain::hash(null, $attributes, LedgerChain::SEAL_APP_KEY);

        $this->assertNotSame($withAppKey, $withOwnKey,
            'দুইটা সংস্করণ একই ছাপ দিচ্ছে — তাহলে চাবিটা আলাদা হয়নি।');

        /*
         * ⭐ আর এটাই সেই আচরণ যেটার জন্য গোটা কাজ: `APP_KEY` ঘোরালে
         * সংস্করণ ২-এর ছাপ **বদলায় না**।
         */
        $before = LedgerChain::hash(null, $attributes, LedgerChain::SEAL_OWN_KEY);
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->assertSame($before, LedgerChain::hash(null, $attributes, LedgerChain::SEAL_OWN_KEY),
            'APP_KEY ঘোরানোয় খতিয়ানের ছাপ বদলে গেছে — চাবিটা এখনো জোড়া লাগানো।');
    }

    public function test_rotating_the_app_key_still_breaks_the_rows_that_were_sealed_with_it(): void
    {
        /*
         * ⓘ সৎ থাকার দাবি: এই কাজটা **অতীতকে সারায় না**। আজ খাতায় যা
         * আছে সব সংস্করণ ১, তাই `APP_KEY` ঘোরালে ওগুলো এখনো ভাঙা
         * দেখাবে। ⭐ সারাইটা কেবল এর পরের সারিগুলোকে ঐ বিপদ থেকে
         * বের করে আনে।
         *
         * ⚠️ দাবিটা লেখা হলো যাতে কেউ পরে ভেবে না বসেন সমস্যাটা পুরোপুরি
         * চলে গেছে — পুরোনো সারিগুলোর জন্য এখনো একটা reseal মাইগ্রেশন
         * লাগবে, আর সেটা আলাদা, ঘোষিত কাজ।
         */
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $result = LedgerChain::verify($this->companyId);

        $this->assertFalse($result['ok']);
        $this->assertSame(LedgerChain::ROW, $result['reason'],
            'পুরোনো সংস্করণের সারিগুলো APP_KEY ঘোরানোর পরেও মিলে যাচ্ছে — '
            .'তাহলে ছাপটা কি আদৌ চাবি দেখছে?');
    }

    // ── ⓘ সংস্করণের হিসাব ────────────────────────────────────────────

    public function test_with_no_key_configured_nothing_changes(): void
    {
        /*
         * ⛔ "চাবি নেই তো থেমে যাও" করা যায় না — তাতে একটা ডিপ্লয়ে খাতা
         * লেখা বন্ধ হয়ে গোটা ব্যবসা থামত। ⓘ তাই খালি চাবিতে আচরণ হুবহু
         * আজকেরটাই।
         */
        config(['abos.ledger_seal.key' => '']);

        $this->assertSame(LedgerChain::SEAL_APP_KEY, LedgerChain::sealVersion());
        $this->assertTrue(LedgerChain::verify($this->companyId)['ok']);
    }

    public function test_a_row_written_after_the_key_is_set_carries_the_new_version(): void
    {
        config(['abos.ledger_seal.key' => 'a-ledger-key-of-its-own']);

        $this->assertSame(LedgerChain::SEAL_OWN_KEY, LedgerChain::sealVersion());

        $row = $this->rowSealedWithOwnKey();

        $this->assertSame(LedgerChain::SEAL_OWN_KEY, (int) $row->seal_version,
            'চাবি বসানোর পরেও সারিটা পুরোনো সংস্করণে সিল হয়েছে।');

        // ⭐ আর দুই সংস্করণ পাশাপাশি থেকেও খাতা মেলে
        $this->assertTrue(LedgerChain::verify($this->companyId)['ok'],
            'এক খাতায় দুই সংস্করণ থাকলে যাচাই ভেঙে পড়ছে।');
    }

    public function test_an_unknown_version_refuses(): void
    {
        /*
         * ⓘ অজানা সংস্করণ মানে হয় ডাটাবেসে আবর্জনা, নয় একটা ভবিষ্যতের
         * চাবি যা এই সার্ভার চেনে না — দুইটাতেই থামাই সৎ উত্তর।
         */
        $this->expectException(RuntimeException::class);

        LedgerChain::keyFor(99);
    }

    public function test_a_known_version_with_no_key_refuses_too(): void
    {
        /*
         * ⛔ এটা আলাদা দাবি, আর সেটা মেপে শেখা: উপরের দাবিটা `match`-এর
         * *"অজানা সংস্করণ"* ডালে পড়ে, আর খালি-চাবির পাহারাটা ছুঁয়েও
         * দেখে না। ⚠️ ঐ পাহারাটা তুলে দিয়েও উপরের দাবিটা সবুজ ছিল —
         * একটা মিউটেন্ট **বেঁচে গিয়ে** সেটা ধরিয়ে দিয়েছে।
         *
         * ⓘ নামটাও দুইটা কথা বলছিল (*"unknown version"* আর *"empty
         * key"*), অথচ মাপত একটাই।
         *
         * ⛔ কেন খালি চাবিতে থামা জরুরি: `hash_hmac` খালি চাবি নিয়েও
         * চুপচাপ একটা ছাপ বানায়, আর সেটা দেখতে **বৈধ ছাপের মতোই**।
         * ⚠️ তখন গোটা পাহারাটা নীরবে অকেজো হয়ে যেত, আর যাচাই সবুজ
         * বলত — যা কোনো পাহারা না থাকার চেয়েও খারাপ।
         */
        config(['abos.ledger_seal.key' => '']);

        $this->expectException(RuntimeException::class);

        LedgerChain::keyFor(LedgerChain::SEAL_OWN_KEY);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** ⓘ চলতি সংস্করণে সিল হওয়া একটা সত্যিকারের সারি, ইঞ্জিনের হাত দিয়েই। */
    private function rowSealedWithOwnKey(): LedgerEntry
    {
        config(['abos.ledger_seal.key' => 'a-ledger-key-of-its-own']);

        $seed = LedgerEntry::withoutGlobalScopes()
            ->where('company_id', $this->companyId)->orderBy('id')->firstOrFail();

        $row = new LedgerEntry;
        $row->forceFill(collect($seed->getAttributes())
            ->except(['id', 'public_id', 'prev_hash', 'row_hash', 'seal_version', 'created_at', 'updated_at'])
            ->all());
        $row->save();

        return $row->refresh();
    }
}

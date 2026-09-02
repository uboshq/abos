<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Security\LedgerChain;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * খাতা বদলে দেওয়া যেত, আর কেউ জানত না।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * খতিয়ান শুধু-যোগের — কিন্তু ওটা **অ্যাপের নিয়ম, ডাটাবেজের নয়**। একটা
 * `UPDATE`, একটা সম্পাদনা করা ব্যাকআপ, বা DBA-র এক মুহূর্ত: যেকোনোটাই
 * একটা অঙ্ক বদলে দিতে পারত, আর কোথাও কোনো চিহ্ন থাকত না।
 *
 * ── এখানে যা প্রমাণ করতে হয় ──────────────────────────────────────────
 * "চেইন আছে" কথাটা যথেষ্ট নয়। তিনটা আলাদা দাবি আছে, আর তিনটাই আলাদা
 * করে ভাঙা সম্ভব:
 *
 * ১. অক্ষত খাতা **সবুজ** বলে — আর সত্যিই কিছু গুনে বলে
 * ২. বদলে দেওয়া খাতা **লাল** বলে, আর কোথায় বদলেছে তা বলে
 * ৩. বৈধ সম্পাদনায় (বানান) **লাল বলে না** — নাহলে কেউ পাহারাটা বন্ধ করত
 *
 * ── আর একটা শিক্ষা, আজকেরই ───────────────────────────────────────────
 * প্রথম রানে কমান্ডটা বলেছিল *"FMART — মিলেছে"*, আর ওটা ছিল **শূন্য
 * সারির উপরে** — ওই কোম্পানির খতিয়ানে কিছুই ছিল না। সবুজ, কিন্তু অন্ধ।
 * তাই এখানে প্রতিটা সবুজ দাবির সাথে **কতগুলো সারি গোনা হলো** সেটাও
 * মিলিয়ে দেখা হয়।
 */
class TheBooksCouldBeEditedAndNobodyWouldKnowTest extends TestCase
{
    use RefreshDatabase;

    private Company $depot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->depot = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->depot->id, $this->depot->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    /**
     * তিনটা লাইনের একটা দাখিলা — এই ফাইলের প্রতিটা পরীক্ষার কাঁচামাল।
     *
     * @return list<int> বসানো সারিগুলোর আইডি, লেখার ক্রমে
     */
    private function postThreeLines(string $date = '2026-08-04'): array
    {
        $before = LedgerEntry::withoutGlobalScopes()->max('id') ?? 0;

        app(PostingEngine::class)->post('journal_voucher', mt_rand(1000, 9999), $date, [
            ['account_id' => 1101, 'debit' => 11500, 'narration' => 'Openning balance'],
            ['account_id' => 4001, 'credit' => 10000],
            ['account_id' => 2201, 'credit' => 1500],
        ]);

        return LedgerEntry::withoutGlobalScopes()
            ->where('id', '>', $before)
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    // ── ১ · অক্ষত খাতা ───────────────────────────────────────────────

    /**
     * প্রতিটা বসানো সারি নিজের ছাপ আর আগেরটার ছাপ ধরে রাখে।
     *
     * শেকলটা সত্যিই শেকল কি না সেটাই প্রশ্ন: প্রতিটা সারির `prev_hash`
     * তার ঠিক আগের সারির `row_hash`-এর সমান হতে হবে। না হলে ওগুলো
     * শেকল নয়, কেবল পাশাপাশি রাখা কতগুলো ছাপ।
     */
    public function test_each_posted_line_holds_the_one_before_it(): void
    {
        $ids = $this->postThreeLines();

        $this->assertCount(3, $ids);

        $rows = LedgerEntry::withoutGlobalScopes()->whereIn('id', $ids)->orderBy('id')->get();

        $previous = null;

        foreach ($rows as $row) {
            $this->assertNotNull($row->row_hash, 'সারি #'.$row->id.'-এর কোনো ছাপ নেই।');

            if ($previous !== null) {
                $this->assertSame($previous, $row->prev_hash, 'শেকলের কড়া খুলে আছে।');
            }

            $previous = $row->row_hash;
        }
    }

    /**
     * অক্ষত খাতা সবুজ বলে — আর সত্যিই কিছু গুনে বলে।
     *
     * ── কেন `checked` মেলানো হয় ─────────────────────────────────────
     * শুধু `ok` দেখলে এই পরীক্ষাটা **খালি খতিয়ানেও সবুজ** থাকত, আর
     * তখন পাহারাটা কিছুই দেখছে না অথচ সবুজ — ঠিক যেভাবে আজ সকালে
     * `FMART` "মিলেছে" বলেছিল শূন্য সারির উপর দাঁড়িয়ে।
     */
    public function test_an_untouched_chain_verifies_and_actually_counted_something(): void
    {
        $this->postThreeLines();

        $result = LedgerChain::verify($this->depot->id);

        $this->assertTrue($result['ok'], 'কেউ কিছু বদলায়নি, তবু চেইন ভাঙা বলছে।');
        $this->assertGreaterThanOrEqual(3, $result['checked'], 'চেইন সবুজ, কিন্তু কিছুই গোনেনি।');
        $this->assertNull($result['reason']);
    }

    /**
     * ডাটাবেজ থেকে পড়া সারির ছাপ আর লেখার সময়ের ছাপ এক।
     *
     * ── কেন এটার নিজের একটা পরীক্ষা দরকার ───────────────────────────
     * ঠিক এই জায়গাতেই প্রথম চেষ্টাটা ভেঙেছিল, আর ভাঙাটা ছিল নীরব:
     * মডেলে অঙ্কটা `408000`, ডাটাবেজে `408000.0000` — একই টাকা, দুই
     * চেহারা, দুই ছাপ। তাতে **প্রতিটা** সারি "বদলে গেছে" দেখাত।
     *
     * আর ওটাই সবচেয়ে বিপজ্জনক ব্যর্থতা: চেইনটা এত কোলাহল করত যে
     * একদিন কেউ সত্যিকারের ভাঙাটাকেও কোলাহলের অংশ ধরে নিত।
     */
    public function test_the_stored_form_and_the_written_form_agree(): void
    {
        $ids = $this->postThreeLines();

        $raw = DB::table('ledger_entries')->whereIn('id', $ids)->orderBy('id')->get();

        $previous = DB::table('ledger_entries')->where('id', $ids[0])->value('prev_hash');

        foreach ($raw as $row) {
            $expected = LedgerChain::hash($previous, (array) $row);

            $this->assertSame(
                $expected,
                $row->row_hash,
                'কাঁচা সারি থেকে গোনা ছাপ আর লেখা ছাপ মেলে না — সারি #'.$row->id,
            );

            $previous = $row->row_hash;
        }
    }

    // ── ২ · বদলে দেওয়া খাতা ──────────────────────────────────────────

    /**
     * অঙ্ক বদলালে চেইন ভাঙে, আর কোথায় ভেঙেছে তা বলে।
     */
    public function test_changing_an_amount_behind_the_app_breaks_the_chain(): void
    {
        $ids = $this->postThreeLines();

        DB::table('ledger_entries')->where('id', $ids[1])->update(['credit' => 99999]);

        $result = LedgerChain::verify($this->depot->id);

        $this->assertFalse($result['ok'], 'একটা অঙ্ক বদলে গেছে, তবু চেইন সবুজ।');
        $this->assertSame($ids[1], $result['broken_at']);
        $this->assertSame(LedgerChain::ROW, $result['reason']);
    }

    /**
     * তারিখ পিছিয়ে দিলেও।
     *
     * অঙ্ক না বদলে তারিখ বদলানো একটা পুরনো কৌশল — খরচটা বন্ধ মাসের
     * বাইরে সরিয়ে দেওয়া। `trx_date` চেইনে আছে ঠিক এই কারণেই।
     */
    public function test_moving_a_row_to_another_date_breaks_the_chain(): void
    {
        $ids = $this->postThreeLines();

        DB::table('ledger_entries')->where('id', $ids[0])->update(['trx_date' => '2026-07-01']);

        $this->assertFalse(LedgerChain::verify($this->depot->id)['ok']);
    }

    /**
     * শেষ থেকে সারি সরিয়ে ফেললেও — যদিও বাকিটা নিখুঁত থাকে।
     *
     * ── কেন এটা আলাদা করে ধরতে হয় ───────────────────────────────────
     * শেষের সারিগুলো তুলে ফেললে বাকি চেইনটা **সম্পূর্ণ মেলে**: যে
     * সারিগুলো নেই তারা তো কিছু ভাঙেনি। কেবল সারি ধরে হাঁটলে এটা
     * চিরকাল সবুজ থাকত, আর ওটাই সবচেয়ে সহজ কারচুপি — মাস শেষের
     * কয়েকটা দাখিলা তুলে দিলে খরচ কমে যায়।
     */
    public function test_removing_the_newest_rows_is_caught_by_the_head(): void
    {
        $ids = $this->postThreeLines();

        DB::table('ledger_entries')->where('id', end($ids))->delete();

        $result = LedgerChain::verify($this->depot->id);

        $this->assertFalse($result['ok'], 'শেষের সারিটা নেই, তবু চেইন সবুজ।');
        $this->assertSame(LedgerChain::TAIL, $result['reason']);
        $this->assertSame($result['expected'] - 1, $result['checked']);
    }

    // ── ৩ · যা ভাঙার কথা নয় ──────────────────────────────────────────

    /**
     * বিবরণের বানান ঠিক করলে চেইন ভাঙে না।
     *
     * ── কেন এই পরীক্ষাটা বাদ দিলে পাহারাটাই মরত ──────────────────────
     * `narration` মানুষের লেখা, আর ভুল বানান ঠিক করা রোজকার কাজ।
     * ওটা চেইনে থাকলে প্রতিটা সংশোধনে খাতা "ভাঙা" দেখাত, আর তিন
     * সপ্তাহের মধ্যে কেউ একজন পাহারাটা বন্ধ করে দিত — একেবারে যুক্তি
     * দিয়েই।
     */
    public function test_fixing_a_spelling_does_not_break_the_chain(): void
    {
        $ids = $this->postThreeLines();

        DB::table('ledger_entries')->where('id', $ids[0])->update(['narration' => 'Opening balance']);

        $result = LedgerChain::verify($this->depot->id);

        $this->assertTrue($result['ok'], 'কেবল বানান বদলেছে, তবু চেইন ভাঙা বলছে।');
    }

    /**
     * এক কোম্পানির ভাঙা অন্য কোম্পানির খাতায় দেখা যায় না।
     *
     * বহু-টেন্যান্ট পণ্যে এটা সুবিধা নয়, শর্ত: একজনের ঘটনা অন্যজনের
     * রিপোর্টে ঢুকতে পারে না।
     */
    public function test_one_companys_break_does_not_touch_another(): void
    {
        $ids = $this->postThreeLines();

        $mart = Company::query()->where('code', 'FMART')->firstOrFail();

        CompanyContext::set($mart->id, $mart->defaultBranch()?->id);
        $martIds = $this->postThreeLines();
        CompanyContext::set($this->depot->id, $this->depot->defaultBranch()?->id);

        DB::table('ledger_entries')->where('id', $ids[1])->update(['debit' => 1]);

        $this->assertFalse(LedgerChain::verify($this->depot->id)['ok']);

        $other = LedgerChain::verify($mart->id);

        $this->assertTrue($other['ok'], 'এক কোম্পানির কারচুপি অন্যের খাতাকে ভাঙা দেখাচ্ছে।');
        $this->assertCount(3, $martIds);
        $this->assertGreaterThanOrEqual(3, $other['checked'], 'অন্য কোম্পানিটা সবুজ, কিন্তু শূন্যের উপর।');
    }

    // ── ৪ · রোজকার পাহারা ────────────────────────────────────────────

    /**
     * `abos:books-check` নিজেই ভাঙাটা ধরে ও নাম বলে।
     *
     * ── কেন কমান্ডটাও পরীক্ষা করা হয় ────────────────────────────────
     * `LedgerChain::verify()` সবুজ-লাল বলতে পারে, কিন্তু **কেউ সেটা
     * না ডাকলে কিছুই হয় না**। কমান্ডটা রোজ চলে আর প্রতিটা ডিপ্লয়ে
     * চলে, তাই চেইনটা সত্যিই ওখানে ডাকা হয়েছে কি না — সেটাই এখানকার
     * দাবি।
     */
    public function test_the_daily_check_reports_the_break_by_name(): void
    {
        $ids = $this->postThreeLines();

        /*
         * দাবিটা চেইনের বার্তা নিয়ে, কমান্ডের প্রস্থান-সংখ্যা নিয়ে নয়।
         *
         * ── কেন ─────────────────────────────────────────────────────
         * `abos:books-check` চারটা মডিউলের সব যাচাই একসাথে চালায়।
         * প্রস্থান-সংখ্যা ধরে মিলালে এই পরীক্ষাটা **ডেমো ডেটার প্রতিটা
         * যাচাই সবুজ** — এমন একটা দাবি করে বসত, যা এই ফাইলের বিষয়ই
         * নয়। তখন অন্য কারও অন্য একটা কাজ এখানে লাল দেখাত, আর
         * পরেরজন চেইনে ভুল খুঁজে সময় নষ্ট করতেন।
         */
        Artisan::call('abos:books-check');

        $this->assertStringNotContainsString('চেইন ভেঙেছে', Artisan::output(),
            'কেউ কিছু বদলায়নি, তবু রোজকার যাচাই চেইন ভাঙা বলছে।');

        DB::table('ledger_entries')->where('id', $ids[2])->update(['credit' => 7]);

        $this->assertNotSame(0, Artisan::call('abos:books-check'), 'খাতা বদলে গেছে, তবু রোজকার যাচাই সবুজ।');

        $said = Artisan::output();

        $this->assertStringContainsString('চেইন ভেঙেছে', $said);
        $this->assertStringContainsString('TDEPOT', $said);
    }
}

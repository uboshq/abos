<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Security\LedgerChain;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Account;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * আমাদের নিজের মাইগ্রেশন সিল ভাঙলে সিলটা আবার বসে — আর শুধু তখনই।
 *
 * ── কেন এই দুইটা পরীক্ষা জোড়ায় থাকে ─────────────────────────────────
 * `reseal()` একটা বিপজ্জনক যন্ত্র: সে চেইন **সবুজ করে দিতে পারে**।
 * তাই একটাই পরীক্ষা ("সিল বসে") যথেষ্ট নয় — ওটা লিখে যন্ত্রটা সবকিছু
 * ঢেকে দিলেও সবুজ থাকত।
 *
 * ⭐ তাই দ্বিতীয় পরীক্ষাটা উল্টো দিক থেকে মাপে: **সিল বসানোর পরেও যেন
 * `verify()` অন্ধ না হয়** — অর্থাৎ যন্ত্রটা পাহারাটা নষ্ট করেনি।
 */
class OurOwnMigrationBrokeTheSealTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    /**
     * খাত সরালে চেইন ভাঙে — আর সিল বসালে আবার মেলে।
     *
     * ⓘ এটাই হুবহু সেই ঘটনা: `one_payable_head_held_three_different_debts`
     * `account_id` UPDATE করেছিল, আর `account_id` সিলমোহরের অংশ।
     */
    public function test_moving_an_account_breaks_the_seal_and_resealing_mends_it(): void
    {
        $id = $this->company->id;

        $this->assertTrue(LedgerChain::verify($id)['ok'], 'শুরুতেই খাতা মেলেনি — সিডার কি বদলেছে?');

        $entry = LedgerEntry::withoutGlobalScopes()
            ->where('company_id', $id)
            ->orderBy('id')
            ->firstOrFail();

        $elsewhere = Account::query()
            ->where('id', '!=', $entry->account_id)
            ->value('id');

        /*
         * ⚠️ ইচ্ছে করে **মডেল এড়িয়ে** — মাইগ্রেশন ঠিক এভাবেই লিখেছিল,
         * আর মডেল দিয়ে করলে ছাপটা নিজে থেকেই বসে যেত।
         */
        DB::table('ledger_entries')->where('id', $entry->id)->update(['account_id' => $elsewhere]);

        $broken = LedgerChain::verify($id);

        $this->assertFalse($broken['ok'], 'খাত সরিয়েও চেইন সবুজ — তাহলে সিলটা account_id দেখেই না।');
        $this->assertSame((int) $entry->id, $broken['broken_at']);

        $sealed = LedgerChain::reseal($id);

        $this->assertGreaterThan(0, $sealed, 'একটা সারিতেও নতুন সিল বসেনি।');
        $this->assertTrue(LedgerChain::verify($id)['ok'], 'সিল বসানোর পরেও খাতা মেলেনি।');
    }

    /**
     * ⛔ সিল বসানো পাহারাটা নষ্ট করে না।
     *
     * ⚠️ এটাই আসল ঝুঁকি: `reseal()` লেখার পর কেউ ধরে নিতে পারতেন
     * "চেইন এখন যেকোনো সময় সবুজ করা যায়"। তাই সিল বসানোর **পরে**
     * একটা সত্যিকারের কারচুপি করে দেখা হয় — সে যেন এখনো ধরা পড়ে।
     */
    public function test_the_guard_still_catches_a_real_change_after_resealing(): void
    {
        $id = $this->company->id;

        LedgerChain::reseal($id);
        $this->assertTrue(LedgerChain::verify($id)['ok']);

        $entry = LedgerEntry::withoutGlobalScopes()
            ->where('company_id', $id)
            ->where('debit', '>', 0)
            ->orderBy('id')
            ->firstOrFail();

        DB::table('ledger_entries')->where('id', $entry->id)->update(['debit' => 1]);

        $this->assertFalse(
            LedgerChain::verify($id)['ok'],
            'সিল বসানোর পর অঙ্ক বদলেও ধরা পড়ল না — পাহারাটা অলংকার হয়ে গেছে।',
        );
    }

    /**
     * শেষ থেকে সারি মুছে ফেলাও ধরা পড়ে — সিল বসানোর পরেও।
     *
     * ⓘ এটা সবচেয়ে সহজ কারচুপি (মাস শেষের কয়েকটা দাখিলা তুলে দিলে খরচ
     * কমে যায়), আর সারি ধরে হাঁটলে ধরা পড়ে না — মাথার সংখ্যাটাই ধরে।
     * ⚠️ `reseal()` মাথাটাও নতুন করে বসায়, তাই এটা মাপা জরুরি: সে যেন
     * ভুল করে গোনাটাও "ঠিক" করে না দেয়।
     */
    public function test_deleting_the_last_rows_is_still_caught(): void
    {
        $id = $this->company->id;

        LedgerChain::reseal($id);
        $this->assertTrue(LedgerChain::verify($id)['ok']);

        $last = LedgerEntry::withoutGlobalScopes()
            ->where('company_id', $id)
            ->orderByDesc('id')
            ->firstOrFail();

        DB::table('ledger_entries')->where('id', $last->id)->delete();

        $this->assertFalse(
            LedgerChain::verify($id)['ok'],
            'শেষের সারি মুছেও চেইন সবুজ — মাথার গোনাটা কি আর মেলানো হচ্ছে না?',
        );
    }

    // ── এক পাতার চেয়ে লম্বা চেইন ─────────────────────────────────────

    /** পাতার মাপ — [[LedgerChain::reseal()]] ও [[LedgerChain::verify()]]-এ যা লেখা আছে। */
    private const PAGE = 500;

    /**
     * ⭐ সিল বসানো এক পাতার পরেও **একটা সারিও বাদ দেয় না**।
     *
     * ── ⛔ কেন এই দাবিটা লাগল, ২৭ সেপ্টেম্বর ২০২৬ ───────────────────
     * দুইটা হাঁটাই `->chunk(500, …)` দিয়ে লেখা ছিল, আর `chunk()` পাতা
     * গোনে `OFFSET` দিয়ে — "৫০০টা বাদ দিয়ে পরের ৫০০টা"। ⚠️ হাঁটার
     * মাঝপথে সেটের একটা সারি সরে গেলে পরের পাতার জানালা **এক ঘর
     * পিছিয়ে যায়**, আর ঠিক সীমানার সারিটা কেউ দেখে না।
     *
     * ⛔ চেইনে একটা সারি এড়িয়ে যাওয়া মানে তার পরের প্রতিটা সারির
     * `previous` ভুল। ⓘ আর ভয়ংকর দিকটা হলো **কিছুই লাল হয় না** —
     * সিলটা বসে যায়, সংখ্যাটা ফিরে আসে, আর খাতা নীরবে ভুল থাকে।
     *
     * ── ⓘ এখানে সারিটা কীভাবে সরানো হয় ─────────────────────────────
     * প্রথম পাতার ৫০০তম সারিটা হাতে আসার মুহূর্তে একটা **আগের** সারি
     * মুছে ফেলা হয় — অর্থাৎ পাতা একটা পড়া হয়ে গেছে, পাতা দুই এখনো
     * চাওয়া হয়নি। ⓘ যে সারিটা মোছা হয় তার সিল ইচ্ছা করে খালি, তাই
     * `reseal()` তাকে এমনিতেই এড়িয়ে যেত — সে চেইনের হিসাবে ঢোকে না,
     * কেবল জানালাটা এক ঘর সরায়। ⭐ তাতে পরিমাপটা একটাই জিনিস মাপে:
     * পাতা বদলানোর কৌশল।
     */
    public function test_resealing_a_chain_longer_than_one_page_skips_no_row(): void
    {
        $id = $this->company->id;

        $this->growTheChainPastOnePage();

        $before = $this->chainIds();

        $this->assertGreaterThan(self::PAGE + 1, count($before),
            'চেইনটা এক পাতার চেয়ে লম্বা হয়নি — তাহলে এই পরীক্ষাটা কিছুই মাপছে না।');

        /*
         * ⓘ সিল ছাড়া একটা আগের সারি — `reseal()` এটাকে `continue` করে,
         * তাই এর মোছা যাওয়া চেইনের অঙ্ক বদলায় না, কেবল সেটের মাপ।
         */
        $vanishing = $before[1];
        DB::table('ledger_entries')->where('id', $vanishing)->update(['row_hash' => null]);

        $visited = $this->watchTheWalk($vanishing);

        $sealed = LedgerChain::reseal($id);

        $walked = $visited();
        $survivors = array_values(array_diff($before, [$vanishing]));
        $missed = array_values(array_diff($survivors, $walked));

        $this->assertGreaterThan(0, $sealed, 'একটা সারিতেও নতুন সিল বসেনি।');

        $this->assertSame([], $missed, implode(PHP_EOL, [
            'সিল বসানোর সময় এই সারিগুলো কেউ দেখেইনি: '.implode(', ', $missed),
            '',
            '⛔ পাতা বদলানোর সময় জানালাটা সরে গেছে, আর বাদ পড়া সারির',
            'পরের প্রতিটা সারির `previous` এখন ভুল।',
        ]));

        $this->assertTrue(LedgerChain::verify($id)['ok'], implode(PHP_EOL, [
            'সিল বসানোর পরেও খাতা মেলেনি।',
            '',
            'ⓘ এক পাতার ভিতরে থাকলে এটা সবুজ হত — লম্বা চেইনেই কেবল',
            'পাতা বদলানোর গর্তটা দেখা যায়।',
        ]));
    }

    /**
     * ⭐ যাচাইও এক পাতার পরে **প্রতিটা সারি দেখে**।
     *
     * ── ⚠️ কেন এটা আলাদা করে মাপা হয় ───────────────────────────────
     * `verify()` চলে **অ্যাপ চালু অবস্থায়** — রোজকার `abos:books-check`
     * আর প্রতিটা ডিপ্লয়ে। ⛔ একটা সারি না দেখা আর সারিটা ঠিক থাকা
     * পাহারার কাছে এক কথা হয়ে যায়, আর সে তখন ভুল সারির নাম বলে।
     *
     * ⓘ এখানে দাবিটা `ok` নিয়ে নয় — **কভারেজ** নিয়ে: শুরুতে যত সারি
     * ছিল, হাঁটার সময় তার প্রতিটা হাতে এসেছে কি না, আর `checked`
     * সংখ্যাটা সত্যিই ততগুলো বলছে কি না।
     */
    public function test_verifying_a_chain_longer_than_one_page_looks_at_every_row(): void
    {
        $id = $this->company->id;

        $this->growTheChainPastOnePage();

        LedgerChain::reseal($id);

        $this->assertTrue(LedgerChain::verify($id)['ok'],
            'লম্বা চেইনটা শুরুতেই মেলেনি — তাহলে নিচের মাপটা অন্য কিছু মাপছে।');

        $before = $this->chainIds();

        $this->assertGreaterThan(self::PAGE + 1, count($before),
            'চেইনটা এক পাতার চেয়ে লম্বা হয়নি — তাহলে এই পরীক্ষাটা কিছুই মাপছে না।');

        $visited = $this->watchTheWalk($before[1]);

        $result = LedgerChain::verify($id);

        $walked = $visited();
        $missed = array_values(array_diff($before, $walked));

        $this->assertSame([], $missed, implode(PHP_EOL, [
            'যাচাই এই সারিগুলো একবারও দেখেনি: '.implode(', ', $missed),
            '',
            '⛔ অথচ হাঁটা শুরুর সময় ওগুলো খাতায় ছিল। পাহারাটা তাহলে',
            'পুরো খাতা দেখে না, আর তার সবুজ-লাল দুইটাই অন্ধ।',
        ]));

        $this->assertSame(count($before), $result['checked'], implode(PHP_EOL, [
            'যাচাই বলছে সে '.$result['checked'].'টা সারি গুনেছে, কিন্তু খাতায় ছিল '.count($before).'টা।',
        ]));
    }

    /**
     * চেইনটাকে এক পাতার চেয়ে লম্বা করা — সিল এখনো ভুল।
     *
     * ⓘ সারিগুলো একটা চলতি সারির নকল, কেবল `public_id` আলাদা আর সিলের
     * ঘরে একটা ভুয়া মান — `reseal()` ঠিক এই অবস্থাটার জন্যই আছে।
     * ⚠️ ইঞ্জিন দিয়ে ৬০০টা দাখিলা বসালে পরীক্ষাটা মিনিটে পৌঁছাত।
     */
    private function growTheChainPastOnePage(int $rows = 600): void
    {
        $template = DB::table('ledger_entries')
            ->where('company_id', $this->company->id)
            ->orderBy('id')
            ->first();

        $this->assertNotNull($template, 'সিডার একটাও খতিয়ান-সারি বসায়নি।');

        $template = (array) $template;
        unset($template['id']);

        $batch = [];

        for ($i = 0; $i < $rows; $i++) {
            $row = $template;

            $row['public_id'] = (string) Str::uuid7();
            $row['source_id'] = 900000 + $i;
            $row['source_line_id'] = null;
            $row['debit'] = '1.0000';
            $row['credit'] = '0.0000';
            $row['prev_hash'] = null;
            $row['row_hash'] = str_repeat('0', 64);

            $batch[] = $row;

            if (count($batch) === 200) {
                DB::table('ledger_entries')->insert($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            DB::table('ledger_entries')->insert($batch);
        }
    }

    /**
     * এই কোম্পানির প্রতিটা খতিয়ান-সারির আইডি, লেখার ক্রমে।
     *
     * @return list<int>
     */
    private function chainIds(): array
    {
        return DB::table('ledger_entries')
            ->where('company_id', $this->company->id)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * হাঁটার সময় কোন সারিগুলো সত্যিই হাতে এল — আর মাঝপথে একটা সরিয়ে দেওয়া।
     *
     * ── ⓘ কেন `retrieved` ঘটনাটা ────────────────────────────────────
     * এটাই একমাত্র জায়গা যেখানে **হাঁটাটা নিজে** কথা বলে: প্রতিটা সারি
     * মডেলে রূপ নেওয়ার সময় ঘটনাটা বাজে। ⭐ তাই "কী দেখা হলো" প্রশ্নটার
     * উত্তর অনুমান নয়, মাপা।
     *
     * ⚠️ মোছাটা প্রথম পাতার **শেষ** সারিতে বাজে — পাতা এক পুরো পড়া হয়ে
     * গেছে (PDO আগেই সব সারি তুলে এনেছে), পাতা দুই এখনো চাওয়া হয়নি।
     * ⓘ অর্থাৎ যে সারিটা মোছা হলো সে নিজে হাঁটায় গোনা হয়েই গেছে; কেবল
     * পরের জানালাটা সরে গেছে।
     *
     * @return \Closure(): list<int> যা এ পর্যন্ত দেখা আইডিগুলো ফেরত দেয়
     */
    private function watchTheWalk(int $vanishing): \Closure
    {
        $walked = [];
        $seen = 0;
        $removed = false;

        LedgerEntry::retrieved(function (LedgerEntry $row) use (&$walked, &$seen, &$removed, $vanishing): void {
            $walked[] = (int) $row->id;
            $seen++;

            if (! $removed && $seen >= self::PAGE) {
                $removed = true;

                DB::table('ledger_entries')->where('id', $vanishing)->delete();
            }
        });

        return static function () use (&$walked): array {
            return array_values(array_unique($walked));
        };
    }
}

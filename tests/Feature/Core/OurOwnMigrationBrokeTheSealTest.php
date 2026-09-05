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
}

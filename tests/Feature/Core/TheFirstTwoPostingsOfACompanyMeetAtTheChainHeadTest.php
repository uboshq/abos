<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Security\LedgerChain;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ⛔ কোম্পানির প্রথম দুই দাখিলা একসাথে এলে দ্বিতীয়টা ৫০০ (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬, হিসাব ⓘ১৮)।
 *
 * ⓘ [[LedgerChain::next()]] আগে তালাসহ মাথা পড়ত, না পেলে `insert` — দুজন একসাথে এলে দুজনেরই ফাঁকের তালা, তারপর বসানো একে অপরের
 * অপেক্ষায় (deadlock), কিংবা প্রাথমিক চাবিতে ধাক্কা। দুই সংযোগ একসাথে এই পরীক্ষায় চলে না — সেটা Mac-এর MySQL-এ হাতে চালিয়ে দেখা
 * ([[LedgerChain::ensureHead()]]-এর টীকা)। এখানে পাহারা দুইটা: পাশেরজন মাথা বসিয়ে ফেললে বসানোর ধাপ চুপচাপ পার হয়, আর
 * দাখিলায় বসানো আসে তালাসহ পড়ার আগে, `ON DUPLICATE KEY` দিয়ে।
 */
final class TheFirstTwoPostingsOfACompanyMeetAtTheChainHeadTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
    }

    public function test_the_second_comer_finds_the_head_already_set_and_goes_on(): void
    {
        DB::table('ledger_chain_heads')->where('company_id', $this->company->id)->delete();

        $ensure = new ReflectionMethod(LedgerChain::class, 'ensureHead');

        // ⓘ প্রথমজন — মাথা নেই, বসে
        $ensure->invoke(null, (int) $this->company->id);
        $this->assertSame(1, DB::table('ledger_chain_heads')->where('company_id', $this->company->id)->count(), '⛔ মাথা বসেনি');

        // ⓘ প্রথমজন দাখিলা শেষ করেছে — মাথায় তার ছাপ
        DB::table('ledger_chain_heads')->where('company_id', $this->company->id)->update(['last_hash' => str_repeat('a', 64), 'entries' => 1]);

        // ⛔ দ্বিতীয়জন — "নেই" দেখেছিল, এখন বসাতে গেলে ধাক্কা নয়
        $ensure->invoke(null, (int) $this->company->id);

        $head = DB::table('ledger_chain_heads')->where('company_id', $this->company->id)->sole();
        $this->assertSame(str_repeat('a', 64), $head->last_hash, '⛔ দ্বিতীয়জন প্রথমজনের ছাপ মুছে দিল');
        $this->assertSame(1, (int) $head->entries);
    }

    public function test_a_companys_first_posting_sets_the_head_before_it_locks_it(): void
    {
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        DB::table('ledger_chain_heads')->where('company_id', $this->company->id)->delete();

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(PostingEngine::class)->post('test:first', 1, now()->toDateString(), [
            ['account_id' => StandardChart::find(StandardChart::SALARY_EXPENSE)->id, 'debit' => '10'],
            ['account_id' => StandardChart::find(StandardChart::SALARY_PAYABLE)->id, 'credit' => '10'],
        ]);
        $heads = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => strtolower($q))
            ->filter(fn ($q) => str_contains($q, 'ledger_chain_heads'))->values();
        DB::disableQueryLog();

        $set = $heads->search(fn ($q) => str_starts_with($q, 'insert'));
        $lock = $heads->search(fn ($q) => str_contains($q, 'for update'));

        $this->assertNotFalse($set, '⛔ প্রথম দাখিলায় মাথা বসানোই হয়নি');
        $this->assertNotFalse($lock, '⛔ মাথা তালাসহ পড়া হয়নি');
        $this->assertLessThan($lock, $set, '⛔ তালাসহ পড়া আগে, বসানো পরে — দুজন একসাথে এলে ফাঁকের তালায় deadlock');
        $this->assertStringContainsString('on duplicate key update', $heads[$set],
            '⛔ INSERT IGNORE বা সাধারণ insert — তিনজন একসাথে এলে ভাগের তালা থেকে deadlock, বা চাবিতে ধাক্কা');
        // ⓘ দুই সারি — মাথা দুইবার এগোয়
        $this->assertSame(2, (int) DB::table('ledger_chain_heads')->where('company_id', $this->company->id)->value('entries'));
    }
}

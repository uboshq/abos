<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ⛔ খাতার বসা সারি মডেলের পথে বদলানো বা মোছা যেত, কেবল হ্যাশ-চেইন পরে ধরত (ERP ১০০%-এর হিসাবের ফাঁক #২৬, ২০ অক্টোবর ২০২৬)।
 *
 * <p>SOX ITGC change control আর ISA 315 আগে থামানো নিয়ন্ত্রণ চায়, কেবল ধরা পড়া নয়। এখন `LedgerEntry`-তে `save()` বা
 * `delete()` ডাকলে থামে, সারি অক্ষত থাকে; ভুল শোধরানোর পথ উল্টো দাখিলা, সেটা আগের মতোই চলে।
 */
final class APostedLedgerLineCouldBeRewrittenTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private LedgerEntry $line;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $till = Account::query()->findOrFail(app(CashTillService::class)->ensurePrimaryTill()->account_id);
        $this->putMoneyIn($till, '5000');
        $this->line = LedgerEntry::query()->where('account_id', $till->id)->latest('id')->firstOrFail();
    }

    public function test_a_posted_line_cannot_be_changed_through_the_model(): void
    {
        $before = $this->line->getAttributes();

        $this->line->debit = '50000';
        $this->line->narration = 'rewritten';

        try {
            $this->line->save();
            $this->fail('⛔ বসা সারির অঙ্ক বদলে গেল');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('post a reversal', $e->getMessage());
        }

        $fresh = LedgerEntry::query()->findOrFail($this->line->id);
        $this->assertSame(0, bccomp((string) $fresh->debit, '5000', 4), '⛔ অঙ্ক বদলেছে');
        $this->assertSame($before['row_hash'], $fresh->row_hash);
        $this->assertSame($before['narration'], $fresh->narration);
    }

    public function test_a_posted_line_cannot_be_deleted_through_the_model(): void
    {
        $count = LedgerEntry::query()->count();

        try {
            $this->line->delete();
            $this->fail('⛔ বসা সারি মুছে গেল');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('post a reversal', $e->getMessage());
        }

        $this->assertSame($count, LedgerEntry::query()->count(), '⛔ সারি কমেছে');
        $this->assertNotNull(LedgerEntry::query()->find($this->line->id));
    }

    public function test_the_right_road_a_reversal_still_posts_and_leaves_the_original_whole(): void
    {
        $count = LedgerEntry::query()->count();
        $hash = $this->line->row_hash;

        app(PostingEngine::class)->reverse($this->line->source_type, (int) $this->line->source_id, now()->toDateString(), 'test');

        $this->assertSame($count + 2, LedgerEntry::query()->count(), 'উল্টো দাখিলার দুই সারি বসেনি');
        $this->assertSame($hash, LedgerEntry::query()->findOrFail($this->line->id)->row_hash, '⛔ উল্টানোয় আসল সারি বদলাল');
    }
}

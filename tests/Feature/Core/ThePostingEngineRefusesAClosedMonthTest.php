<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ খাতার ইঞ্জিন নিজেই বন্ধ মাসে কিছু বসায় না — [[PostingEngine::post()]] আর [[PostingEngine::reverse()]], [[OpenPeriod::assertOpen()]]
 * দিয়ে (fe, ২১ অক্টোবর ২০২৬)।
 *
 * ⓘ [[TheLockOnThePastWasNeverRealTest]] একই কথা বলে ভাউচারের পথে, কিন্তু ভাউচারের সেবা নিজেও মাসের তালা দেখে
 * ([[VoucherService]]) — তাই ইঞ্জিনের পাহারা সরালেও ওটা সবুজ থাকত। এখানে ইঞ্জিনকে সরাসরি ডাকা হয়, অন্য কোনো পাহারা ছাড়া।
 * বিক্রির সম্পাদনা আর বাতিল-ইনভয়েসের শেষ দেয়াল এটাই ([[AClosedMonthsBillIsNotEditedTest]],
 * [[AnAugustBillCancelledInOctoberLeavesAugustAloneTest]])।
 */
final class ThePostingEngineRefusesAClosedMonthTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'closed_month_probe';

    private Company $company;

    private PeriodLock $lock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Carbon::setTestNow('2026-10-10 10:00:00');

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        app(StandardChart::class)->install();

        $this->lock = PeriodLock::query()->create([
            'company_id' => $this->company->id, 'year' => 2026, 'month' => 8,
            'reason' => 'আগস্টের হিসাব ছাপা', 'locked_by' => $owner->id, 'locked_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_posting_dated_in_a_closed_month_is_refused_by_the_month_lock(): void
    {
        try {
            app(PostingEngine::class)->post(self::SOURCE, 1, '2026-08-20', $this->lines());
            $this->fail('⛔ বন্ধ আগস্টে খাতার ইঞ্জিন দাখিলা বসিয়ে দিল।');
        } catch (ValidationException $e) {
            $this->assertSame(
                (string) __('core.period.month_closed', ['month' => $this->lock->label(), 'reason' => $this->lock->reason]),
                $e->errors()['trx_date'][0] ?? null,
                '⛔ থামল, কিন্তু মাসের তালার কথায় নয়: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE),
            );
        }

        $this->assertSame(0, LedgerEntry::query()->where('source_type', self::SOURCE)->count(), '⛔ থামার পরেও সারি রয়ে গেছে।');

        // ⓘ পাশের খোলা মাসে একই দাখিলা বসে — থামার কারণ কেবল মাসটা
        app(PostingEngine::class)->post(self::SOURCE, 1, '2026-10-10', $this->lines());
        $this->assertSame(2, LedgerEntry::query()->where('source_type', self::SOURCE)->count(), 'প্রস্তুতিটাই ভুল — খোলা মাসেও বসেনি।');
    }

    public function test_a_reversal_dated_in_a_closed_month_is_refused_too(): void
    {
        app(PostingEngine::class)->post(self::SOURCE, 2, '2026-10-10', $this->lines());

        try {
            app(PostingEngine::class)->reverse(self::SOURCE, 2, '2026-08-20');
            $this->fail('⛔ বন্ধ আগস্টে উল্টো দাখিলা বসে গেল।');
        } catch (ValidationException $e) {
            $this->assertSame(
                (string) __('core.period.month_closed', ['month' => $this->lock->label(), 'reason' => $this->lock->reason]),
                $e->errors()['trx_date'][0] ?? null,
                '⛔ থামল, কিন্তু মাসের তালার কথায় নয়: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE),
            );
        }

        $this->assertSame(0, LedgerEntry::query()->where('source_type', self::SOURCE.':reversal')->count(), '⛔ বন্ধ মাসে উল্টো সারি রয়ে গেছে।');
    }

    /** @return list<array<string, mixed>> */
    private function lines(): array
    {
        return [
            ['account_id' => StandardChart::find(StandardChart::RECEIVABLE)->id, 'debit' => '500'],
            ['account_id' => StandardChart::find(StandardChart::PAYABLE)->id, 'credit' => '500'],
        ];
    }
}

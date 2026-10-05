<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\MoneyTransferService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ম৯ — পেছনের তারিখের স্থানান্তর সেই দিনের পরে বাক্স ঋণাত্মক করে না (Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে কেবল আজকের জের দেখা হত। ⓘ এখন সেই তারিখ থেকে আজ পর্যন্ত প্রতিটা দিনের শেষে সবচেয়ে কম জের।
 */
final class ABackdatedTransferEmptiedYesterdaysBoxTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private CashTill $from;

    private CashTill $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $this->from = app(CashTillService::class)->create(['name_en' => 'M9 counter', 'holder_id' => $owner->id]);
        $this->to = app(CashTillService::class)->create(['name_en' => 'M9 safe', 'holder_id' => $owner->id]);
    }

    /** ১০০০ এল ছয় দিন আগে, ৮০০ গেল দুই দিন আগে — পাঁচ দিন আগের ৫০০ পাঠালে দুই দিন আগে বাক্স −৩০০ হত */
    public function test_a_transfer_dated_before_a_later_spend_is_refused(): void
    {
        $this->putMoneyIn($this->from->account, '1000', now()->subDays(6)->toDateString());
        $this->spend('800', now()->subDays(2)->toDateString());

        $this->assertRefused(fn () => $this->send('500', now()->subDays(5)->toDateString()),
            '⛔ পেছনের তারিখের স্থানান্তর পরের খরচের পরে বাক্স ঋণাত্মক করল।');

        // ⓘ যতটুকু সব দিন টেকে, ততটুকু চলে
        $this->assertSame('draft', $this->send('200', now()->subDays(5)->toDateString())->status);
    }

    /** আজ এল ১০০০ — তিন দিন আগের তারিখে পাঠানো যায় না, তখন বাক্সে কিছুই ছিল না */
    public function test_money_that_came_later_does_not_fund_an_earlier_transfer(): void
    {
        $this->putMoneyIn($this->from->account, '1000');

        $this->assertRefused(fn () => $this->send('500', now()->subDays(3)->toDateString()),
            '⛔ পরে আসা টাকা দিয়ে আগের তারিখের স্থানান্তর হলো — সেদিন বাক্স ঋণাত্মক।');
    }

    /** ⓘ আজকের তারিখে আজকের মতোই */
    public function test_a_transfer_dated_today_is_as_before(): void
    {
        $this->putMoneyIn($this->from->account, '1000');

        $this->assertSame('draft', $this->send('1000', now()->toDateString())->status);
        $this->assertRefused(fn () => $this->send('1', now()->toDateString()), 'খালি বাক্স থেকে আরও পাঠানো গেল।');
    }

    private function send(string $amount, string $date): \App\Modules\Accounts\Models\MoneyTransfer
    {
        return app(MoneyTransferService::class)->initiate([
            'from_till_id' => $this->from->id, 'to_till_id' => $this->to->id, 'amount' => $amount, 'trx_date' => $date,
        ]);
    }

    private function spend(string $amount, string $date): void
    {
        app(PostingEngine::class)->post(
            sourceType: 'test:spend', sourceId: random_int(1, PHP_INT_MAX), trxDate: $date,
            lines: [
                ['account_id' => StandardChart::find(StandardChart::ENTERTAINMENT)->id, 'debit' => $amount, 'narration' => 'খরচ'],
                ['account_id' => $this->from->account_id, 'credit' => $amount, 'narration' => 'খরচ'],
            ],
        );
    }

    private function assertRefused(\Closure $act, string $why): void
    {
        try {
            $act();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors(), 'আটকেছে, কিন্তু অন্য কারণে: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->fail($why);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Module\ModuleRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Services\CapitalService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * হিসাবরক্ষকের ছাঁচে লাভ ঘোষণার চাবি, আর ঘোষণার নিশ্চিত-ধাপে বাছা তারিখ ও বিবরণ হারাত — পুরো-ERP অডিট, অর্থ M27, ১০ অক্টোবর ২০২৬।
 *
 * ⭐ Accountant ছাঁচে `finance.capital.post` নেই — লাভ ঘোষণা আর মূলধনে নেওয়া মালিকের সিদ্ধান্ত।
 * ⭐ পূর্বরূপে যে তারিখ আর বিবরণ বাছা হলো, "এখনই ঘোষণা" ফর্ম সেটাই নিয়ে যায় — আজকের তারিখ আর খালি বিবরণ নয়।
 */
final class TheAccountantCouldDeclareProfitTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    public function test_the_accountant_template_does_not_carry_the_profit_key(): void
    {
        $finance = collect(app(ModuleRegistry::class)->all())->first(fn ($m) => $m->code === 'finance');
        $this->assertNotNull($finance, 'দৃশ্যটাই বানানো যায়নি — অর্থ মডিউল নেই');
        $this->assertArrayHasKey('Accountant', $finance->roleTemplates, 'দৃশ্যটাই বানানো যায়নি — হিসাবরক্ষকের ছাঁচ নেই');
        $this->assertContains('finance.capital.view', $finance->roleTemplates['Accountant'], 'দৃশ্যটাই বানানো যায়নি — ছাঁচ পড়া যায়নি');
        $this->assertNotContains('finance.capital.post', $finance->roleTemplates['Accountant'],
            '⛔ হিসাবরক্ষকের ছাঁচে লাভ ঘোষণা আর মূলধনে নেওয়ার চাবি');
    }

    public function test_the_confirm_form_carries_the_chosen_day_and_narration(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
        $cash = Account::query()->money()->postable()->active()->where('money_kind', Account::CASH)->orderBy('code')->firstOrFail();
        $this->putMoneyIn($cash, '500000', now()->subMonths(2)->toDateString());

        app(PostingEngine::class)->post(sourceType: 'test:earned', sourceId: 1, trxDate: now()->subDays(20)->toDateString(), lines: [
            ['account_id' => $cash->id, 'debit' => '100000'],
            ['account_id' => StandardChart::find(StandardChart::RETAINED_EARNINGS)->id, 'credit' => '100000'],
        ]);
        $partner = Person::query()->create(['code' => 'P-M27', 'name_en' => 'M27 Partner', 'name_bn' => 'M27 Partner', 'is_active' => true]);
        app(CapitalService::class)->post(app(CapitalService::class)->record([
            'person_id' => $partner->id, 'contributor_type' => CapitalEntry::OWNER, 'entry_type' => CapitalEntry::CONTRIBUTION,
            'trx_date' => now()->subDays(10)->toDateString(), 'amount' => '50000',
        ]), $cash);

        $day = now()->subDays(3)->toDateString();
        $html = $this->post(route('finance.profit.preview'), ['profit' => '10000', 'trx_date' => $day, 'narration' => 'আশ্বিনের ভাগ'])
            ->assertOk()->getContent();

        $start = strpos($html, route('finance.profit.declare'));
        $this->assertNotFalse($start, 'দৃশ্যটাই বানানো যায়নি — ঘোষণার ফর্ম নেই');
        $form = substr($html, $start, (int) strpos($html, '</form>', $start) - $start);

        $this->assertStringContainsString('name="trx_date" value="'.$day.'"', $form, '⛔ পূর্বরূপে বাছা তারিখ হারাল — ঘোষণা আজকের তারিখে বসত');
        $this->assertStringContainsString('name="narration" value="আশ্বিনের ভাগ"', $form, '⛔ পূর্বরূপে লেখা বিবরণ হারাল');
    }
}

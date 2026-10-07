<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Services\CapitalService;
use App\Modules\Finance\Services\InvestmentReturns;
use App\Modules\Finance\Services\ProfitDistribution;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ লাভ বণ্টন পুরো কোম্পানির — হেডারে বাছা শাখার নয় (পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ ⛔৪)।
 *
 * ⓘ লাভ-দেনার জাবেদা পুরো কোম্পানির জন্য বসে। আগে এক শাখা বাছা থাকলে কেবল সেই শাখায় মূলধন দেওয়া মানুষেরা ভাগ পেতেন, আর পুরো
 * % ভাগ হত সেই শাখার মূলধনে — ভুল মানুষ, ভুল অঙ্ক। মূলধনের পাতা আগের মতোই হেডারের শাখা মানে।
 */
final class TheProfitIsSharedOverTheWholeCompanyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Person $there;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->branch('MMS')->id);
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();

        $this->contribute('WC-HERE', 'Partner Here', '600000', 'MMS');
        $this->there = $this->contribute('WC-THERE', 'Partner There', '400000', 'NTK');
    }

    public function test_the_preview_and_the_returns_read_every_branch_while_the_capital_page_keeps_the_picked_one(): void
    {
        $this->choose('all');
        $everyone = app(ProfitDistribution::class)->preview('100000');

        $this->choose($this->branch('MMS')->id);
        $picked = app(ProfitDistribution::class)->preview('100000');

        $this->assertContains($this->there->id, array_map(fn ($r) => (int) $r['person_id'], $picked), '⛔ অন্য শাখার অংশীদার লাভের ভাগ পেলেন না');
        $this->assertEquals($everyone, $picked, '⛔ হেডারের শাখা বদলালে লাভের ভাগ বদলায়');

        $returns = app(InvestmentReturns::class)->forPeriod(now()->subYear(), now());
        $this->assertContains($this->there->id, array_map(fn ($r) => (int) $r['person_id'], $returns['rows']), '⛔ বিনিয়োগের আয়ে অন্য শাখার অংশীদার নেই');

        // ⓘ মূলধনের পাতা — বাছা শাখাই (৫ অক্টোবর ২০২৬, মালিকের প্রশ্ন)
        $this->assertNotContains($this->there->id, array_map(fn ($r) => (int) $r['person_id'], app(CapitalService::class)->positions()),
            '⛔ মূলধনের পাতা হেডারের শাখা মানা ছেড়ে দিল');

        // ⓘ পর্দার প্রিভিউ — হেডারে MMS বাছা, তবু অন্য শাখার অংশীদার তালিকায়
        $this->post(route('finance.profit.preview'), ['profit' => '100000'])->assertOk()->assertSee('Partner There');
    }

    public function test_the_agreed_shares_are_added_over_every_branch_before_a_declaration(): void
    {
        // ⓘ চুক্তির অংশ: এখানে ৪০%, ওখানে ৭০% — মোট ১১০%; হেডারে MMS বাছা থাকলে আগে কেবল ৪০% দেখা যেত
        $this->contribute('WC-HERE-2', 'Agreed Here', '100', 'MMS', share: '40');
        $this->contribute('WC-THERE-2', 'Agreed There', '100', 'NTK', share: '70');
        $this->choose($this->branch('MMS')->id);

        try {
            app(ProfitDistribution::class)->declare(['profit' => '1000', 'trx_date' => now()->toDateString()]);
            $this->fail('⛔ অন্য শাখার চুক্তির অংশ না গুনে ঘোষণা এগোল');
        } catch (ValidationException $e) {
            // ⓘ ঠিক এই পাহারার বার্তা — পরের "ভাগের যোগ ঘোষণার বেশি" পাহারাও আটকাত, কিন্তু সেটা অন্য প্রশ্ন
            $this->assertContains((string) __('finance::validation.shares_over_a_hundred', ['total' => '110.0000']), $e->errors()['profit'] ?? [],
                '⛔ অংশের যোগে অন্য শাখার ৭০% নেই (অন্য কারণে আটকেছে: '.implode(' ', $e->errors()['profit'] ?? []).')');
        }
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function contribute(string $code, string $name, string $amount, string $branch, ?string $share = null): Person
    {
        $person = Person::query()->create(['code' => $code, 'name_en' => $name, 'name_bn' => $name, 'is_active' => true]);

        CapitalEntry::query()->create([
            'branch_id' => $this->branch($branch)->id, 'document_no' => 'CAP-'.$code, 'person_id' => $person->id,
            'contributor_type' => CapitalEntry::CONTRIBUTION, 'entry_type' => CapitalEntry::CONTRIBUTION, 'in_kind' => CapitalEntry::CASH,
            'trx_date' => now()->subMonth()->toDateString(), 'amount' => $amount, 'status' => CapitalEntry::POSTED,
            'share_percent' => $share,
        ]);

        return $person;
    }

    private function choose(int|string $branch): void
    {
        $this->actingAs($this->owner->fresh())->post(route('branch.switch'), ['branch_id' => (string) $branch])->assertRedirect();

        $this->owner = $this->owner->fresh();
        CompanyContext::set($this->company->id, $this->owner->current_branch_id);
        app(DataScope::class)->forget();
        $this->app->forgetScopedInstances();
        $this->actingAs($this->owner);
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}

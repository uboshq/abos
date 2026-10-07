<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\ProfitShare;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * লাভের পাতার বোতাম কেবল যাঁর পোস্টের চাবি আছে — abos-63-এর তালিকা (abos-bb-র নিরীক্ষার বাকি), ২ অক্টোবর ২০২৬।
 *
 * ⓘ পাতা খোলে দেখার চাবিতে (`finance.capital.view`), আর ঘোষণা ও মূলধনে যোগ চায় পোস্টের চাবি
 * (`finance.capital.post`)। ⛔ আগে বোতাম সবাই দেখতেন, চাপলে ৪০৩। ⓘ মাস বন্ধ আর বছর বন্ধের পাতা আগে থেকেই
 * ঠিক — খোলার চাবিই কাজের চাবি, আর আবার খোলা আলাদা চাবিতে ঢাকা।
 *
 * ⚠️ দাবিটা অন্ধ যেন না হয়: কারও বাকি পাওনা বসানো থাকে, তাই "মূলধনে যোগ" বোতামটা আঁকার অবস্থা সত্যিই আছে —
 * চাবিওয়ালা তাকে দেখেন, চাবিহীন দেখেন না।
 */
final class TheProfitButtonsShowOnlyToWhoCanPostTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $person = Person::query()->create(['code' => 'P-OWED', 'name_en' => 'Partner Owed', 'name_bn' => 'Partner Owed', 'is_active' => true]);

        ProfitShare::query()->create([
            'branch_id' => Branch::query()->firstOrFail()->id,
            'document_no' => 'PDS-TEST',
            'trx_date' => now()->toDateString(),
            'person_id' => $person->id,
            'profit_base' => '10000',
            'amount' => '10000',
            'status' => ProfitShare::POSTED,
            'posted_at' => now(),
        ]);
    }

    public function test_the_capitalise_button_needs_the_post_key(): void
    {
        $form = e(route('finance.profit.capitalise'));

        $this->get(route('finance.profit.index'))->assertOk()
            ->assertSee($form, false);

        $viewer = User::factory()->create(['current_company_id' => $this->company->id]);
        $viewer->companies()->attach($this->company->id, ['is_active' => true]);
        $viewer->givePermissionTo('finance.capital.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($viewer->fresh())->get(route('finance.profit.index'))->assertOk()
            ->assertDontSee($form, false);
    }
}

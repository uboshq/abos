<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Imports\ChartOfAccountsImporter;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * হিসাবের ছক দুইবার তুললে প্রতিটা খাত দুইবার হত — abos-63-এর তালিকা (abos-bb-র নিরীক্ষার বাকি), ২ অক্টোবর ২০২৬।
 *
 * ⛔ কোড খালি থাকলে খাত নতুন কোড পায়, তাই কোডের অনন্যতা কিছুই ধরত না। ⭐ এখন একই অভিভাবকের নিচে একই নাম থাকলে
 * দ্বিতীয় সারি ফেরত যায়; অন্য অভিভাবকের নিচে একই নাম চলে (ভিন্ন খাত)।
 */
final class TheChartImportedTwiceMadeEveryHeadTwiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_the_same_head_without_a_code_is_not_made_twice(): void
    {
        $importer = app(ChartOfAccountsImporter::class);
        $row = ['code' => '', 'name_en' => 'Generator Fuel', 'name_bn' => '', 'parent' => '5000', 'type' => 'expense', 'nature' => 'debit', 'is_group' => ''];

        $this->assertSame([], $importer->check($row), 'প্রস্তুতিটাই ভুল — প্রথম সারিটাই ফেরত গেছে।');
        $importer->import($row);

        $again = $importer->check([...$row, 'name_en' => '  generator fuel ']);

        $this->assertNotEmpty($again, '⛔ একই খাত কোড ছাড়া দ্বিতীয়বার বসতে যাচ্ছিল।');
        $this->assertSame(1, Account::query()->whereRaw('LOWER(name_en) = ?', ['generator fuel'])->count());

        $elsewhere = $importer->check([...$row, 'parent' => '4000', 'type' => 'income', 'nature' => 'credit']);
        $this->assertSame([], $elsewhere, 'অন্য অভিভাবকের নিচে একই নাম আটকে গেছে — ওটা আলাদা খাত।');
    }
}

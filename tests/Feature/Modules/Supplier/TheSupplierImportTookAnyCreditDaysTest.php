<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Supplier\Imports\SupplierImporter;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ সরবরাহকারী আমদানিতে বাকির দিন যা-ই হোক পার হত (Supplier নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[SupplierImporter::check()]])।
 *
 * ⚠️ `is_numeric` "-5" মানত — যাচাই সবুজ, বসানোর সময় SQL-এ ভাঙত; "30.5" চুপচাপ ৩০, "1e2" ১০০, আর "400" বসে পরে প্রতিটা ফর্ম-সংরক্ষণ
 * আটকাত (ফর্ম: `integer|min:0|max:365`)।
 */
final class TheSupplierImportTookAnyCreditDaysTest extends TestCase
{
    use RefreshDatabase;

    public function test_credit_days_take_the_forms_rule(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        // ⓘ আমদানির পথ প্রতিটা ঘোষিত কলাম ভরে পাঠায় (খালি হলেও) — সারিটা সেভাবেই
        $row = ['name_en' => 'Days Mills'] + array_fill_keys(array_keys(SupplierImporter::columns()), '');
        $importer = app(SupplierImporter::class);
        $reason = __('core.import.not_a_number', ['column' => 'credit_days']);

        foreach (['-5', '400', '30.5', '1e2', '৩০'] as $days) {
            $this->assertContains($reason, $importer->check(['credit_days' => $days] + $row), '⛔ বাকির দিন "'.$days.'" পার হল');
        }

        $this->assertNotContains($reason, $importer->check(['credit_days' => '30'] + $row), 'সাধারণ দিন আগের মতোই চলে');
    }
}

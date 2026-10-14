<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Supplier\Imports\SupplierImporter;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ "1e5" শুরুর দেনায় ৫০০ দিত (Supplier নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[SupplierRequest]], [[SupplierImporter::check()]])।
 *
 * ⚠️ `numeric` আর `is_numeric` দুটোই "1e5" মানে, কিন্তু bcmath মানে না — ফর্মে ValueError (৫০০), ইমপোর্টে যাচাই সবুজ অথচ
 * সারি পড়ে যেত। ⭐ এখন কারণসহ ফেরত: কেবল সাধারণ দশমিক।
 */
final class OneEFiveBrokeTheSupplierFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_scientific_notation_is_refused_with_a_reason(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        app(StandardChart::class)->install();

        $this->actingAs($owner)->post(route('supplier.store'), [
            'name_en' => 'Exponent Mills',
            'opening_balance' => '1e5',
            'credit_limit' => '2e3',
        ])->assertSessionHasErrors(['opening_balance', 'credit_limit']);
        $this->assertFalse(Supplier::query()->where('name_en', 'Exponent Mills')->exists());

        $errors = app(SupplierImporter::class)->check([
            'code' => '', 'name_en' => 'Exponent Mills', 'opening_balance' => '1e5', 'credit_limit' => '', 'credit_days' => '', 'opening_date' => '', 'party_type' => '', 'payment_term' => '',
        ]);
        $this->assertContains(__('core.import.not_a_number', ['column' => 'opening_balance']), $errors, '⛔ ইমপোর্টের যাচাই "1e5" মেনে নিল');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Imports\CustomerImporter;
use App\Modules\Supplier\Imports\SupplierImporter;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ আমদানির যাচাই ঘরের মাপ আর ইমেইলের রূপ দেখত না (Customer/Supplier নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[CustomerImporter::check()]],
 * [[SupplierImporter::check()]])।
 *
 * ⚠️ ৪০ অক্ষরের ফোন যাচাইয়ে সবুজ, তারপর ডাটাবেসের ৩২-এর ঘরে ভাঙত; "not-an-email" ইমেইল হয়ে বসত। ⭐ এখন ফর্মের মাপেই কারণসহ লাল।
 */
final class TheImportTookAnyLengthAndAnyEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_imports_measure_text_like_the_form(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        foreach ([CustomerImporter::class, SupplierImporter::class] as $class) {
            $row = ['name_en' => 'Measured Shop'] + array_fill_keys(array_keys($class::columns()), '');
            $importer = app($class);

            $this->assertNotSame([], $importer->check(['phone' => str_repeat('9', 40)] + $row), "⛔ {$class}: ৪০ অক্ষরের ফোন সবুজ");
            $this->assertNotSame([], $importer->check(['email' => 'not-an-email'] + $row), "⛔ {$class}: ভুল ইমেইল সবুজ");
            $this->assertSame([], $importer->check(['phone' => '01711000000', 'email' => 'shop@example.com'] + $row), "{$class}: ঠিক সারি থেমে গেল");
        }
    }
}

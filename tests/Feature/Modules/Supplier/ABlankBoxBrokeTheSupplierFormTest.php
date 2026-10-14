<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ সরবরাহকারীর ফর্মে খালি ঘর ৫০০ দিত (Supplier নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[SupplierRequest::prepareForValidation()]],
 * [[SupplierService::update()]])।
 *
 * ⚠️ খালি ঘর Laravel-এ null; বাকির সীমা, মেয়াদ আর কোড তিনটাই `NOT NULL`। সীমা বা মেয়াদ খালি রেখে নতুন সরবরাহকারী, বা
 * সম্পাদনায় কোড মুছে সংরক্ষণ — দুটোই "Column cannot be null"। ⭐ এখন খালি সংখ্যা ০, আর খালি কোড মানে কোড যেমন ছিল।
 */
final class ABlankBoxBrokeTheSupplierFormTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        app(StandardChart::class)->install();
    }

    public function test_a_new_supplier_with_blank_number_boxes_is_saved_at_zero(): void
    {
        $this->actingAs($this->owner)->post(route('supplier.store'), [
            'name_en' => 'Blank Box Mills',
            'credit_limit' => '',
            'credit_days' => '',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $supplier = Supplier::query()->where('name_en', 'Blank Box Mills')->first();
        $this->assertNotNull($supplier, '⛔ খালি ঘরসহ নতুন সরবরাহকারী সংরক্ষণ হল না।');
        $this->assertSame(0, bccomp((string) $supplier->credit_limit, '0', 4));
        $this->assertSame(0, (int) $supplier->credit_days);
    }

    public function test_an_edit_with_the_code_and_numbers_cleared_keeps_the_code(): void
    {
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $code = $supplier->code;

        $this->actingAs($this->owner)->put(route('supplier.update', $supplier), [
            'name_en' => $supplier->name_en,
            'code' => '',
            'credit_limit' => '',
            'credit_days' => '',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($code, $supplier->fresh()->code, '⛔ মুছে দেওয়া কোড বদলে গেল বা সংরক্ষণ ভাঙল।');
        $this->assertSame(0, bccomp((string) $supplier->fresh()->credit_limit, '0', 4));
    }
}

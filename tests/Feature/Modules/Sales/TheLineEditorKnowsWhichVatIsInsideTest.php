<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\MasterData\Models\Tax;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * সারির সম্পাদক জানে কোন পণ্যের দরে ভ্যাট ধরা — বিক্রি আর ক্রয় দুই ফর্মেই (Sales অডিট, ১০ অক্টোবর ২০২৬)।
 *
 * ⚠️ সার্ভার ভেতরের ভ্যাট আর মোটে যোগ করে না (d1401d93), কিন্তু পর্দা জানত না কোন পণ্য ভেতরের হারে — তাই
 * net + ভ্যাট দেখাত। ⓘ হিসাবটা [[resources/js/components/line-vat.test.js]] মাপে; এখানে মাপা হয় তালিকাটা
 * সত্যিই পর্দায় পৌঁছায় কিনা — ভেতরের পণ্য আছে, বাইরের নেই।
 */
final class TheLineEditorKnowsWhichVatIsInsideTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_line_editors_are_told_which_products_carry_their_vat_inside(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $tax = Tax::query()->create([
            'code' => 'LE-VAT15IN', 'name_en' => 'VAT 15% inside', 'name_bn' => 'ভ্যাট ১৫% ভিতরে',
            'rate' => '15', 'kind' => 'vat', 'is_inclusive' => true, 'is_active' => true,
        ]);
        [$inside, $outside] = Product::query()->where('is_active', true)->orderBy('id')->take(2)->get()->all();
        $inside->forceFill(['tax_id' => $tax->id])->save();
        $outside->forceFill(['tax_id' => null])->save();

        $expected = Blade::render('inclusive: @js($ids),', ['ids' => [(string) $inside->id]]);

        // ⓘ বিক্রির দিকে চালানের ফর্ম — বিল চালান ছাড়া খোলে না (৪০৪, মালিকের নিয়ম); একই সারির সম্পাদক
        foreach (['sales.challan.create', 'purchase.bill.create'] as $page) {
            $this->get(route($page))->assertOk()->assertSee($expected, false);
        }
    }
}

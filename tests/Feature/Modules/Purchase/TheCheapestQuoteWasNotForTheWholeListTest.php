<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchase\Models\Rfq;
use App\Modules\Purchase\Services\RfqService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ দরপত্রের "সবচেয়ে কম" ভুল জনের হত (fe, ১৩ অক্টোবর ২০২৬; [[RfqService::compare()]], [[RfqService::quote()]])।
 *
 * ⚠️ আমন্ত্রণ না পাওয়া সরবরাহকারীর দর বসত; দুই পণ্যের দরপত্রে একটার সস্তা দর দিলেই মোট কম, দাগ "সবচেয়ে কম"; আর লাইনের মোটের
 * বেশি ছাড়ে নিট ঋণাত্মক — সেটাও "সবচেয়ে কম"। ⭐ এখন: কেবল আমন্ত্রিত, কেবল দরপত্রের পণ্যে, ছাড় লাইনের ভেতরে, আর দাগ কেবল সব
 * পণ্যের দর দেওয়া জনের।
 */
final class TheCheapestQuoteWasNotForTheWholeListTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_an_invited_complete_honest_quote_can_be_marked_lowest(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(SettingsService::class)->set('purchase.screen_requisitions', true);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        [$soap, $rice] = Product::query()->orderBy('id')->take(2)->get()->all();
        $suppliers = Supplier::query()->orderBy('id')->take(2)->get()->all();
        while (count($suppliers) < 2) {
            $suppliers[] = Supplier::query()->create(['code' => 'SUP-Q'.count($suppliers), 'name_en' => 'Q'.count($suppliers),
                'name_bn' => 'Q'.count($suppliers), 'is_active' => true]);
        }
        [$partial, $whole] = $suppliers;
        $outsider = Supplier::query()->create(['code' => 'SUP-OUT', 'name_en' => 'Outsider', 'name_bn' => 'বাইরের', 'is_active' => true]);

        $service = app(RfqService::class);
        $rfq = $service->create(
            ['trx_date' => now()->toDateString(), 'respond_by' => now()->addDays(3)->toDateString()],
            [['product_id' => $soap->id, 'qty' => '5'], ['product_id' => $rice->id, 'qty' => '5']],
            [$partial->id, $whole->id],
        );

        $this->refused(fn () => $this->quote($rfq, $outsider, [[$soap, '1', '0'], [$rice, '1', '0']]), 'supplier_id', '⛔ আমন্ত্রণ না পাওয়া সরবরাহকারীর দর বসল।');
        $this->refused(fn () => $this->quote($rfq, $whole, [[$soap, '10', '100'], [$rice, '10', '0']]), 'lines', '⛔ লাইনের মোটের বেশি ছাড় — নিট ঋণাত্মক — বসল।');

        // ⓘ একজন কেবল সাবানে সস্তা দর, আরেকজন দুটোতেই — মোটে প্রথমজন কম, কিন্তু তাঁর দর পুরো দরপত্রের নয়
        $this->quote($rfq, $partial, [[$soap, '1', '0']]);
        $this->quote($rfq, $whole, [[$soap, '20', '0'], [$rice, '20', '0']]);

        $lowest = collect($service->compare(Rfq::query()->findOrFail($rfq->id)))->firstWhere('lowest', true);
        $this->assertNotNull($lowest, 'কেউই সবচেয়ে কম দাগ পেলেন না।');
        $this->assertSame((int) $whole->id, (int) $lowest['supplier']->id, '⛔ এক পণ্যের সস্তা দর পুরো দরপত্রের "সবচেয়ে কম" হলো।');
    }

    /** @param  list<array{Product, string, string}>  $rows */
    private function quote(Rfq $rfq, Supplier $supplier, array $rows): void
    {
        app(RfqService::class)->quote(
            ['rfq_id' => $rfq->id, 'supplier_id' => $supplier->id, 'quoted_on' => now()->toDateString()],
            array_map(fn (array $r) => ['product_id' => $r[0]->id, 'qty' => '5', 'rate' => $r[1], 'discount' => $r[2], 'tax' => '0'], $rows),
        );
    }

    private function refused(\Closure $act, string $field, string $why): void
    {
        try {
            $act();
            $this->fail($why);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), 'অন্য কারণে থামল।');
        }
    }
}

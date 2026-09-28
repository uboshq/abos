<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DeliveryChallanService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বিল হয়ে যাওয়া চালানেও "এই চালানের বিল করুন" — লাইভের যাচাইয়ে ধরা (কোঅর্ডিনেটর, ২৯ সেপ্টেম্বর ২০২৬)।
 *
 * ── ⛔ আগে ─────────────────────────────────────────────────────────────
 * পাতা বোতামটা দেখাত যতক্ষণ চালান পাকা — বিল হোক বা না হোক। আর দরজা ([[SalesInvoiceRequest]])
 * কেবল দেখত `delivery_challan_id` ঘরটা ভরা কি না: সারিগুলো ঐ চালানের কি না দেখত না, তাই
 * চালানের সারির যোগ ছাড়া একটা হাতে বানানো POST একই চালানে দ্বিতীয় বিল বানিয়ে ফেলত।
 * ⚠️ আর উল্টো দিকে, আসল ফর্মটা ঘরটা পাঠাতই না — চালান থেকে খোলা ফর্ম জমা দিলে ৪০৩।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * বিল থাকলে বোতামের জায়গায় বিলের লিংক (বাকি থাকলে দুইটাই); চালান ধরে জমা দেওয়া বিলের প্রতিটা
 * সারি ঐ চালানেরই সারি; বাকি না থাকলে দরজা বিলের নম্বর বলে ফেরায়; ফর্ম চালানটা সাথে নেয়।
 */
final class TheChallanWasBilledTwiceTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /** ⛔→⭐ একই চালানের পাতা: বিলের আগে বোতাম, বিলের পরে বোতাম নেই — বিলের লিংক। */
    public function test_a_billed_challan_shows_its_bill_instead_of_the_button(): void
    {
        $challan = $this->confirmedChallan();

        $this->get(route('sales.challan.show', $challan))
            ->assertOk()
            ->assertSee(__('sales::action.invoice_against'))
            ->assertDontSee('data-challan-bills', false);

        $this->post(route('sales.invoice.store'), $this->billOf($challan))->assertSessionHasNoErrors();
        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();

        $this->get(route('sales.challan.show', $challan))
            ->assertOk()
            ->assertDontSee(__('sales::action.invoice_against'))
            ->assertSee('data-challan-bills', false)
            ->assertSee($invoice->document_no)
            ->assertSee(route('sales.invoice.show', $invoice), false);
    }

    /**
     * ⛔ একই চালানে দুইবার চেষ্টা — প্রথমটা বিল, দ্বিতীয়টা ফেরত; আর সারির যোগ ছাড়া হাতে বানানো
     * POST-ও ফেরত। বিলের সংখ্যা এক-ই থাকে।
     */
    public function test_a_second_bill_on_the_same_challan_is_refused_at_the_door(): void
    {
        $challan = $this->confirmedChallan();
        $before = SalesInvoice::query()->count();

        $this->post(route('sales.invoice.store'), $this->billOf($challan))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($before + 1, SalesInvoice::query()->count(), '⛔ প্রথম বিলটাই হয়নি — দাবিটা কিছু মাপছে না।');

        $this->post(route('sales.invoice.store'), $this->billOf($challan))
            ->assertSessionHasErrors('delivery_challan_id');
        $this->assertSame($before + 1, SalesInvoice::query()->count(), '⛔ একই চালানে দ্বিতীয় বিল হয়ে গেছে।');

        // ⛔ চালানের সারির যোগ মুছে — দরজা যেন কেবল ঘরটা ভরা দেখে ছেড়ে না দেয়
        $crafted = $this->billOf($challan);
        unset($crafted['lines'][0]['delivery_challan_line_id']);

        $this->post(route('sales.invoice.store'), $crafted)->assertSessionHasErrors();
        $this->assertSame($before + 1, SalesInvoice::query()->count(), '⛔ সারির যোগ ছাড়া POST দ্বিতীয় বিল বানিয়েছে।');
    }

    /** ⛔ বিল না হওয়া চালানেও: সারির যোগ ছাড়া, বা অন্য চালানের সারি নিয়ে, বিল হয় না। */
    public function test_a_bill_against_a_challan_carries_only_that_challans_lines(): void
    {
        $challan = $this->confirmedChallan();
        $other = $this->confirmedChallan();
        $before = SalesInvoice::query()->count();

        $unlinked = $this->billOf($challan);
        unset($unlinked['lines'][0]['delivery_challan_line_id']);
        $this->post(route('sales.invoice.store'), $unlinked)->assertSessionHasErrors('lines');

        $foreign = $this->billOf($challan);
        $foreign['lines'][0]['delivery_challan_line_id'] = $other->lines()->value('id');
        $this->post(route('sales.invoice.store'), $foreign)->assertSessionHasErrors('lines');

        $this->assertSame($before, SalesInvoice::query()->count());
    }

    /** ⭐ চালান থেকে খোলা ফর্ম চালানটা সাথে নেয়; আর বিল হয়ে গেলে ফর্ম না খুলে চালানে ফেরায়। */
    public function test_the_form_carries_its_challan_and_a_billed_challan_sends_you_back(): void
    {
        $challan = $this->confirmedChallan();

        $this->get(route('sales.invoice.create', ['delivery_challan_id' => $challan->id]))
            ->assertOk()
            ->assertSee('name="delivery_challan_id" value="'.$challan->id.'"', false);

        $this->post(route('sales.invoice.store'), $this->billOf($challan))->assertSessionHasNoErrors();

        $this->get(route('sales.invoice.create', ['delivery_challan_id' => $challan->id]))
            ->assertRedirect(route('sales.challan.show', $challan))
            ->assertSessionHasErrors('delivery_challan_id');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function confirmedChallan(): DeliveryChallan
    {
        $biscuit = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();

        $challan = app(DeliveryChallanService::class)->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'own_transport' => true,
        ], [['product_id' => $biscuit->id, 'delivered_qty' => '2', 'rate' => '10']]);

        return app(DeliveryChallanService::class)->confirm($challan);
    }

    /** ফর্ম যা পাঠায় — চালান, আর প্রতি সারিতে চালানের সারির যোগ। */
    private function billOf(DeliveryChallan $challan): array
    {
        $line = $challan->lines()->firstOrFail();

        return [
            'delivery_challan_id' => $challan->id,
            'customer_id' => $challan->customer_id,
            'warehouse_id' => $challan->warehouse_id,
            'trx_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $line->product_id,
                'delivery_challan_line_id' => $line->id,
                'qty' => '2',
                'rate' => '10',
            ]],
        ];
    }
}

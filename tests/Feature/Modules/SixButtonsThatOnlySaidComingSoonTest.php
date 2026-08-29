<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * সরাসরি বিক্রয়ের ছয়টা বোতাম — আর ছয়টাই সত্যি।
 *
 * ── কী ছিল, ২৯ আগস্ট ২০২৬ পর্যন্ত ────────────────────────────────────
 * পর্দায় ছয়টা বোতাম বসানো ছিল — Chart / Bulk DO · Expense ·
 * Transportation · Shipment · Add Deposit · Add Note। চারটা চাপলে একটা
 * হলুদ বার্তা আসত: "আসছে"। দুইটা কেবল ডান পাশের একটা ঘরে ফোকাস করত।
 *
 * জায়গা ধরে রাখার যুক্তিটা খারাপ ছিল না, আর চুপচাপ কিছু-না-করার চেয়ে
 * "আসছে" বলা সৎ। কিন্তু মালিক নাম ধরে ছয়টাই চেয়েছেন, আর নিয়ম হলো
 * স্টাব নয়।
 *
 * ── কেন পরীক্ষাটা "বোতামটা আছে" দেখে থামে না ─────────────────────────
 * বোতাম আগেও ছিল। ছিল না তার পেছনের ঘর, আর ঘর থাকলেও সেটা সার্ভারে
 * পৌঁছানো। তাই প্রতিটা দাবি শেষ হয় ডাটাবেজে: চালানটা খুলে দেখা হয়
 * লেখা জিনিসটা সত্যিই বসেছে কি না।
 */
class SixButtonsThatOnlySaidComingSoonTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->customer = Customer::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->firstOrFail();
    }

    private function markup(): string
    {
        return (string) $this->actingAs($this->user)
            ->get(route('sales.direct.create'))->getContent();
    }

    /**
     * ছয়টা বোতামই আছে, আর একটাও "আসছে" বলে না।
     *
     * পুরনো আচরণটা একটা Alpine ঘর (`upcoming`) আর একটা হলুদ বার্তায়
     * বসত। ওই দুইটার একটাও যেন ফিরে না আসে — কারণ ফিরলে বোতামটা
     * দেখতে একই থাকবে, কেবল আবার কিছু করবে না।
     */
    public function test_none_of_the_six_still_says_coming_soon(): void
    {
        $html = $this->markup();

        foreach (['chart_bulk_do', 'expense', 'transportation', 'shipment', 'add_deposit', 'add_note'] as $key) {
            $this->assertStringContainsString(__('sales::action.'.$key), $html,
                "বোতামটাই নেই: {$key}");
        }

        $this->assertStringNotContainsString('upcoming', $html, implode("\n", [
            '"আসছে" বার্তাটা ফিরে এসেছে।',
            '',
            'একটা বোতাম যেটা চাপা যায় অথচ কিছুই করে না — সেটাই সবচেয়ে',
            'খারাপ স্টাব, আর মালিক নাম ধরে ছয়টাই চেয়েছিলেন।',
        ]));
    }

    /**
     * প্রতিটা বোতামের পেছনে সত্যিকারের ঘর আছে।
     */
    public function test_every_button_opens_a_panel_with_real_fields(): void
    {
        $html = $this->markup();

        $fields = [
            /*
             * শীটটা নিজের কম্পোনেন্ট — চালানের ফর্মেও একই।
             *
             * প্রথমে এখানে একটা আলাদা ছোট শিট লেখা হয়েছিল, আর মালিক
             * DMS-এরটা দেখতে বলায় ধরা পড়ল ABOS-এ ওটা আগে থেকেই আছে।
             * দুইটা শিট মানে দুই জায়গায় একই অঙ্ক; নকলটা মুছে আসলটাই
             * বসানো হয়েছে, আর সারিগুলো আসে `bulk-applied` ইভেন্টে।
             */
            'চার্ট / বাল্ক DO' => 'absorbBulk($event.detail.rows)',
            'খরচ' => 'name="expense_narration"',
            'পরিবহন' => 'name="transport_cost"',
            'চালান কোথায়' => 'name="ship_to"',
            'জমা' => 'name="deposit_method"',
            'মন্তব্য' => 'name="narration"',
        ];

        $missing = [];

        foreach ($fields as $what => $needle) {
            if (! str_contains($html, $needle)) {
                $missing[] = "{$what} — {$needle} নেই";
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'বোতাম আছে, পেছনে ঘর নেই:',
            ...$missing,
        ]));
    }

    /**
     * আর যা লেখা হয় তা সত্যিই চালানে বসে।
     *
     * ── কেন দাবিটা ডাটাবেজ পর্যন্ত যায় ───────────────────────────────
     * ঘর থাকা আর ঘরটা কাজ করা দুই জিনিস। একটা `name` ভুল লিখলে পর্দায়
     * সব ঠিক দেখাত, ব্যবহারকারী টাইপ করতেন, সেভ হত — আর লেখা জিনিসটা
     * নীরবে হারিয়ে যেত। ওটা ধরার একমাত্র জায়গা এখানে।
     */
    public function test_what_the_panels_collect_is_what_the_challan_keeps(): void
    {
        $this->actingAs($this->user)->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => '2', 'rate' => '100']],

            'expense_amount' => '200',
            'expense_narration' => 'হাম্মালি',

            'carrier_name' => 'করিম পরিবহন',
            'transport_cost' => '450',
            'vehicle_no' => 'ঢাকা মেট্রো ব ১১-২২৩৩',
            'driver_name' => 'রফিক',

            'ship_to' => 'চরপাড়া বাজার',
            'ship_date' => now()->addDay()->toDateString(),

            'deposit' => '500',
            'deposit_method' => 'cheque',
            'deposit_ref' => 'CHQ-77',

            'narration' => 'সকালে পাঠাতে হবে',
        ])->assertRedirect();

        $challan = DeliveryChallan::query()->latest('id')->firstOrFail();

        $this->assertSame('হাম্মালি', $challan->expense_narration);
        $this->assertSame('করিম পরিবহন', $challan->carrier_name);
        $this->assertSame(0, bccomp((string) $challan->transport_cost, '450', 4));
        $this->assertSame('ঢাকা মেট্রো ব ১১-২২৩৩', $challan->vehicle_no);
        $this->assertSame('রফিক', $challan->driver_name);
        $this->assertSame('চরপাড়া বাজার', $challan->ship_to);
        $this->assertSame('cheque', $challan->deposit_method);
        $this->assertSame('CHQ-77', $challan->deposit_ref);
        $this->assertSame('সকালে পাঠাতে হবে', $challan->narration);
    }

    /**
     * টাকা না নিলে জমার ধরনও লেখা হয় না।
     *
     * বাছাইয়ের ঘরটার ডিফল্ট "নগদ", তাই ওটা চুপচাপ প্রতিটা চালানে বসে
     * যেত — আর রিপোর্টে হাজারটা শূন্য টাকার নগদ জমা দেখা যেত, যার
     * একটাও ঘটেনি।
     */
    public function test_no_money_means_no_method(): void
    {
        $this->actingAs($this->user)->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => '1', 'rate' => '50']],
            'deposit' => '0',
            'deposit_method' => 'cash',
        ])->assertRedirect();

        $this->assertNull(DeliveryChallan::query()->latest('id')->firstOrFail()->deposit_method);
    }

    /**
     * খরচের অঙ্ক বসালে কারণটাও চাওয়া হয়।
     *
     * "খরচ ২০০" এক মাস পরে কারও কাজে আসে না। জানার একমাত্র সময় এখনই,
     * যখন যিনি টাকাটা দিয়েছেন তিনি সামনেই দাঁড়ানো।
     */
    public function test_an_expense_without_a_reason_is_refused(): void
    {
        $this->actingAs($this->user)->post(route('sales.direct.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => '1', 'rate' => '50']],
            'expense_amount' => '300',
        ])->assertSessionHasErrors('expense_narration');
    }

    /**
     * দুইটা নতুন প্যানেলেরও নিজের সুইচ আছে (নিয়ম ৭)।
     *
     * যে ডিপো কাউন্টার থেকে হাতে হাতে মাল দেয় তার কাছে গাড়ি বা
     * ঠিকানার মানে নেই — প্রতিটা চালানে দুইটা বোতাম পার করতে হত।
     */
    public function test_the_two_new_panels_can_be_switched_off(): void
    {
        $settings = app(SettingsService::class);

        foreach (['sales.field_transport' => 'name="transport_cost"',
            'sales.field_shipment' => 'name="ship_to"'] as $key => $needle) {
            $settings->set($key, true);
            $settings->flush();
            $this->assertStringContainsString($needle, $this->markup(), "খোলা থাকলেও নেই: {$key}");

            $settings->set($key, false);
            $settings->flush();
            $this->assertStringNotContainsString($needle, $this->markup(), "বন্ধ করেও রয়ে গেছে: {$key}");

            $settings->set($key, true);
            $settings->flush();
        }
    }
}

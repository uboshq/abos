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
use App\Modules\Inventory\Services\PackConversion;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * পর্দা থেকে খাতা পর্যন্ত — প্যাকটা সত্যিই পৌঁছায় কিনা।
 *
 * ── কেন এই টেস্টটা আলাদা করে দরকার ───────────────────────────────────
 * ইঞ্জিন ঠিক, সার্ভিস ঠিক, ফর্মে ড্রপডাউনও আছে — তবু কিছুই হত না, কারণ
 * FormRequest-এ `lines.*.unit_id` নিয়মটা ছিল না আর validated() ঘরটা
 * নীরবে ফেলে দিত। ব্যবহারকারী "বাক্স" বাছতেন, ফর্ম জমা হত, সবুজ বার্তা
 * আসত, আর মাল যেত পিস হিসেবে — একশো ভাগের এক ভাগ।
 *
 * তাই এখানে সত্যিকারের HTTP অনুরোধ যায়, আর ফেরত পড়া হয় ডেটাবেজ থেকে।
 */
class PackEntryScreenTest extends TestCase
{
    use RefreshDatabase;

    private Unit $piece;

    private Unit $box;

    private Product $product;

    private Warehouse $warehouse;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->piece = Unit::query()->where('code', 'PCS')->firstOrFail();

        $strip = Unit::query()->create([
            'code' => 'PATA', 'name_en' => 'Strip', 'name_bn' => 'পাতা',
            'base_unit_id' => $this->piece->id, 'factor' => '10', 'is_active' => true,
        ]);
        $this->box = Unit::query()->create([
            'code' => 'BOX', 'name_en' => 'Box', 'name_bn' => 'বাক্স',
            'base_unit_id' => $strip->id, 'factor' => '10', 'is_active' => true,
        ]);

        $this->product = Product::query()->firstOrFail();
        $this->product->forceFill(['unit_id' => $this->piece->id])->save();

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->customer = Customer::query()->firstOrFail();
    }

    private function switchOn(bool $on): void
    {
        app(SettingsService::class)->set('inventory.pack_entry_enabled', $on);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function postInvoice(array $line)
    {
        return $this->post(route('sales.invoice.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'trx_date' => now()->toDateString(),
            'lines' => [$line],
        ]);
    }

    // ── পর্দা থেকে খাতা ──────────────────────────────────────────

    /**
     * পর্দায় "২ বাক্স" বেছে জমা দিলে খাতায় ২০০ পিস বসে।
     *
     * এটাই সেই পরীক্ষা যা না থাকলে পুরো সুবিধাটা অনুপস্থিত থেকেও
     * উপস্থিত মনে হত।
     */
    public function test_a_pack_chosen_on_the_screen_reaches_the_books(): void
    {
        $this->switchOn(true);

        $this->postInvoice([
            'product_id' => $this->product->id,
            'qty' => '2',
            'rate' => '800',
            'unit_id' => $this->box->id,
        ])->assertRedirect();

        $line = SalesInvoice::query()->latest('id')->firstOrFail()->lines->first();

        $this->assertSame(0, bccomp('200', (string) $line->qty, 4), 'পরিমাণ পিসে নামেনি');
        $this->assertSame(0, bccomp('8', (string) $line->rate, 4), 'দর পিসে নামেনি');
        $this->assertSame($this->box->id, (int) $line->entered_unit_id);
    }

    /** একক ছাড়া জমা দিলে আগের মতোই — পুরনো পর্দা কিছু টের পায় না। */
    public function test_a_line_without_a_unit_still_posts(): void
    {
        $this->switchOn(true);

        $this->postInvoice([
            'product_id' => $this->product->id,
            'qty' => '3',
            'rate' => '100',
        ])->assertRedirect();

        $line = SalesInvoice::query()->latest('id')->firstOrFail()->lines->first();

        $this->assertSame(0, bccomp('3', (string) $line->qty, 4));
        $this->assertNull($line->entered_unit_id);
    }

    /**
     * অন্য কোম্পানির একক দিয়ে জমা দেওয়া যায় না।
     *
     * ড্রপডাউনে ওটা কখনো আসে না, কিন্তু অনুরোধটা হাতে বানানো যায় — আর
     * তখন এক কোম্পানির সিঁড়ি দিয়ে অন্য কোম্পানির মাল গোনা হত।
     */
    public function test_another_companys_unit_is_refused(): void
    {
        $this->switchOn(true);

        $other = Company::query()->where('code', '!=', 'TDEPOT')->firstOrFail();

        $stranger = CompanyContext::forCompany($other->id, fn () => Unit::query()->create([
            'code' => 'FOREIGN', 'name_en' => 'Foreign', 'name_bn' => 'ভিনদেশি',
            'factor' => '1', 'is_active' => true,
        ]));

        $this->postInvoice([
            'product_id' => $this->product->id,
            'qty' => '1',
            'rate' => '100',
            'unit_id' => $stranger->id,
        ])->assertSessionHasErrors('lines.0.unit_id');
    }

    // ── সুইচ ─────────────────────────────────────────────────────

    /** সুইচ চালু থাকলে ফর্মে এককের ঘরটা থাকে। */
    public function test_the_unit_column_appears_when_the_switch_is_on(): void
    {
        $this->switchOn(true);

        $this->get(route('sales.invoice.create'))
            ->assertOk()
            ->assertSee('lines[${i}][unit_id]', escape: false);
    }

    /**
     * সুইচ বন্ধ থাকলে ঘরটাই আসে না।
     *
     * যে ব্যবসা এক এককে বেচে, তার প্রতিটা সারিতে একটা বাড়তি ড্রপডাউন
     * কেবল টাইপিং বাড়াত।
     */
    public function test_the_unit_column_is_absent_when_the_switch_is_off(): void
    {
        $this->switchOn(false);

        $this->get(route('sales.invoice.create'))
            ->assertOk()
            ->assertDontSee('lines[${i}][unit_id]', escape: false);
    }

    /**
     * সুইচ বন্ধ করলেও আগে লেখা প্যাক খাতায় থেকে যায়।
     *
     * সুইচটা কেবল পর্দার। মুছে ফেললে পুরনো চালানগুলো হঠাৎ অন্য সংখ্যা
     * দেখাত, আর ছাপা কাগজের সাথে পর্দার হিসাব মিলত না।
     */
    public function test_switching_off_does_not_rewrite_history(): void
    {
        $this->switchOn(true);

        $this->postInvoice([
            'product_id' => $this->product->id,
            'qty' => '2',
            'rate' => '800',
            'unit_id' => $this->box->id,
        ])->assertRedirect();

        $this->switchOn(false);

        $line = SalesInvoice::query()->latest('id')->firstOrFail()->lines->first();

        $this->assertSame($this->box->id, (int) $line->entered_unit_id);
        $this->assertSame(0, bccomp('200', (string) $line->qty, 4));
    }

    // ── তালিকা ───────────────────────────────────────────────────

    /**
     * এক এককের পণ্যে ড্রপডাউন আসে না।
     *
     * একটামাত্র বিকল্পের তালিকা পর্দায় শুধু জায়গা নেয়, আর ব্যবহারকারীকে
     * ভাবায় "এখানে কি কিছু বাছতে হবে"।
     */
    public function test_a_product_with_one_unit_gets_no_dropdown(): void
    {
        $lonely = Unit::query()->create([
            'code' => 'SOLO', 'name_en' => 'Solo', 'name_bn' => 'একলা',
            'factor' => '1', 'is_active' => true,
        ]);

        $this->product->forceFill(['unit_id' => $lonely->id])->save();

        $options = app(PackConversion::class)
            ->optionsFor([$this->product->fresh()]);

        $this->assertArrayNotHasKey($this->product->id, $options);
    }
}

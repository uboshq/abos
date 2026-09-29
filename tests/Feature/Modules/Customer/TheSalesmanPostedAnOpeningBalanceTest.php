<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Services\RoleTemplateRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * মাঠের বিক্রয়কর্মী নতুন গ্রাহকের শুরুর বাকি সরাসরি খাতায় বসাতে পারতেন — গভীর অডিট, ২৯ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ আগে ─────────────────────────────────────────────────────────────
 * গ্রাহক বানানোর চাবি (`customer.create`, Field Sales ছাঁচেও আছে) দিয়েই যেকোনো অঙ্কের, যেকোনো চিহ্নের,
 * যেকোনো তারিখের শুরুর বাকি খাতায় বসত ([[CustomerService::create()]] → পাওনা বনাম জমা লাভ) — কোনো সই
 * ছাড়া। মালিকের নিয়ম: যেকোনো টাকায় মানুষের সিদ্ধান্ত।
 *
 * ── ⭐ এখন ─────────────────────────────────────────────────────────────
 * শুরুর বাকির নিজের চাবি `customer.opening_balance` — ছাঁচে কেবল হিসাবরক্ষকের (মালিক/সুপার অ্যাডমিন
 * সব চাবিই পান)। চাবি ছাড়া ঘরটা পর্দায় নেই, আর পাঠালেও **সেবা ফেরত দেয়** (উপেক্ষা নয় — চুপচাপ শূন্য
 * করলে লোকটা ভাবতেন বসেছে): ফর্ম, ইমপোর্ট, যেকোনো পথ একই জায়গা দিয়ে যায়। সম্পাদনায় শুরুর বাকি আগের
 * মতোই বদলায় না (বদলাতে জাবেদা লাগে)।
 */
final class TheSalesmanPostedAnOpeningBalanceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $salesman;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->salesman = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->salesman->companies()->attach($this->company->id, ['is_active' => true]);
        $this->salesman->givePermissionTo(['customer.view', 'customer.create']);
    }

    /** ⛔→⭐ একই মানুষ: চাবি ছাড়া শুরুর বাকি ফেরত যায়, কিছুই খাতায় বসে না; চাবিতে বসে। */
    public function test_an_opening_balance_needs_its_own_key(): void
    {
        $ledger = LedgerEntry::query()->count();

        $this->actingAs($this->salesman->fresh())->post(route('customer.store'), $this->form('Opening Probe A', '500000'))
            ->assertSessionHasErrors('opening_balance');

        $this->assertFalse(Customer::query()->where('name_en', 'Opening Probe A')->exists(), '⛔ চাবি ছাড়া গ্রাহকটা শুরুর বাকিসহ তৈরি হয়ে গেছে।');
        $this->assertSame($ledger, LedgerEntry::query()->count(), '⛔ চাবি ছাড়া শুরুর বাকি খাতায় বসেছে।');

        $this->salesman->givePermissionTo(Permission::findOrCreate('customer.opening_balance', 'web'));

        $this->actingAs($this->salesman->fresh())->post(route('customer.store'), $this->form('Opening Probe B', '500000'))
            ->assertSessionHasNoErrors();

        $customer = Customer::query()->where('name_en', 'Opening Probe B')->firstOrFail();
        $this->assertSame(0, bccomp((string) $customer->outstanding(), '500000', 4), 'চাবিতে শুরুর বাকি খাতায় বসেনি।');
    }

    /** ⭐ চাবি ছাড়াও শূন্য শুরুর বাকিতে গ্রাহক বানানো চলে — কাজটা আটকায় না, কেবল টাকাটা। */
    public function test_a_customer_without_an_opening_balance_needs_no_key(): void
    {
        $this->actingAs($this->salesman->fresh())->post(route('customer.store'), $this->form('Opening Probe C', '0'))
            ->assertSessionHasNoErrors();

        $this->assertTrue(Customer::query()->where('name_en', 'Opening Probe C')->exists());
    }

    /** ⛔ ইমপোর্ট বা যেকোনো পথ — পাহারা সেবায়, তাই দরজা ঘুরে এড়ানো যায় না। */
    public function test_every_path_goes_through_the_same_guard(): void
    {
        $this->actingAs($this->salesman->fresh());

        try {
            app(CustomerService::class)->create([
                'name_en' => 'Opening Probe D',
                'name_bn' => 'শুরুর বাকি পরীক্ষা ঘ',
                'opening_balance' => '-250000',
                // ⓘ আজকের তারিখ — পেছনের তারিখের নিজের দেয়াল (৭ দিন) আছে, সেটা এই দাবির বিষয় নয়
                'opening_date' => now()->toDateString(),
            ]);
            $this->fail('⛔ চাবি ছাড়া সেবার পথে শুরুর বাকি বসে গেছে।');
        } catch (ValidationException $e) {
            // ⓘ ঠিক শুরুর বাকির কারণে — অন্য বাধা (নাম, ডুপ্লিকেট) এই দাবি সবুজ করতে পারে না
            $this->assertArrayHasKey('opening_balance', $e->errors(), 'অন্য কারণে থেমেছে: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }
    }

    /** ⭐ চাবি ছাড়া ফর্মে শুরুর বাকির ঘরই নেই; চাবিতে আছে। */
    public function test_the_form_shows_the_opening_box_only_with_the_key(): void
    {
        $this->actingAs($this->salesman->fresh())->get(route('customer.create'))->assertOk()
            ->assertDontSee('name="opening_balance"', false);

        $this->salesman->givePermissionTo(Permission::findOrCreate('customer.opening_balance', 'web'));

        $this->actingAs($this->salesman->fresh())->get(route('customer.create'))->assertOk()
            ->assertSee('name="opening_balance"', false);
    }

    /** ⭐ ছাঁচে চাবিটা হিসাবরক্ষকের — মাঠের বিক্রয়কর্মীর নয়। */
    public function test_the_key_belongs_to_the_accountant_not_to_field_sales(): void
    {
        $templates = app(RoleTemplateRegistry::class)->all();

        $this->assertContains('customer.opening_balance', $templates['Accountant'] ?? [], '⛔ হিসাবরক্ষকের ছাঁচে চাবিটা নেই।');
        $this->assertNotContains('customer.opening_balance', $templates['Field Sales'] ?? [], '⛔ মাঠের বিক্রয়কর্মীর ছাঁচে চাবিটা আছে।');
    }

    /** @return array<string, string> */
    private function form(string $name, string $opening): array
    {
        return [
            'name_en' => $name,
            'opening_balance' => $opening,
            'opening_date' => now()->toDateString(),
        ];
    }
}

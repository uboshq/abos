<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\CustomFieldService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * নিজস্ব ঘর — কোম্পানি নিজে যোগ করে।
 *
 * ── কেন এটা দরকার ছিল ───────────────────────────────────────────────
 * রাখার জায়গা না থাকলে মানুষ "রুট নম্বর" লিখে রাখেন বিবরণের ঘরে। তখন
 * ওটা দিয়ে খোঁজা যায় না, রিপোর্টে আসে না, আর দুইজন দুই বানানে লেখেন —
 * "রুট ৭", "route-7", "R7"। তিনটাই একই রুট, অথচ তালিকা তিনটা।
 */
class CustomFieldTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);

        $this->customer = Customer::query()->orderBy('id')->firstOrFail();
    }

    /**
     * একটা ঘর বানিয়ে তাতে মান রাখা যায়, আর সেটা ফিরে পাওয়া যায়।
     */
    public function test_a_company_can_add_its_own_field_and_fill_it(): void
    {
        $this->field(key: 'route_no', type: 'text');

        $this->fields()->save($this->customer, ['route_no' => '৭']);

        $this->assertSame(['route_no' => '৭'], $this->fields()->valuesFor($this->customer));
    }

    /**
     * ঘরটা যেই জিনিসের, কেবল সেখানেই দেখা যায়।
     *
     * গ্রাহকের ঘর পণ্যের ফর্মে এলে ফর্মটা অর্থহীন হত, আর মানুষ ভুল
     * জায়গায় তথ্য লিখতেন।
     */
    public function test_a_field_belongs_to_one_kind_of_record(): void
    {
        $this->field(key: 'route_no', type: 'text', entity: 'customer');

        $this->assertCount(1, $this->fields()->fieldsFor('customer'));
        $this->assertCount(0, $this->fields()->fieldsFor('product'));
    }

    /**
     * বাধ্যতামূলক ঘর খালি রেখে সংরক্ষণ হয় না।
     */
    public function test_a_required_field_cannot_be_left_empty(): void
    {
        $this->field(key: 'route_no', type: 'text', required: true);

        $this->expectException(ValidationException::class);

        $this->fields()->save($this->customer, ['route_no' => '']);
    }

    /**
     * বাছাইয়ের ঘরে ঘোষিত বিকল্পের বাইরের মান বসে না।
     *
     * না আটকালে ঠিকানায় হাতে লিখে যেকোনো মান পাঠানো যেত, আর রিপোর্টে
     * এমন একটা দল দেখা যেত যেটা কেউ কখনো সংজ্ঞায়িত করেনি।
     */
    public function test_a_choice_outside_the_list_is_refused(): void
    {
        $this->field(key: 'zone', type: 'select', options: ['উত্তর', 'দক্ষিণ']);

        $this->fields()->save($this->customer, ['zone' => 'উত্তর']);
        $this->assertSame(['zone' => 'উত্তর'], $this->fields()->valuesFor($this->customer));

        $this->expectException(ValidationException::class);

        $this->fields()->save($this->customer, ['zone' => 'পূর্ব']);
    }

    /**
     * সংখ্যার ঘরে লেখা বসে না, তারিখের ঘরে আবোল-তাবোল নয়।
     */
    public function test_the_type_is_enforced(): void
    {
        $this->field(key: 'shelf', type: 'number');

        $this->expectException(ValidationException::class);

        $this->fields()->save($this->customer, ['shelf' => 'তিনতলা']);
    }

    /**
     * খালি করে দিলে সারিটাই থাকে না।
     *
     * খালি লেখা জমা রাখলে "কোন ঘরগুলো ভরা" প্রশ্নের উত্তরে খালি সারিও
     * গোনা হত।
     */
    public function test_clearing_a_value_removes_the_row(): void
    {
        $this->field(key: 'route_no', type: 'text');

        $this->fields()->save($this->customer, ['route_no' => '৭']);
        $this->assertSame(1, CustomFieldValue::query()->count());

        $this->fields()->save($this->customer, ['route_no' => '']);
        $this->assertSame(0, CustomFieldValue::query()->count());
    }

    /**
     * ঘর নিষ্ক্রিয় করলে মান হারায় না।
     *
     * ── কেন এটা গুরুত্বপূর্ণ ────────────────────────────────────────
     * ঘরটা মুছে ফেললে ওতে লেখা প্রতিটা রেকর্ডের তথ্যও চলে যেত — আর
     * সেটা এমন তথ্য যা কোম্পানি নিজে দরকারি বলেই যোগ করেছিল। আবার
     * চালু করলে সব ফিরে আসা উচিত।
     */
    public function test_deactivating_a_field_keeps_what_was_written(): void
    {
        $field = $this->field(key: 'route_no', type: 'text');

        $this->fields()->save($this->customer, ['route_no' => '৭']);

        $field->update(['is_active' => false]);

        $this->assertCount(0, $this->fields()->fieldsFor('customer'));
        $this->assertSame(1, CustomFieldValue::query()->count());

        $field->update(['is_active' => true]);

        $this->assertSame(['route_no' => '৭'], $this->fields()->valuesFor($this->customer));
    }

    /**
     * অন্য কোম্পানির ঘর বা মান এই কোম্পানিতে দেখা যায় না।
     */
    public function test_another_companys_fields_stay_invisible(): void
    {
        $this->field(key: 'route_no', type: 'text');

        $other = Company::query()->where('code', '<>', 'TDEPOT')->firstOrFail();

        CompanyContext::forCompany($other->id, function () {
            $this->assertCount(0, app(CustomFieldService::class)->fieldsFor('customer'));
        });
    }

    /**
     * সাজানোর পর্দাটা খোলে, আর নতুন ঘর বসানো যায়।
     */
    public function test_the_settings_screen_creates_a_field(): void
    {
        $this->get(route('system_admin.custom_field.index'))->assertOk();

        $this->post(route('system_admin.custom_field.store'), [
            'entity' => 'customer',
            'key' => 'shop_photo_no',
            'label_en' => 'Shop photo no',
            'label_bn' => 'দোকানের ছবি নম্বর',
            'type' => 'text',
        ])->assertRedirect();

        $this->assertSame(1, CustomField::query()->where('key', 'shop_photo_no')->count());
    }

    /**
     * নতুন ঘরটা গ্রাহকের ফর্মেই দেখা যায়।
     *
     * ── কেন এটা আলাদা করে পরীক্ষা ──────────────────────────────────
     * ঘর বানানো আর ঘর *ব্যবহার করতে পারা* এক জিনিস নয়। সেটিংসে সারি
     * তৈরি হয়েও ফর্মে না এলে ব্যবহারকারীর কাছে ব্যবস্থাটা কাজ করেনি —
     * অথচ প্রতিটা টেস্ট সবুজ থাকত।
     */
    public function test_the_field_shows_up_on_the_customer_form(): void
    {
        $this->field(key: 'route_no', type: 'text', labelBn: 'রুট নম্বর');

        $this->get(route('customer.create'))
            ->assertOk()
            ->assertSee('রুট নম্বর');

        $this->get(route('customer.edit', $this->customer))
            ->assertOk()
            ->assertSee('custom[route_no]', false);
    }

    /**
     * গ্রাহক সংরক্ষণ করলে নিজস্ব ঘরের মানও বসে।
     */
    public function test_saving_a_customer_stores_the_custom_value(): void
    {
        $this->field(key: 'route_no', type: 'text');

        $this->put(route('customer.update', $this->customer), [
            'name_en' => $this->customer->name_en,
            'name_bn' => $this->customer->name_bn,
            'credit_limit' => 0,
            'credit_days' => 0,
            'custom' => ['route_no' => '১২'],
        ])->assertRedirect();

        $this->assertSame(['route_no' => '১২'], $this->fields()->valuesFor($this->customer->fresh()));
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function fields(): CustomFieldService
    {
        return app(CustomFieldService::class);
    }

    /** @param list<string> $options */
    private function field(
        string $key,
        string $type,
        string $entity = 'customer',
        bool $required = false,
        array $options = [],
        string $labelBn = 'নিজস্ব ঘর',
    ): CustomField {
        return CustomField::create([
            'entity' => $entity,
            'key' => $key,
            'label_en' => 'Custom field',
            'label_bn' => $labelBn,
            'type' => $type,
            'options' => $options === [] ? null : $options,
            'is_required' => $required,
            'is_active' => true,
            'sort' => 0,
        ]);
    }
}

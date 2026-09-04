<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\DuplicateGuard;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * এক গ্রাহক, দুইটা সারি, আর অর্ধেক বকেয়া।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * গ্রাহক ও সরবরাহকারীতে অনন্য ছিল কেবল `code` — নাম নয়, ফোন নয়। ফলে
 * "রহিম স্টোর", "রহিম  স্টোর" আর "Rahim Store" তিনটা আলাদা সারি হত,
 * তিনটাতেই আলাদা বকেয়া জমত, আর "রহিম সাহেব মোট কত দেন" প্রশ্নের কোনো
 * সঠিক উত্তর থাকত না।
 *
 * নকল সারি পরে মুছে ফেলাও যায় না — দুইটাতেই বিল ঝুলে থাকে। তাই একমাত্র
 * উপায় ঢোকার সময়েই ঠেকানো।
 *
 * ── ফোন আর নাম কেন আলাদা আচরণ পায় ───────────────────────────────────
 * একই ফোন মানে প্রায় নিশ্চিতভাবে একই মানুষ — আটকানো হয়।
 * একই নাম মানে সেটা নয় — "রহিম স্টোর" নামে দুই বাজারে দুইটা আলাদা দোকান
 * থাকতেই পারে, তাই দেখানো হয়, আটকানো হয় না।
 */
class OneCustomerTwoRowsAndHalfTheDuesTest extends TestCase
{
    use RefreshDatabase;

    private CustomerService $customers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->customers = app(CustomerService::class);
    }

    /** @param  array<string, mixed>  $extra */
    private function customer(string $name, ?string $phone = null, array $extra = []): Customer
    {
        return $this->customers->create([
            'name_en' => $name,
            'phone' => $phone,
            ...$extra,
        ]);
    }

    /* ── ফোনের নানা রূপ এক করে দেখা ─────────────────────────────── */

    public function test_the_same_number_written_three_ways_is_one_number(): void
    {
        $this->assertSame(
            DuplicateGuard::normalisePhone('01712345678'),
            DuplicateGuard::normalisePhone('+8801712345678'),
        );

        $this->assertSame(
            DuplicateGuard::normalisePhone('01712345678'),
            DuplicateGuard::normalisePhone('880 1712-345678'),
        );

        $this->assertNotSame(
            DuplicateGuard::normalisePhone('01712345678'),
            DuplicateGuard::normalisePhone('01712345679'),
        );
    }

    public function test_the_same_name_written_three_ways_is_one_name(): void
    {
        $plain = DuplicateGuard::normaliseName('Rahim Store');

        $this->assertSame($plain, DuplicateGuard::normaliseName('rahim  store'));
        $this->assertSame($plain, DuplicateGuard::normaliseName('M/S. Rahim Store'));
        $this->assertNotSame($plain, DuplicateGuard::normaliseName('Rahim Traders'));
    }

    /**
     * খালি ফোন কখনো নকল নয়।
     *
     * অনেক ছোট দোকানের নম্বর জানা থাকে না, আর তখন সবগুলো খালি ঘর
     * একে অপরের নকল হয়ে গেলে দ্বিতীয় গ্রাহকটাই খোলা যেত না।
     */
    public function test_an_empty_phone_never_counts_as_a_duplicate(): void
    {
        $this->customer('First shop');
        $second = $this->customer('Second shop');

        $this->assertNotNull($second->id);
    }

    /* ── আসল পাহারা ─────────────────────────────────────────────── */

    public function test_the_same_phone_in_another_shape_is_refused(): void
    {
        $this->customer('Rahim Store', '01712345678');

        $this->expectException(ValidationException::class);
        $this->customer('Rahim Enterprise', '+8801712345678');
    }

    public function test_the_same_name_is_shown_but_can_be_overridden(): void
    {
        $this->customer('Rahim Store', '01711111111');

        // প্রথম চেষ্টা আটকায় — ব্যবহারকারী যেন দেখেন
        try {
            $this->customer('rahim  store', '01722222222');
            $this->fail('একই নামে দ্বিতীয় গ্রাহক নীরবে বসে গেছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name_en', $e->errors());
        }

        // জেনেশুনে এগোলে চলে
        $second = $this->customer('rahim  store', '01722222222', ['allow_duplicate' => true]);

        $this->assertNotNull($second->id);
        $this->assertSame(2, Customer::query()
            ->whereIn('phone', ['01711111111', '01722222222'])->count());
    }

    /**
     * নিজের সারি নিজের নকল নয়।
     *
     * এটাই সবচেয়ে সহজে ভুল হওয়ার জায়গা: সম্পাদনার সময় নিজের ফোন
     * নম্বরটাই "আগে থেকে আছে" বলে ধরা পড়ত, আর কেউ আর কোনো গ্রাহক
     * সম্পাদনাই করতে পারত না।
     */
    public function test_editing_a_row_does_not_trip_over_itself(): void
    {
        $customer = $this->customer('Rahim Store', '01712345678');

        $updated = $this->customers->update($customer, [
            'name_en' => 'Rahim Store',
            'phone' => '01712345678',
            'allow_duplicate' => true,
        ]);

        $this->assertSame('01712345678', $updated->phone);
    }

    /** সরবরাহকারীতেও একই পাহারা — নিয়মটা কোরে, তাই দুই জায়গায় এক। */
    public function test_a_supplier_gets_the_same_guard(): void
    {
        $suppliers = app(SupplierService::class);

        $suppliers->create(['name_en' => 'City Traders', 'phone' => '01811111111']);

        $this->expectException(ValidationException::class);
        $suppliers->create(['name_en' => 'City Traders Ltd', 'phone' => '+8801811111111']);
    }

    /**
     * অন্য কোম্পানির গ্রাহক নকল নয়।
     *
     * ABOS বহু-কোম্পানি, আর দুইটা আলাদা ডিপোর দুইটা আলাদা "রহিম স্টোর"
     * থাকা স্বাভাবিক। পাহারাটা কোম্পানি না মানলে দ্বিতীয় কোম্পানি
     * তাদের নিজের গ্রাহকই বসাতে পারত না।
     */
    public function test_another_company_is_not_a_duplicate(): void
    {
        $this->customer('Rahim Store', '01712345678');

        $other = Company::query()->where('code', 'FMART')->firstOrFail();
        CompanyContext::set($other->id, $other->defaultBranch()?->id);

        $twin = $this->customer('Rahim Store', '01712345678');

        $this->assertSame($other->id, $twin->company_id);
    }
}

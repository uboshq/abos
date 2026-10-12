<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Core\Module\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ বাকি নিয়ন্ত্রণের নিজের দরজা — মডিউল, মেনু আর ড্যাশবোর্ড (১১ অক্টোবর ২০২৬)।
 *
 *   · মডিউলটা নিবন্ধিত, গ্রাহক আর বিক্রয়ের মাঝে বসে।
 *   · মালিক ড্যাশবোর্ড খোলেন; চাবি ছাড়া কেউ খুলতে পারেন না (৪০৩, দরজায় — কেবল লুকানো বোতাম নয়)।
 */
final class TheCreditDeskHasItsOwnDoorTest extends TestCase
{
    use CreditControlKit;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCreditCompany();
    }

    public function test_the_module_sits_between_customer_and_sales(): void
    {
        $module = app(ModuleRegistry::class)->get('credit_control');

        $this->assertNotNull($module, 'বাকি নিয়ন্ত্রণ মডিউল নিবন্ধিত নয়।');
        $this->assertSame('business', $module->nav['section']);
        $this->assertGreaterThan(app(ModuleRegistry::class)->get('customer')->nav['order'], $module->nav['order']);
        $this->assertLessThan(app(ModuleRegistry::class)->get('sales')->nav['order'], $module->nav['order']);
    }

    public function test_the_owner_opens_the_dashboard(): void
    {
        $this->get(route('module.dashboard', ['module' => 'credit_control']))
            ->assertOk()
            ->assertSee(__('credit_control::dashboard.title'));
    }

    public function test_nobody_without_the_key_opens_it(): void
    {
        $this->actingAs($this->staffWith(['customer.view']))
            ->get(route('module.dashboard', ['module' => 'credit_control']))
            ->assertForbidden();
    }
}

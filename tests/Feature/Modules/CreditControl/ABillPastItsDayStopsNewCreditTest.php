<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Core\Services\CompanyProvisioner;
use App\Core\Services\PermissionSyncer;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\Setting;
use App\Modules\Sales\Services\CreditExposure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ মেয়াদ পেরোনো বিল নতুন বাকি থামায় — মালিকের উত্তর ১, ১১ অক্টোবর ২০২৬ (বাকি নিয়ন্ত্রণ ধাপ ২.১)।
 *
 * *"কোনো বিল মেয়াদ পেরোলেই নতুন বাকি নয়, সীমায় জায়গা থাকলেও; পুরো টাকা দিলে কেনা চলে"*।
 *
 *   · সুইচ চালু, ছাড় ০: মেয়াদ একদিন পেরোনো বিল → নতুন বাকি বন্ধ, সীমায় অনেক জায়গা থাকলেও (DO-র যাচাই আর দেয়াল দুই পথেই)।
 *   · পুরো টাকা এখনই দিলে কেনা চলে। · মেয়াদ আজ → এখনো চলে। · ছাড় ৩ দিন → ৩ দিনে চলে, ৪ দিনে থামে।
 *   · নতুন কোম্পানি খোলার দিনেই সুইচ চালু, ছাড় ০।
 *   · মাইগ্রেশন: দিন বসানো চলতি কোম্পানির সুইচ চালু লেখে, দিনের মান ছোঁয় না; দিন না বসানো কোম্পানিতে কিছু লেখে না।
 */
final class ABillPastItsDayStopsNewCreditTest extends TestCase
{
    use CreditControlKit;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCreditCompany();

        $settings = app(SettingsService::class);
        $settings->set('customer.overdue_stop', true);
        $settings->set('customer.overdue_block_days', 0);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_a_bill_one_day_past_due_stops_new_credit_even_with_limit_room(): void
    {
        $c = $this->customer('PAST-1', '500000');
        $this->bill($c, 'INV-PAST1', '1000', daysPastDue: 1);

        $result = app(CreditExposure::class)->check($c->fresh(), '500');
        $this->assertFalse($result['fits'], '⛔ মেয়াদ একদিন পেরোনো বিল নিয়ে DO কুলোল — সীমায় জায়গা দেয়াল টপকে গেল।');
        $this->assertStringContainsString('INV-PAST1', (string) $result['reason']);

        try {
            app(CreditExposure::class)->assertRoom($c->fresh(), '500');
            $this->fail('⛔ মেয়াদ পেরোনো বিল থাকতে নতুন বাকি গেল।');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('INV-PAST1', $e->validator->errors()->first('customer_id'));
        }
    }

    public function test_paying_in_full_still_sells(): void
    {
        $c = $this->customer('PAST-2', '500000');
        $this->bill($c, 'INV-PAST2', '1000', daysPastDue: 1);

        app(CreditExposure::class)->assertRoom($c->fresh(), '500', '500');
        $this->addToAssertionCount(1);
    }

    public function test_a_bill_due_today_does_not_stop(): void
    {
        $c = $this->customer('PAST-3', '500000');
        $this->bill($c, 'INV-TODAY', '1000', daysPastDue: 0);

        $this->assertTrue(app(CreditExposure::class)->check($c->fresh(), '500')['fits']);
        app(CreditExposure::class)->assertRoom($c->fresh(), '500');
    }

    public function test_grace_days_wait_before_the_wall(): void
    {
        app(SettingsService::class)->set('customer.overdue_block_days', 3);

        $inside = $this->customer('PAST-4', '500000');
        $this->bill($inside, 'INV-G3', '1000', daysPastDue: 3);
        $this->assertTrue(app(CreditExposure::class)->check($inside->fresh(), '500')['fits'], '⛔ ছাড়ের দিনের ভিতরেই দেয়াল উঠল।');

        $outside = $this->customer('PAST-5', '500000');
        $this->bill($outside, 'INV-G4', '1000', daysPastDue: 4);
        $this->assertFalse(app(CreditExposure::class)->check($outside->fresh(), '500')['fits'], '⛔ ছাড় পেরিয়েও দেয়াল উঠল না।');
    }

    public function test_the_switch_off_lets_it_through(): void
    {
        app(SettingsService::class)->set('customer.overdue_stop', false);

        $c = $this->customer('PAST-6', '500000');
        $this->bill($c, 'INV-OFF', '1000', daysPastDue: 30);

        $this->assertTrue(app(CreditExposure::class)->check($c->fresh(), '500')['fits']);
    }

    public function test_a_new_company_opens_with_the_wall_on_and_no_grace(): void
    {
        $fresh = Company::create(['code' => 'NEWCC', 'name_en' => 'New Credit Co']);
        CompanyContext::forCompany($fresh->id, fn () => app(PermissionSyncer::class)->sync());

        app(CompanyProvisioner::class)->setUp($fresh, [['code' => 'MAIN', 'name_en' => 'Main', 'is_default' => true]], [
            'name' => 'cc-year', 'starts_on' => now()->startOfYear()->toDateString(), 'ends_on' => now()->endOfYear()->toDateString(),
        ]);

        CompanyContext::forCompany($fresh->id, function (): void {
            app(SettingsService::class)->flush();
            $this->assertTrue(app(CreditExposure::class)->overdueStopOn(), '⛔ নতুন কোম্পানিতে মেয়াদের দেয়াল বন্ধ।');
            $this->assertSame(0, app(CreditExposure::class)->overdueDays());
        });
    }

    public function test_the_migration_keeps_every_running_company_as_it_was(): void
    {
        $other = Company::create(['code' => 'OLDCC', 'name_en' => 'Old Credit Co']);

        // ⓘ চলতি দুই কোম্পানি: একটায় ৪৫ দিন বসানো ছিল (দেয়াল চালু), অন্যটায় কিছুই না (দেয়াল বন্ধ)
        DB::table('settings')->where('key', 'customer.overdue_stop')->delete();
        DB::table('settings')->where('key', 'customer.overdue_block_days')->delete();
        DB::table('settings')->insert([
            'company_id' => $this->company->id, 'module' => 'customer', 'key' => 'customer.overdue_block_days',
            'type' => 'integer', 'value' => '45', 'group' => 'entry', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require base_path('app/Modules/Customer/Database/Migrations/2027_03_02_090000_a_bill_past_its_day_could_not_stop_new_credit.php');
        $migration->up();
        $migration->up();

        $this->assertSame('1', (string) Setting::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('key', 'customer.overdue_stop')->value('value'), '⛔ দিন বসানো কোম্পানির দেয়াল মাইগ্রেশনে বন্ধ হয়ে গেল।');
        $this->assertSame(1, Setting::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('key', 'customer.overdue_stop')->count(), '⛔ দুইবার চালালে দুইটা সারি।');
        $this->assertSame('45', (string) Setting::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('key', 'customer.overdue_block_days')->value('value'), '⛔ মাইগ্রেশন দিনের মান বদলেছে।');
        $this->assertFalse(Setting::query()->withoutGlobalScopes()->where('company_id', $other->id)
            ->where('key', 'customer.overdue_stop')->exists(), '⛔ দিন না বসানো কোম্পানিতে দেয়াল চালু হয়ে গেল।');

        // ⓘ কাটার দিন আগের মতোই: ৪৫ দিন
        app(SettingsService::class)->flush();
        $c = $this->customer('PAST-7', '500000');
        $this->bill($c, 'INV-M45', '1000', daysPastDue: 45);
        $this->assertTrue(app(CreditExposure::class)->check($c->fresh(), '500')['fits']);
        $this->bill($c, 'INV-M46', '1000', daysPastDue: 46);
        $this->assertFalse(app(CreditExposure::class)->check($c->fresh(), '500')['fits']);
    }
}

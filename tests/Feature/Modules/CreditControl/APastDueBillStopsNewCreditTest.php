<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\CreditControl;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\Company;
use App\Modules\Sales\Services\CreditExposure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ মেয়াদ পেরোলেই নতুন বাকি বন্ধ — বাকি নিয়ন্ত্রণ ধাপ ২.১ (মালিকের উত্তর ১, ১১ অক্টোবর ২০২৬)।
 *
 * ⓘ মালিক: *"কোনো বিলের মেয়াদ পেরোলেই আর নতুন বাকি নয়, সীমায় জায়গা থাকলেও; পুরো টাকা দিলে কেনা চলবে"*।
 *   · নতুন কোম্পানিতে সুইচ চালু, ছাড় ০ — মেয়াদের এক দিন পরের বিলই থামায়; আজ মেয়াদের বিল থামায় না।
 *   · সীমায় যত জায়গাই থাকুক, থামে; পুরো টাকা দিলে চলে।
 *   · সুইচ বন্ধ → দেয়াল নেই; ছাড়ের দিন বসালে ঠিক ততদিন পরে।
 *   · ⛔ মাইগ্রেশন চলতি কোম্পানির আচরণ বদলায় না: দিন ০ → বন্ধ, দিন X → চালু; `overdue_block_days` ছোঁয়া হয় না।
 */
final class APastDueBillStopsNewCreditTest extends TestCase
{
    use CreditControlKit;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCreditCompany();
    }

    public function test_a_new_company_stops_on_the_due_date_with_no_grace(): void
    {
        $definitions = app(SettingsService::class)->definitions();

        $this->assertTrue($definitions['customer.overdue_stop_enabled']['default']);
        $this->assertSame(0, $definitions['customer.overdue_block_days']['default']);
        $this->assertTrue($definitions['customer.overdue_stop_enabled']['super_admin_only'] ?? false, '⛔ যে কেউ দেয়াল তুলতে পারত।');
    }

    public function test_a_bill_one_day_past_due_stops_new_credit(): void
    {
        $c = $this->customer('PD-ONE', '100000');
        $this->bill($c, 'INV-PD1', '500', daysPastDue: 1);

        $result = app(CreditExposure::class)->check($c->fresh(), '1000');
        $this->assertFalse($result['fits'], '⛔ মেয়াদের এক দিন পরের বিল নিয়ে নতুন বাকি গেছে।');
        $this->assertStringContainsString('INV-PD1', (string) $result['reason']);

        try {
            app(CreditExposure::class)->assertRoom($c->fresh(), '1000');
            $this->fail('⛔ চালান/বিল/কাউন্টারের দেয়াল মেয়াদ পেরোনো বাকিতে নতুন বাকি দিয়েছে।');
        } catch (ValidationException $e) {
            $message = $e->validator->errors()->first('customer_id');
            $this->assertStringContainsString('INV-PD1', $message);
            $this->assertSame(__('sales::credit.overdue_stop_past_due', ['bills' => 'INV-PD1', 'amount' => Money::format('500')]), $message);
        }
    }

    public function test_a_bill_due_today_is_not_yet_past_due(): void
    {
        $c = $this->customer('PD-TODAY', '100000');
        $this->bill($c, 'INV-PD2', '500', daysPastDue: 0);

        $this->assertTrue(app(CreditExposure::class)->check($c->fresh(), '1000')['fits']);
        app(CreditExposure::class)->assertRoom($c->fresh(), '1000');
    }

    public function test_limit_room_does_not_override_it(): void
    {
        $c = $this->customer('PD-ROOM', '10000000');
        $this->bill($c, 'INV-PD3', '10', daysPastDue: 1);

        $this->expectException(ValidationException::class);
        app(CreditExposure::class)->assertRoom($c->fresh(), '1');
    }

    public function test_paying_in_full_still_sells(): void
    {
        $c = $this->customer('PD-CASH', '100000');
        $this->bill($c, 'INV-PD4', '500', daysPastDue: 3);

        app(CreditExposure::class)->assertRoom($c->fresh(), '1000', '1000');
        $this->assertTrue(true);
    }

    public function test_the_switch_off_means_no_wall(): void
    {
        $c = $this->customer('PD-OFF', '100000');
        $this->bill($c, 'INV-PD5', '500', daysPastDue: 200);

        app(SettingsService::class)->set('customer.overdue_stop_enabled', false);

        $this->assertTrue(app(CreditExposure::class)->check($c->fresh(), '1000')['fits']);
        app(CreditExposure::class)->assertRoom($c->fresh(), '1000');
    }

    public function test_grace_days_hold_the_wall_until_they_pass(): void
    {
        $c = $this->customer('PD-GRACE', '100000');
        app(SettingsService::class)->set('customer.overdue_block_days', 5);

        $this->bill($c, 'INV-PD6', '500', daysPastDue: 5);
        $this->assertTrue(app(CreditExposure::class)->check($c->fresh(), '1000')['fits'], '⛔ ছাড়ের ভিতরেই দেয়াল উঠেছে।');

        $this->bill($c, 'INV-PD7', '500', daysPastDue: 6);
        $result = app(CreditExposure::class)->check($c->fresh(), '1000');
        $this->assertFalse($result['fits']);
        $this->assertStringContainsString('INV-PD7', (string) $result['reason']);
        $this->assertStringNotContainsString('INV-PD6', (string) $result['reason']);
    }

    public function test_the_migration_keeps_every_existing_company_as_it_was(): void
    {
        $settings = app(SettingsService::class);

        // ⓘ দুইটা "চলতি" কোম্পানি: একটার দিন ৬০ (দেয়াল ছিল), একটার দিন বসানো নেই (দেয়াল ছিল না)
        $settings->set('customer.overdue_block_days', 60);
        $other = Company::create(['code' => 'PD-OLD', 'name_en' => 'Old Co']);
        DB::table('settings')->where('key', 'customer.overdue_stop_enabled')->delete();
        $daysBefore = DB::table('settings')->where('key', 'customer.overdue_block_days')->orderBy('id')->get(['company_id', 'value'])->map(fn ($r) => (array) $r)->all();

        $migration = require base_path('app/Modules/Customer/Database/Migrations/2027_03_02_100000_overdue_could_not_say_on_the_due_date.php');
        $migration->up();

        $switch = DB::table('settings')->where('key', 'customer.overdue_stop_enabled')->pluck('value', 'company_id');
        $this->assertSame('1', $switch[$this->company->id] ?? null, '⛔ ৬০ দিনের দেয়ালওয়ালা কোম্পানির দেয়াল বন্ধ হয়ে গেছে।');
        $this->assertSame('0', $switch[$other->id] ?? null, '⛔ দেয়াল-ছাড়া কোম্পানিতে মাইগ্রেশন দেয়াল চালু করেছে।');
        $this->assertSame($daysBefore, DB::table('settings')->where('key', 'customer.overdue_block_days')->orderBy('id')->get(['company_id', 'value'])->map(fn ($r) => (array) $r)->all(),
            '⛔ মাইগ্রেশন overdue_block_days বদলেছে।');

        // ⓘ আবার চালালে কিছুই বদলায় না, দুই সারিও হয় না
        DB::table('settings')->where('key', 'customer.overdue_stop_enabled')->where('company_id', $other->id)->update(['value' => '1']);
        $migration->up();
        $this->assertSame('1', DB::table('settings')->where('key', 'customer.overdue_stop_enabled')->where('company_id', $other->id)->value('value'));
        $this->assertSame(1, DB::table('settings')->where('key', 'customer.overdue_stop_enabled')->where('company_id', $other->id)->count());

        // ⓘ দেয়ালের আচরণও আগের মতো: অন্য কোম্পানির পুরনো বিল এখনো থামায় না
        CompanyContext::set($other->id, null);
        $settings->flush();
        DB::table('settings')->where('key', 'customer.overdue_stop_enabled')->where('company_id', $other->id)->update(['value' => '0']);
        $this->assertFalse(app(CreditExposure::class)->overdueStopOn());
    }
}

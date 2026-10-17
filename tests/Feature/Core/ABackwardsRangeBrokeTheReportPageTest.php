<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * শুরুর তারিখ শেষের পরে দিলে বার্তা, ৫০০ নয় (ec, রিপোর্ট লাইন-নিরীক্ষা ⚠️৪, ১৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে ইঞ্জিন সাধারণ RuntimeException ছুড়ত, আর ওয়েবের প্রতিটা রিপোর্ট পর্দা "কিছু ভেঙেছে" দেখাত — কেবল ফোন আগে যাচাই করত।
 */
final class ABackwardsRangeBrokeTheReportPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_start_after_the_end_is_a_message_on_the_web(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->get(route('accounts.report.show', ['slug' => 'ledger', 'from' => '2026-10-20', 'to' => '2026-10-10']))
            ->assertRedirect()
            ->assertSessionHasErrors(['from' => __('validation.report_bad_range')]);

        $this->assertNotSame('validation.report_bad_range', __('validation.report_bad_range'), '⛔ বার্তার চাবির কোনো লেখা নেই।');
    }
}

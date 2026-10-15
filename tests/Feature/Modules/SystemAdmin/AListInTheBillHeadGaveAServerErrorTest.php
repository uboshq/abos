<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বিলের তথ্যের পাতায় হাতে বানানো তালিকা ৫০০ দিত — ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬।
 *
 * ⛔ `settings[sales.print.header.name][]=x` এলে [[InvoiceInfoController::update()]] `(string) array`-এ "Array to string
 * conversion" হয়ে ভাঙত। ⭐ এখন ঘরের নাম ধরে সাধারণ ভুলের লেখা, আর কিছুই বসে না।
 */
final class AListInTheBillHeadGaveAServerErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_list_in_a_bill_head_box_is_refused_with_a_message(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $key = 'sales.print.header.name';
        $before = tap(app(SettingsService::class))->flush()->get($key);

        $this->put(route('system_admin.print_control.invoice_info.update'), [
            'settings' => [$key => ['x']],
        ])->assertRedirect()->assertSessionHasErrors("settings.{$key}");

        $this->assertSame($before, tap(app(SettingsService::class))->flush()->get($key), 'তালিকা এসে বিলের মাথায় কিছু বসে গেল।');
    }
}

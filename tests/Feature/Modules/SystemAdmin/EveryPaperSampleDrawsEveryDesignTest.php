<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Http\Controllers\VoucherPrintController;
use App\Modules\Accounts\Http\Controllers\VoucherSampleController;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Support\VoucherDesigns;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\PaperDesigns;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ছাপার নিয়ন্ত্রণের নকশা-কার্ডের নমুনা — চালান · আদেশ · রসিদ · ভাউচার, প্রতিটা নকশা আর মাপে।
 *
 * মালিক, ৩০ সেপ্টেম্বর ২০২৬: কার্ডে চাপলে পপআপে আসল ছাপা ([[PaperSampleController]], [[VoucherSampleController]])।
 *
 * ── ⚠️ কেন প্রতিটা নকশা, একটা নয় ─────────────────────────────────────────
 * তালিকায় যা আছে সবই কার্ড হয়ে পর্দায় আসে। একটা ছাঁচ ভাঙা থাকলে কেবল ঐ কার্ডটা ফাঁকা বা ৫০০ — আর বাকি ২৩৯টা
 * সবুজ দেখে কেউ ধরতেন না। ⓘ তাই তালিকা থেকেই গোনা, হাতে লেখা নাম নয় ([[never-supply-the-name-yourself]])।
 */
final class EveryPaperSampleDrawsEveryDesignTest extends TestCase
{
    use RefreshDatabase;

    private const SALES = ['challan' => 'sales.challan_sample', 'order' => 'sales.order_sample', 'receipt' => 'sales.receipt_sample'];

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_every_sales_paper_design_draws_and_prints_in_every_size(): void
    {
        $seen = 0;

        foreach (self::SALES as $paper => $route) {
            foreach (PaperDesigns::SIZES as $size) {
                $codes = PaperDesigns::codes($paper, $size);
                $this->assertNotEmpty($codes, "{$paper} · {$size}: তালিকা খালি — কার্ডই আসবে না।");

                foreach ($codes as $code) {
                    $html = $this->get(route($route, ['design' => $code, 'size' => $size]))->assertOk()->getContent();
                    $this->assertStringContainsString('data-signatures', (string) $html, "{$paper} · {$size} · {$code}: নমুনায় সইয়ের ঘর নেই।");

                    $pdf = $this->get(route($route, ['design' => $code, 'size' => $size, 'pdf' => 1]))->assertOk();
                    $this->assertStringStartsWith('%PDF', (string) $pdf->getContent(), "{$paper} · {$size} · {$code}: ?pdf=1-এ PDF আসেনি।");
                    $seen++;
                }
            }
        }

        $this->assertGreaterThan(0, $seen);
    }

    public function test_every_voucher_design_draws_and_prints_in_every_size_and_type(): void
    {
        foreach (VoucherDesigns::SIZES as $size) {
            $codes = VoucherDesigns::codes($size);
            $this->assertNotEmpty($codes, "ভাউচার · {$size}: তালিকা খালি।");

            foreach ($codes as $i => $code) {
                // ⓘ চার ধরন পালা করে — প্রতিটা নকশা অন্তত একটা ধরনে, প্রতিটা ধরন অনেক নকশায়
                $type = VoucherSampleController::TYPES[$i % 4];
                $html = $this->get(route('accounts.voucher.sample', ['design' => $code, 'size' => $size, 'type' => $type]))->assertOk()->getContent();
                $this->assertStringContainsString('data-signatures', (string) $html, "ভাউচার · {$size} · {$code} · {$type}: সইয়ের ঘর নেই।");

                $pdf = $this->get(route('accounts.voucher.sample', ['design' => $code, 'size' => $size, 'type' => $type, 'pdf' => 1]))->assertOk();
                $this->assertStringStartsWith('%PDF', (string) $pdf->getContent(), "ভাউচার · {$size} · {$code}: PDF আসেনি।");
            }
        }
    }

    public function test_an_unknown_design_falls_back_to_the_first_in_the_list(): void
    {
        foreach (self::SALES as $route) {
            $this->get(route($route, ['design' => 'no_such_design', 'size' => 'bogus']))->assertOk();
        }
        $this->get(route('accounts.voucher.sample', ['design' => 'no_such_design', 'type' => 'bogus']))->assertOk();
    }

    /**
     * ⛔ নমুনা কোনো কাগজ বানায় না — নিয়ন্ত্রণ পাতায় কার্ড দেখতে গিয়ে খাতায় একটা চালান-ভাউচার ঢুকে গেলে সেটা
     * আসল হিসাবে গোনা হত।
     */
    public function test_a_sample_writes_no_paper(): void
    {
        $count = fn () => [SalesOrder::query()->count(), Collection::query()->count(), DeliveryChallan::query()->count(), Voucher::query()->count()];
        $before = $count();

        foreach (self::SALES as $route) {
            $this->get(route($route, ['pdf' => 1]))->assertOk();
        }
        foreach (VoucherSampleController::TYPES as $type) {
            $this->get(route('accounts.voucher.sample', ['type' => $type, 'pdf' => 1]))->assertOk();
        }

        $this->assertSame($before, $count(), 'নমুনা দেখতে গিয়ে কাগজ তৈরি হয়েছে।');
    }

    /**
     * ⓘ নমুনার সইয়ের ঘর আসল ভাউচারের নিয়মেই — আদায়ে "দিলেন", পরিশোধ-খরচে "পেলেন", জাবেদায় দুটো।
     * ⚠️ দুই জায়গায় লেখা নিয়ম আলাদা হয়ে যায়; এই দাবি দুইটাকে মেলায়।
     */
    public function test_the_sample_signs_like_the_real_voucher(): void
    {
        $real = new \ReflectionMethod(VoucherPrintController::class, 'signatures');
        $sample = new \ReflectionMethod(VoucherSampleController::class, 'sample');

        foreach (VoucherSampleController::TYPES as $type) {
            $this->assertSame(
                $real->invoke(app(VoucherPrintController::class), new Voucher(['type' => $type])),
                $sample->invoke(app(VoucherSampleController::class), $type)['signatures'],
                "{$type}: নমুনার সইয়ের ঘর আসল ভাউচারের সাথে মেলে না।",
            );
        }
    }

    public function test_the_same_person_needs_the_control_panel_key(): void
    {
        $person = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->actingAs($person);
        $routes = [...array_values(self::SALES), 'accounts.voucher.sample'];

        foreach ($routes as $route) {
            $this->get(route($route))->assertForbidden();
        }

        CompanyContext::forCompany($this->company->id,
            fn () => $person->givePermissionTo(Permission::findOrCreate('system_admin.settings.manage', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $person->refresh();

        foreach ($routes as $route) {
            $this->get(route($route))->assertOk();
        }
    }
}

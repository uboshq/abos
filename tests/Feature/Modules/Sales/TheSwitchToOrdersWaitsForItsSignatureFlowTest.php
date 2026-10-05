<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Modules\Sales\Services\SalesOrderService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * মেশানোর সুইচ চালুর আগে আদেশের অনুমোদন-প্রবাহ — `abos:orders-replace-do` (SO+DO নকশা, ধাপ ১৩; ৫ অক্টোবর ২০২৬)।
 *
 * দাবি — একই কোম্পানি, পরপর: শুধু দেখলে কিছু বদলায় না; DO-র প্রবাহ আছে আর আদেশের নেই, তখন চালু থামে;
 * কপি করলে আদেশের প্রবাহ একই ধাপ নিয়ে আসে, দ্বিতীয়বার কপি নকল বানায় না; তারপর চালু হয়, আর বন্ধও হয়।
 */
final class TheSwitchToOrdersWaitsForItsSignatureFlowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        // ⓘ দৃশ্যটা নিজে বানানো — ডেমোতে যা-ই থাকুক: DO-র একটা দুই-ধাপের প্রবাহ, আদেশের কোনো প্রবাহ নয়
        ApprovalFlow::query()->where('module', 'sales')->where('action', SalesOrderService::APPROVAL_ACTION)->delete();
        ApprovalFlow::query()->where('module', 'sales')->where('action', 'delivery_order')->delete();
        $flow = ApprovalFlow::query()->create(['module' => 'sales', 'action' => 'delivery_order', 'is_active' => true]);
        $role = (int) \Spatie\Permission\Models\Role::query()->where('company_id', $this->company->id)->value('id');
        $flow->steps()->create(['level' => 1, 'step_name' => 'সুপারভাইজার', 'approver_type' => 'role', 'approver_id' => $role]);
        $flow->steps()->create(['level' => 2, 'step_name' => 'হিসাব', 'approver_type' => 'role', 'approver_id' => $role]);
    }

    public function test_the_switch_waits_for_the_order_flow_then_turns_on_and_off(): void
    {
        $this->artisan('abos:orders-replace-do', ['company' => 'TDEPOT'])->assertExitCode(0);
        $this->assertFalse($this->switchOn(), '⛔ শুধু দেখতে গিয়েই সুইচ বদলে গেল।');
        $this->assertSame(0, $this->orderFlows());

        $this->artisan('abos:orders-replace-do', ['company' => 'TDEPOT', '--on' => true])->assertExitCode(1);
        $this->assertFalse($this->switchOn(), '⛔ আদেশের প্রবাহ নেই, তবু সুইচ চালু — আদেশ সই ছাড়াই অনুমোদিত হত।');

        $this->artisan('abos:orders-replace-do', ['company' => 'TDEPOT', '--copy-flow' => true])->assertExitCode(0);
        $this->assertSame(1, $this->orderFlows(), '⛔ DO-র প্রবাহ আদেশে কপি হয়নি।');
        $copy = ApprovalFlow::query()->where('module', 'sales')->where('action', SalesOrderService::APPROVAL_ACTION)->with('steps')->sole();
        $this->assertSame(['সুপারভাইজার', 'হিসাব'], $copy->steps->sortBy('level')->pluck('step_name')->all(), '⛔ কপিতে ধাপ হারিয়েছে।');

        $this->artisan('abos:orders-replace-do', ['company' => 'TDEPOT', '--copy-flow' => true])->assertExitCode(0);
        $this->assertSame(1, $this->orderFlows(), '⛔ দ্বিতীয়বার কপি নকল প্রবাহ বানাল।');

        $this->artisan('abos:orders-replace-do', ['company' => 'TDEPOT', '--on' => true])->assertExitCode(0);
        $this->assertTrue($this->switchOn(), 'প্রবাহ কপির পরেও সুইচ চালু হয়নি।');

        $this->artisan('abos:orders-replace-do', ['company' => 'TDEPOT', '--off' => true])->assertExitCode(0);
        $this->assertFalse($this->switchOn(), 'সুইচ বন্ধ হয়নি।');
    }

    public function test_an_unknown_company_changes_nothing(): void
    {
        $this->artisan('abos:orders-replace-do', ['company' => 'NOPE', '--on' => true])->assertExitCode(1);
        $this->assertFalse($this->switchOn());
    }

    private function switchOn(): bool
    {
        return (bool) CompanyContext::forCompany($this->company->id,
            fn () => app(SettingsService::class)->get(SalesOrderService::REPLACES_DO, false));
    }

    private function orderFlows(): int
    {
        return ApprovalFlow::query()->where('module', 'sales')->where('action', SalesOrderService::APPROVAL_ACTION)->count();
    }
}

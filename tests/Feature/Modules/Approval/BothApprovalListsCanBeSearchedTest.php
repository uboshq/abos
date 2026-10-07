<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * দুই অনুমোদন-তালিকায় খোঁজা — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬।
 *
 * ⓘ "আমার অনুরোধ"-এ ছাঁকনির সারিই ছিল না, আর "আমার সিদ্ধান্তের অপেক্ষায়"-তে
 * খোঁজার ঘর ছিল না। ⚠️ দাবিটা দুই দিকেই: মেলা কাগজ থাকে, না-মেলা কাগজ সরে।
 */
final class BothApprovalListsCanBeSearchedTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    private function request(string $reason): Approval
    {
        return Approval::query()->create([
            'company_id' => CompanyContext::id(),
            'approvable_type' => 'test',
            'approvable_id' => random_int(1000, 999999),
            'module' => 'accounts',
            'action' => 'voucher',
            'amount' => '1234',
            'status' => Approval::PENDING,
            'current_level' => 1,
            'requested_by' => $this->owner->id,
            'requested_reason' => $reason,
            'requested_at' => now(),
        ]);
    }

    public function test_my_requests_has_a_toolbar_and_its_search_narrows_the_list(): void
    {
        $this->request('Zebra fence repair');
        $this->request('Mango crates');

        $html = $this->get(route('approval.inbox.mine'))->assertOk()->getContent();
        $this->assertStringContainsString('name="q"', $html, '⛔ "আমার অনুরোধ"-এ খোঁজার ঘর নেই।');

        $found = $this->get(route('approval.inbox.mine', ['q' => 'Zebra']))->assertOk()->getContent();
        $this->assertStringContainsString(e(trans_choice('core.count.records', 1, ['count' => 1])), $found,
            '⛔ খুঁজে একটাই মেলার কথা, গোনা অন্য কথা বলছে।');
    }

    public function test_the_waiting_list_has_a_search_box(): void
    {
        $html = $this->get(route('approval.inbox.index'))->assertOk()->getContent();

        $this->assertStringContainsString('name="q"', $html, '⛔ "আমার সিদ্ধান্তের অপেক্ষায়"-তে খোঁজার ঘর নেই।');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Imports\CustomerLimitImporter;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * অনেক গ্রাহকের বাকির সীমা এক ফাইলে — মালিক, ২ অক্টোবর ২০২৬ ("ok doro", "parle ektane koro")।
 *
 * ⓘ erp-এ UB-র ৪১৪ জনের সবার সীমা ০, আর ০ মানে বাকি নেই। ধরে ধরে সম্পাদনা মানে ৪১৪ বার সংরক্ষণ, ৪১৪ সই, তারপর আবার
 * ৪১৪ বার সংরক্ষণ। ⭐ এখন [[CustomerLimitImporter]]: কোড আর সীমা। কমানো সাথে সাথে; বাড়ানো ঠিক সেই অঙ্কে সইয়ের
 * অনুরোধ, আর শেষ সই পড়লে নিজে বসে ([[ApplyTheLimitOnTheLastSignature]])। ⛔ সই এড়ানো যায় না।
 */
final class FourHundredLimitsSetInOneGoTest extends TestCase
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

        $flow = ApprovalFlow::query()->create(['module' => 'customer', 'action' => 'credit_limit', 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->owner->id]);
        $this->app->forgetInstance(ApprovalEngine::class);
    }

    public function test_a_lower_limit_lands_at_once_and_a_higher_one_waits_then_lands_on_the_signature(): void
    {
        [$a, $b] = Customer::query()->whereNotNull('code')->orderBy('id')->limit(2)->get()->all();
        $a->forceFill(['credit_limit' => '50000'])->save();
        $b->forceFill(['credit_limit' => '0'])->save();

        $importer = app(CustomerLimitImporter::class);

        $this->assertSame([], $importer->check(['code' => $a->code, 'credit_limit' => '10,000']));
        $importer->import(['code' => $a->code, 'credit_limit' => '10,000']);
        $this->assertSame(0, bccomp((string) $a->fresh()->credit_limit, '10000', 4), '⛔ সীমা কমানো সাথে সাথে বসেনি।');

        $importer->import(['code' => $b->code, 'credit_limit' => '75000']);
        $this->assertSame(0, bccomp((string) $b->fresh()->credit_limit, '0', 4), '⛔ সই ছাড়াই সীমা বেড়ে গেছে।');

        $pending = Approval::query()->where('action', 'credit_limit')->where('approvable_id', $b->id)->where('status', Approval::PENDING)->first();
        $this->assertNotNull($pending, '⛔ বাড়ানোর জন্য সইয়ের অনুরোধ বসেনি।');
        $this->assertSame(0, bccomp((string) $pending->amount, '75000', 4), '⛔ অনুরোধে ঠিক সেই অঙ্কটা বাঁধা নেই।');

        app(ApprovalEngine::class)->approve($pending, $this->owner);

        $this->assertSame(0, bccomp((string) $b->fresh()->credit_limit, '75000', 4), '⛔ শেষ সই পড়ল, অথচ নতুন সীমা নিজে বসেনি।');
    }

    public function test_an_unknown_code_or_a_negative_limit_is_refused(): void
    {
        $importer = app(CustomerLimitImporter::class);
        $known = (string) Customer::query()->whereNotNull('code')->value('code');

        $this->assertNotEmpty($importer->check(['code' => 'NO-SUCH-CODE', 'credit_limit' => '1000']));
        $this->assertNotEmpty($importer->check(['code' => $known, 'credit_limit' => '-5']));
        $this->assertNotEmpty($importer->check(['code' => $known, 'credit_limit' => 'abc']));
    }
}

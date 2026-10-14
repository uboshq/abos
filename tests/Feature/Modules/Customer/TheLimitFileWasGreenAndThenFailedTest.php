<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
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
 * ⛔ সীমার ফাইলের যাচাই সবুজ দেখাত, তারপর বসাতে গিয়ে সারি পড়ত (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬;
 * [[CustomerLimitImporter::check()]])।
 *
 * ⚠️ বাড়ানো সারি দুই জায়গায় থামে — সীমার সইয়ের ছক নেই, অথবা অন্য অঙ্কের অনুরোধ আগেই অপেক্ষায় — কিন্তু যাচাই শুধু কোড আর
 * অঙ্ক দেখত। ⭐ এখন যাচাইয়েই কারণসহ লাল; কমানো আর একই অঙ্কের অপেক্ষা আগের মতো সবুজ।
 */
final class TheLimitFileWasGreenAndThenFailedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_check_says_what_the_import_would_refuse(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        [$a, $b] = Customer::query()->whereNotNull('code')->orderBy('id')->limit(2)->get()->all();
        $a->forceFill(['credit_limit' => '50000'])->save();
        $b->forceFill(['credit_limit' => '0'])->save();
        $importer = app(CustomerLimitImporter::class);

        // ⓘ ছক নেই — বাড়ানো থামে, কমানো চলে
        $this->assertContains(__('customer::validation.limit_needs_a_flow'), $importer->check(['code' => $a->code, 'credit_limit' => '90000']),
            '⛔ ছক নেই, তবু বাড়ানোর সারি সবুজ');
        $this->assertSame([], $importer->check(['code' => $a->code, 'credit_limit' => '10000']), 'কমানো সারি থেমে গেল');

        $flow = ApprovalFlow::query()->create(['module' => 'customer', 'action' => 'credit_limit', 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $owner->id]);
        $this->app->forgetInstance(ApprovalEngine::class);

        // ⓘ ৭৫ হাজারের অনুরোধ অপেক্ষায় — ৭৫ হাজার সবুজ, ৯০ হাজার লাল
        // ⓘ ছক বসার পরে নতুন করে — আগের আমদানিকারী ছকহীন সেবা ধরে রাখে
        $importer = app(CustomerLimitImporter::class);
        $importer->import(['code' => $b->code, 'credit_limit' => '75000']);
        $this->assertSame([], $importer->check(['code' => $b->code, 'credit_limit' => '75000']), 'একই অঙ্কের অপেক্ষা থেমে গেল');
        $this->assertContains(__('customer::import.limit_other_request_waiting'), $importer->check(['code' => $b->code, 'credit_limit' => '90000']),
            '⛔ অন্য অঙ্কের অনুরোধ অপেক্ষায়, তবু সারি সবুজ');
    }
}

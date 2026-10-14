<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Approval\Services\ApprovalFlowService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ অপেক্ষার কাগজ যে স্তরে বসে, ছক বদলে সেই স্তর উধাও নয় — Approval লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬
 * ([[ApprovalFlowService::update()]])।
 *
 * ⓘ মোছায় পাহারা ছিল, বদলে নয়: স্তর ৩-এ কাগজ অপেক্ষায় রেখে ছক দুই স্তরে নামালে (বা বন্ধ করলে) কাগজটার আর কেউ সইকারী থাকত না।
 */
final class EditingAFlowKeepsItsWaitingPapersTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_change_that_strands_a_waiting_level_is_refused_and_one_that_keeps_it_passes(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);

        $service = app(ApprovalFlowService::class);
        $steps = fn (int $count) => array_map(fn (int $level) => ['level' => $level, 'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $owner->id, 'requires_all' => false], range(1, $count));
        $data = ['module' => 'purchase', 'action' => 'payment', 'is_active' => true];

        $flow = ApprovalFlow::query()->where('module', 'purchase')->where('action', 'payment')->first();
        $flow = $flow === null ? $service->create($data, $steps(3)) : $service->update($flow, $data + ['document_type' => $flow->document_type], $steps(3));

        // ⓘ স্তর ৩-এ একটা কাগজ অপেক্ষায়
        (new Approval)->forceFill(['company_id' => $company->id, 'approvable_type' => 'purchase_payment', 'approvable_id' => 999999,
            'module' => 'purchase', 'action' => 'payment', 'status' => Approval::PENDING, 'current_level' => 3,
            'requested_by' => $owner->id, 'requested_at' => now()])->save();

        foreach ([
            'স্তর কমানো' => [$data, $steps(2)],
            'ছক বন্ধ' => [['is_active' => false] + $data, $steps(3)],
        ] as $what => [$changed, $levels]) {
            try {
                $service->update($flow->fresh(), $changed, $levels);
                $this->fail("⛔ {$what}: অপেক্ষার কাগজের স্তর উধাও হল");
            } catch (ValidationException $e) {
                $this->assertSame(__('approval::validation.flow_change_strands', ['count' => 1]), $e->errors()['flow'][0] ?? null);
            }
        }

        $this->assertSame(3, $flow->fresh('steps')->steps->count(), '⛔ প্রত্যাখ্যাত বদলেও স্তর সরল');

        // ⓘ অপেক্ষার স্তর রেখে বদল — চলে
        $service->update($flow->fresh(), $data, $steps(4));
        $this->assertSame(4, $flow->fresh('steps')->steps->count());
    }
}

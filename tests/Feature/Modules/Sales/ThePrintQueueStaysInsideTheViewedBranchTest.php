<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Sales\Models\PrintJob;
use App\Modules\Sales\Services\PrintQueue;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ছাপার সারির তালিকা দেখার শাখার ভিতরে — Sales লাইন-ধরা নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⛔ সারিতে `branch_id` ছিল, দেয়াল ছিল না: তালিকায় সব শাখার বিলের নম্বর আসত, আর অন্য শাখার সারি "বেরিয়ে গেছে" চিহ্নিত করা যেত।
 * ⓘ একই কাগজ যে শাখা থেকেই ছাপা হোক, সারি একটাই — ছাপার নিজের খোঁজা দেয়াল ছাড়া।
 */
final class ThePrintQueueStaysInsideTheViewedBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_another_branchs_print_job_is_neither_listed_nor_settled(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $home = (int) $company->defaultBranch()?->id;
        $other = Branch::query()->create(['company_id' => $company->id, 'code' => 'PQB2', 'name_en' => 'Print branch', 'is_active' => true]);

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($owner);
        $queue = app(PrintQueue::class);

        CompanyContext::set($company->id, (int) $other->id);
        $theirs = $queue->queue(PrintJob::INVOICE, 9201, 'a4', 'PQ-THEIRS');

        CompanyContext::set($company->id, $home);
        $ours = $queue->queue(PrintJob::INVOICE, 9202, 'a4', 'PQ-OURS');

        // ⓘ হেডারে কেবল নিজের শাখা
        $owner->forceFill(['view_all_branches' => false, 'current_branch_id' => $home])->save();

        $listed = collect($queue->pending('')->items())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $ours->id, $listed, 'দৃশ্যটাই ভুল — নিজের শাখার সারি তালিকায় নেই।');
        $this->assertNotContains((int) $theirs->id, $listed, '⛔ অন্য শাখার ছাপার সারি তালিকায় এল।');

        $this->post(route('sales.print_queue.settle', $theirs->id))->assertNotFound();
        $this->assertSame(PrintJob::WAITING, PrintJob::query()->withoutGlobalScopes()->findOrFail($theirs->id)->status,
            '⛔ অন্য শাখার সারি "বেরিয়ে গেছে" হয়ে গেল।');

        // ⓘ একই কাগজ আবার ছাপা — অন্য শাখার সারিটাই, নতুন সারি নয়
        $this->assertSame((int) $theirs->id, (int) $queue->queue(PrintJob::INVOICE, 9201, 'a4', 'PQ-THEIRS')->id);
    }
}

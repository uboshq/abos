<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Sales\Models\PrintJob;
use App\Modules\Sales\Services\PrintQueue;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ ছাপার সীমা কেবল বিল আর চালানে, আগের মতোই — নতুন গোনা কাগজে (অর্ডার, DO, গেট পাস, রসিদ) শুধু DUPLICATE, আটকানো নয়
 * (fe, ১১ অক্টোবর ২০২৬, PR #17 রিভিউ ⚠️১১: ফ্রিজে নতুন করে আটকানো চলে না)।
 */
final class TheReprintLimitStaysOnBillsAndChallansTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_limit_stops_a_bill_and_a_challan_but_not_the_newly_counted_papers(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        app(SettingsService::class)->set('sales.reprint_limit', 1);

        // ⓘ ছাড়ানোর চাবি ছাড়া একজন — নইলে সীমা কাউকেই থামায় না, দাবিটা অর্থহীন
        $clerk = User::factory()->create(['current_company_id' => $company->id, 'is_active' => true]);
        $clerk->companies()->attach($company->id, ['is_active' => true]);
        $this->actingAs($clerk);
        $this->assertFalse($clerk->can('sales.reprint.override'));

        $queue = app(PrintQueue::class);

        foreach ([PrintJob::INVOICE, PrintJob::CHALLAN] as $type) {
            $job = $queue->printed($queue->queue($type, 7001, 'a4', 'L-1'));
            $this->assertFalse($queue->mayPrint($job), "⛔ {$type}: সীমা পেরিয়েও ছাপা যায় — সীমাটা হারিয়েছে।");
        }

        foreach ([PrintJob::ORDER, PrintJob::DELIVERY_ORDER, PrintJob::GATE_PASS, PrintJob::CHALLAN_GATEPASS, PrintJob::RECEIPT] as $type) {
            $job = $queue->printed($queue->queue($type, 7001, 'a4', 'N-1'));
            $this->assertTrue($job->isReprint());
            $this->assertTrue($queue->mayPrint($job), "⛔ {$type}: ফ্রিজে নতুন আটকানো — এই কাগজে সীমা খাটার কথা নয়।");
        }
    }
}

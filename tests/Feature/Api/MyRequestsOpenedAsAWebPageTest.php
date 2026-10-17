<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\Company;
use App\Models\User;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ "আমার অনুরোধ" ফোনের নিজের পর্দায় — ১৭ অক্টোবর ২০২৬।
 *
 * ── ⛔ কী ভাঙা ছিল ──────────────────────────────────────────────────
 * মালিক: *"direct webpage app e bujajayna"*। মেনুর "আমার অনুরোধ" ফোনে ওয়েব-পাতা হয়ে খুলত, কারণ ফোনের কোনো দরজা
 * নিজের পাঠানো অনুরোধ দিত না — কেবল নিজের সইয়ের অপেক্ষার সারি (`/approvals/pending`)।
 *
 * ⭐ দাবি: `GET /api/v1/approvals/mine` ওয়েবের পাতার একই সারি দেয় (আমি যা পাঠিয়েছি, নতুন আগে), অবস্থা আর শেষ কথা
 * (কে, কী, কেন) সহ, পুরো গোনা আর অপেক্ষমাণের গোনা সহ; অন্যের অনুরোধ আসে না, বেতন ফোনে আসে না।
 */
final class MyRequestsOpenedAsAWebPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_phone_lists_what_i_asked_for_with_where_it_stands(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $me = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $other = User::factory()->create(['is_active' => true, 'current_company_id' => $company->id]);
        $other->companies()->attach($company->id, ['is_active' => true]);

        $ask = function (string $no, User $by, string $status) use ($company): Approval {
            $po = PurchaseOrder::create([
                'company_id' => $company->id,
                'branch_id' => $company->defaultBranch()?->id,
                'document_no' => $no,
                'supplier_id' => Supplier::query()->value('id'),
                'trx_date' => now()->toDateString(),
                'total' => '1000',
                'created_by' => $by->id,
            ]);

            return Approval::create([
                'company_id' => $company->id,
                'approvable_type' => PurchaseOrder::class,
                'approvable_id' => $po->id,
                'module' => 'purchase',
                'action' => 'order',
                'amount' => '1000',
                'status' => $status,
                'current_level' => 1,
                'requested_by' => $by->id,
                'requested_at' => now(),
            ]);
        };

        $waiting = $ask('PO-MINE-1', $me, Approval::PENDING);
        $returned = $ask('PO-MINE-2', $me, Approval::RETURNED);
        ApprovalDecision::create([
            'approval_id' => $returned->id,
            'level' => 1,
            'user_id' => $other->id,
            'decision' => Approval::RETURNED,
            'remarks' => 'দর ঠিক করুন',
            'decided_at' => now(),
        ]);
        $ask('PO-OTHER-1', $other, Approval::PENDING);

        $run = PayrollRun::create([
            'company_id' => $company->id, 'document_no' => 'PRL-MINE-1', 'month' => now()->format('Y-m'),
            'trx_date' => now()->toDateString(), 'gross_total' => '9000', 'deduction_total' => '0', 'net_total' => '9000',
            'employee_count' => 1, 'created_by' => $me->id,
        ]);
        Approval::create([
            'company_id' => $company->id, 'approvable_type' => PayrollRun::class, 'approvable_id' => $run->id,
            'module' => 'hr', 'action' => 'payroll', 'amount' => '9000', 'status' => Approval::PENDING,
            'current_level' => 1, 'requested_by' => $me->id, 'requested_at' => now(),
        ]);

        Sanctum::actingAs($me, [AuthController::APP]);
        $answer = $this->getJson('/api/v1/approvals/mine')->assertOk();

        $this->assertSame(
            [(string) $returned->public_id, (string) $waiting->public_id],
            collect($answer->json('rows'))->pluck('id')->all(),
            '⛔ আমার অনুরোধ নতুন-আগে আসেনি, বা অন্যের/বেতনের অনুরোধ ঢুকেছে');
        $this->assertSame(2, $answer->json('count'));
        $this->assertSame(1, $answer->json('pendingCount'));

        $first = $answer->json('rows.0');
        $this->assertSame('PO-MINE-2', $first['documentNo']);
        $this->assertSame(Approval::RETURNED, $first['status']);
        $this->assertSame('সংশোধনে ফেরত', $first['statusLabel']);
        $this->assertSame('দর ঠিক করুন', $first['lastDecision']['remarks'], '⛔ ফেরতের কারণ লেখক দেখেন না');
        $this->assertSame($other->name, $first['lastDecision']['by']);
        $this->assertNull($answer->json('rows.1.lastDecision'));
    }
}

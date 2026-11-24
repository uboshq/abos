<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Services\PaperPreview;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\Company;
use App\Models\DocumentDelivery;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ "দেখুন" shows the paper as it prints, and looking is not printing (owner, 24 Nov 2026).
 *
 * Claims: the owner gets the voucher's PDF; nothing is written to the print trail; a person who may not read the paper gets 403; a
 * record with no printed paper offers no preview.
 */
final class TheSignerSeesThePrintedPaperTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_printed_voucher_is_shown_and_never_counted_as_printed(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['current_company_id' => $company->id, 'current_branch_id' => $company->defaultBranch()?->id, 'ui' => 'blue'])->save();

        $voucher = Voucher::query()->create([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'type' => Voucher::RECEIPT,
            'document_no' => 'RCV-LOOK-1', 'trx_date' => now()->toDateString(), 'amount' => '500', 'status' => 'draft', 'created_by' => $owner->id,
        ]);
        $approval = Approval::create(['company_id' => $company->id, 'approvable_type' => Voucher::class, 'approvable_id' => $voucher->id,
            'module' => 'accounts', 'action' => Voucher::RECEIPT, 'amount' => '500', 'status' => Approval::PENDING, 'current_level' => 1,
            'requested_by' => $owner->id, 'requested_at' => now()]);

        $before = DocumentDelivery::query()->withoutGlobalScopes()->count();
        $paper = $this->actingAs($owner)->get(route('approval.inbox.paper', $approval->id));
        $paper->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $paper->getContent(), '⛔ the preview is not the printed paper.');
        $this->assertSame($before, DocumentDelivery::query()->withoutGlobalScopes()->count(), '⛔ looking was counted as printing.');

        $stranger = User::factory()->create(['is_active' => true]);
        $stranger->companies()->attach($company, ['is_active' => true]);
        $stranger->forceFill(['current_company_id' => $company->id])->save();
        $this->actingAs($stranger->fresh())->get(route('approval.inbox.paper', $approval->id))->assertForbidden();

        $this->assertSame('accounts_voucher', app(PaperPreview::class)->typeOf($voucher));
        $this->assertNull(app(PaperPreview::class)->typeOf($company), '⛔ a record with no printed paper offers a preview.');
    }
}

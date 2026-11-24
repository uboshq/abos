<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⭐ The approval inbox board (owner "ok", 23 Nov 2026, sample YRFmwfF7 v5) — papers grouped by kind in coloured boxes with
 * a subtotal each, the open paper on the right with a big linked amount and the decide buttons under the boxes.
 *
 * Claims:
 *   · rose, navy and blue draw the board: one box per kind, each with its subtotal; the paper number and the main
 *     amount are links; the money paper has no checkbox (owner's decision 5), the other kind has;
 *   · ⛔ every other look (SAP "tiles", Dynamic, Odoo "apps", classic) keeps the cards — no board, not one class of it
 *     (owner: "সাপ, ডাইনামিক, ওডু … সেগুলোর মতোই থাকবে").
 */
final class TheInboxBoardIsOnlyInTheAbosLooksTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->signer = $this->member('board-signer@abos.test');
        $clerk = $this->member('board-clerk@abos.test');

        $this->flowFor('sales', 'discount', $this->signer);
        $this->flowFor('accounts', 'payment', $this->signer);

        $this->waiting('sales', 'discount', '500', $clerk);
        $this->waiting('sales', 'discount', '700', $clerk);
        $this->waiting('accounts', 'payment', '100', $clerk);
    }

    public function test_the_three_abos_looks_draw_the_board_with_a_box_and_subtotal_per_kind(): void
    {
        foreach (['rose', 'navy', 'blue'] as $look) {
            $this->signer->forceFill(['ui' => $look])->save();

            $html = (string) $this->actingAs($this->signer->fresh())->get(route('approval.inbox.index'))->assertOk()->getContent();

            $this->assertStringContainsString('data-inbox-board', $html, "⛔ {$look}: the inbox board is not drawn.");
            $this->assertSame(2, substr_count($html, 'data-board-group="'), "⛔ {$look}: two kinds should give two boxes.");
            $this->assertSame(2, substr_count($html, 'class="inbox-board-sub"'), "⛔ {$look}: a kind box has no subtotal row.");
            $this->assertStringContainsString(\App\Core\Support\Money::format('1200'), $html, "⛔ {$look}: the discount box has no subtotal 1,200.00.");
            $this->assertStringContainsString('class="inbox-board-dmoney"', $html, "⛔ {$look}: the open paper has no big amount.");
            // ⓘ the money paper never gets a checkbox; the discounts do (owner's decision 5)
            $this->assertSame(2, substr_count($html, 'data-board-in="sales.discount"'), "⛔ {$look}: the discounts lost their checkboxes.");
            $this->assertStringNotContainsString('data-board-in="accounts.payment"', $html, "⛔ {$look}: a money paper got a checkbox.");
            $this->assertStringNotContainsString('data-card-state', $html, "⛔ {$look}: the old cards are drawn beside the board.");
        }
    }

    public function test_every_other_look_keeps_its_cards(): void
    {
        foreach (['tiles', 'dynamic', 'apps', 'classic'] as $look) {
            $this->signer->forceFill(['ui' => $look])->save();

            $html = (string) $this->actingAs($this->signer->fresh())->get(route('approval.inbox.index'))->assertOk()->getContent();

            $this->assertStringNotContainsString('data-inbox-board', $html, "⛔ {$look}: the board leaked into another look.");
            $this->assertStringNotContainsString('inbox-board-', $html, "⛔ {$look}: a board class leaked into another look.");
            $this->assertStringContainsString('data-card-state', $html, "⛔ {$look}: the cards are gone.");
        }
    }

    private function member(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'is_active' => true, 'locale' => 'bn']);
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id])->save();
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(
            array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), ['approval.view', 'approval.decide'])));

        return $user;
    }

    private function flowFor(string $module, string $action, User $signer): void
    {
        ApprovalFlow::query()->where('company_id', $this->company->id)->where('module', $module)->where('action', $action)->delete();
        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => $module, 'action' => $action, 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $signer->id]);
    }

    private function waiting(string $module, string $action, string $amount, User $by): Approval
    {
        $branch = Branch::query()->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();

        return Approval::create([
            'company_id' => $this->company->id,
            'approvable_type' => Branch::class,
            'approvable_id' => $branch->id,
            'module' => $module,
            'action' => $action,
            'amount' => $amount,
            'status' => Approval::PENDING,
            'current_level' => 1,
            'requested_by' => $by->id,
            'requested_at' => now(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Engines\Approval\BulkApproval;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalLimit;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * দফা ১৩ — একসাথে সই: আগে দেখা, তারপর সই; শুধু এক-ধাপের কাগজ; নিজের, টাকার আর সীমার বাইরের কাগজ কারণসহ বাদ
 * (অনুমোদন মডিউলের নকশা, মালিক ২১ অক্টোবর ২০২৬; সীমা না থাকলে কড়া দিক — fe, ২২ অক্টোবর ২০২৬)।
 *
 * দাবি:
 *   · আগে-দেখার পাতা কয়টা আর মোট কত বলে, প্রতিটা বাদের কারণ লেখে, আর কিছুই সই করে না;
 *   · আসল সই কেবল ছোট এক-ধাপের অন্যের কাগজে — বাকি চারটা অপেক্ষাতেই থাকে;
 *   · টাকার সীমার সারিই না থাকলে সব বাদ ("আপনার টাকার সীমা ঠিক করা নেই") — সুপার অ্যাডমিন ছাড়া।
 */
final class BulkSigningLooksFirstAndKeepsItsRulesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $signer;

    private User $clerk;

    /** @var array<string, Approval> */
    private array $paper = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->signer = $this->member('bulk-signer@a5.test');
        $this->clerk = $this->member('bulk-clerk@a5.test');
        $second = $this->member('bulk-second@a5.test');

        $this->flowFor('sales', 'discount', [$this->signer]);
        $this->flowFor('sales', 'order', [$this->signer, $second]);
        $this->flowFor('accounts', 'payment', [$this->signer]);

        $role = CompanyContext::forCompany($this->company->id, fn () => Role::findOrCreate('bulk-limit', 'web'));
        CompanyContext::forCompany($this->company->id, fn () => $this->signer->assignRole($role));
        ApprovalLimit::query()->create(['company_id' => $this->company->id, 'role_id' => $role->id, 'module' => 'sales', 'max_amount' => '1000']);
        // ⓘ টাকার কাগজের মডিউলেও সীমা — যাতে পরিশোধটা কেবল 'টাকা নড়ার কাজ' নিয়মে বাদ পড়ে, সীমা না থাকার কারণে নয়
        ApprovalLimit::query()->create(['company_id' => $this->company->id, 'role_id' => $role->id, 'module' => 'accounts', 'max_amount' => '1000']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->paper = [
            'small' => $this->waiting('sales', 'discount', '500', $this->clerk),
            'big' => $this->waiting('sales', 'discount', '5000', $this->clerk),
            'own' => $this->waiting('sales', 'discount', '300', $this->signer),
            'two_steps' => $this->waiting('sales', 'order', '200', $this->clerk),
            'money' => $this->waiting('accounts', 'payment', '100', $this->clerk),
        ];
    }

    public function test_the_review_counts_totals_and_explains_and_signs_nothing(): void
    {
        $page = $this->actingAs($this->signer->fresh())
            ->post(route('approval.inbox.bulk_review'), ['ids' => $this->ids()])
            ->assertOk();

        $page->assertSee(__('approval::message.bulk_will_sign', ['count' => 1, 'total' => '500.00'], 'bn'));
        foreach (['over_limit', 'own', 'multi_step', 'money'] as $why) {
            $page->assertSee(__('approval::message.bulk_why_'.$why, [], 'bn'));
        }
        $page->assertSee('name="ids[]" value="'.$this->paper['small']->id.'"', false);
        $page->assertDontSee('name="ids[]" value="'.$this->paper['big']->id.'"', false);

        foreach ($this->paper as $name => $approval) {
            $this->assertSame(Approval::PENDING, $approval->fresh()->status, "⛔ আগে-দেখাতেই {$name} সই হয়ে গেছে।");
        }
    }

    public function test_only_the_small_one_step_paper_of_someone_else_is_signed(): void
    {
        $this->actingAs($this->signer->fresh())
            ->post(route('approval.inbox.bulk'), ['ids' => $this->ids()])
            ->assertRedirect(route('approval.inbox.index'));

        $this->assertNotSame(Approval::PENDING, $this->paper['small']->fresh()->status, '⛔ ছোট এক-ধাপের কাগজটা সই হয়নি।');
        foreach (['big' => 'সীমার বাইরে', 'own' => 'নিজের', 'two_steps' => 'দুই ধাপের', 'money' => 'টাকার'] as $name => $what) {
            $this->assertSame(Approval::PENDING, $this->paper[$name]->fresh()->status, "⛔ {$what} কাগজ একসাথে সইয়ে পাশ হয়ে গেছে।");
        }
    }

    public function test_no_limit_row_leaves_everything_out_but_a_super_admin_signs(): void
    {
        ApprovalLimit::query()->where('company_id', $this->company->id)->delete();

        $review = app(BulkApproval::class)->review([(int) $this->paper['small']->id], $this->signer->fresh());
        $this->assertSame([], $review['sign'], '⛔ সীমা ঠিক না থাকা সইদাতা একসাথে সই দিতে পারছেন।');
        $this->assertSame('no_limit', $review['skip'][0]['why'] ?? null);
        $this->assertSame('আপনার টাকার সীমা ঠিক করা নেই — একটা একটা করে সই দিন', __('approval::message.bulk_why_no_limit', [], 'bn'));

        CompanyContext::forCompany($this->company->id, fn () => $this->signer->assignRole(PermissionSyncer::SUPER_ADMIN_ROLE));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $review = app(BulkApproval::class)->review([(int) $this->paper['small']->id], $this->signer->fresh());
        $this->assertCount(1, $review['sign'], '⛔ সুপার অ্যাডমিনও সীমার কারণে আটকে গেলেন।');
    }

    /** @return list<int> */
    private function ids(): array
    {
        return array_map(fn (Approval $a) => (int) $a->id, array_values($this->paper));
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

    /** @param list<User> $signers ধাপ ১, ২, … */
    private function flowFor(string $module, string $action, array $signers): void
    {
        ApprovalFlow::query()->where('company_id', $this->company->id)->where('module', $module)->where('action', $action)->delete();
        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => $module, 'action' => $action, 'is_active' => true]);
        foreach ($signers as $i => $user) {
            ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => $i + 1,
                'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $user->id]);
        }
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

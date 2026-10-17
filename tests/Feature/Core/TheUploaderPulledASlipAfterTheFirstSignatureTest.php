<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * আপলোডকারী প্রথম সইয়ের পরে, বা পোস্ট হওয়া কাগজ থেকে, স্লিপটা সরিয়ে দিতে পারতেন — ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬।
 *
 * ⛔ "সই হয়েছে" মানে ছিল কেবল পুরো অনুমোদন `approved`:
 * - দুই স্তরের ছকে প্রথম স্তর সই দিলে অনুমোদনটা তখনো `pending` — যে স্লিপ দেখে প্রথম জন সই দিলেন, সেটা সরানো যেত;
 * - অনুমোদন বন্ধ কোম্পানিতে (UB) অনুমোদনের সারিই নেই — পোস্ট হওয়া রসিদের স্লিপ যেকোনো দিন সরানো যেত;
 * - আর এই পথ কাগজটা দেখার অধিকারও চাইত না।
 * ⓘ নিজের ভুল ফাইল খসড়ায় নিজে সরানো যায় — সেই ধনাত্মক দিকটাও মাপা হয়।
 */
final class TheUploaderPulledASlipAfterTheFirstSignatureTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DemoSeeder::class);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->company = Company::query()->findOrFail($this->owner->current_company_id);
        CompanyContext::set($this->company->id, $this->owner->current_branch_id ?? $this->company->defaultBranch()?->id);
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_the_first_level_signature_locks_the_slip_for_its_uploader(): void
    {
        $voucher = $this->draftVoucher();
        $clerk = $this->userWith(['accounts.voucher.create', 'accounts.report']);
        $slip = $this->upload($clerk, $voucher, 'level-one.pdf');

        $approval = Approval::query()->create([
            'company_id' => $this->company->id,
            'approvable_type' => Voucher::class,
            'approvable_id' => $voucher->id,
            'module' => 'accounts',
            'action' => 'voucher.post',
            'amount' => '500',
            'status' => Approval::PENDING,
            'current_level' => 2,
            'requested_by' => $clerk->id,
            'requested_at' => now(),
        ]);

        /* ⓘ পাহারাটা মাপছে কি না: স্তর ১-এর সই বসার আগে আপলোডকারী এখনো পারেন — নিচের ৪০৩ তাই সইয়ের জন্যই */
        $this->assertTrue(app(\App\Core\Engines\Attachment\AttachmentEngine::class)->uploaderMayRemove($slip, $voucher->fresh(), $clerk->fresh()),
            'সইয়ের আগেই আপলোডকারী আটকে গেলেন — দাবিটা সইয়ের কিছু মাপছে না।');

        ApprovalDecision::query()->create([
            'approval_id' => $approval->id,
            'level' => 1,
            'user_id' => $this->owner->id,
            'decision' => ApprovalDecision::APPROVED,
            'decided_at' => now(),
        ]);

        $this->actingAs($clerk->fresh())->delete(route('attachment.destroy', $slip))->assertForbidden();
        $this->assertNotSoftDeleted($slip);
    }

    public function test_a_posted_paper_without_any_approval_keeps_its_slip(): void
    {
        $voucher = $this->draftVoucher();
        $clerk = $this->userWith(['accounts.voucher.create', 'accounts.report']);
        $slip = $this->upload($clerk, $voucher, 'posted.pdf');

        DB::table($voucher->getTable())->where('id', $voucher->id)->update(['status' => DocumentStatus::CONFIRMED]);
        $this->freshRequest();

        $this->assertSame(0, Approval::query()->where('approvable_id', $voucher->id)->count(), 'অনুমোদনের সারি আছে — দাবিটা "অনুমোদন বন্ধ" মাপছে না।');

        $this->actingAs($clerk->fresh())->delete(route('attachment.destroy', $slip))->assertForbidden();
        $this->assertNotSoftDeleted($slip);
    }

    public function test_the_uploader_needs_to_see_the_paper_and_may_still_fix_a_draft(): void
    {
        $voucher = $this->draftVoucher();
        $clerk = $this->userWith(['accounts.voucher.create', 'accounts.report']);

        $mine = $this->upload($clerk, $voucher, 'mistake.pdf');
        $this->actingAs($clerk->fresh())->delete(route('attachment.destroy', $mine))->assertRedirect();
        $this->assertSoftDeleted($mine);

        $slip = $this->upload($clerk, $voucher, 'kept.pdf');
        $clerk->revokePermissionTo('accounts.report');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->freshRequest();

        $this->actingAs($clerk->fresh())->delete(route('attachment.destroy', $slip))->assertForbidden();
        $this->assertNotSoftDeleted($slip);
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** ⓘ লাইভে প্রতিটা অনুরোধ নতুন app পায়; পরীক্ষায় scoped রিজলভার আগের অনুরোধের মডেল মনে রাখে ([[AttachmentScreenTest]]) */
    private function freshRequest(): void
    {
        $this->app->forgetScopedInstances();
        $this->app['router']->getRoutes()->getByName('attachment.destroy')->flushController();
    }

    private function draftVoucher(): Voucher
    {
        [$a, $b] = Account::query()->where('company_id', $this->company->id)
            ->postable()->active()->whereNull('money_kind')->orderBy('code')->take(2)->get()->all();

        return app(VoucherService::class)->create([
            'type' => Voucher::JOURNAL,
            'trx_date' => now()->toDateString(),
            'narration' => 'SLIP-AFTER-SIGN',
        ], [
            ['account_id' => $a->id, 'debit' => '500', 'credit' => '0'],
            ['account_id' => $b->id, 'debit' => '0', 'credit' => '500'],
        ]);
    }

    private function upload(User $who, Voucher $voucher, string $name): Attachment
    {
        $path = tempnam(sys_get_temp_dir(), 'att');
        $this->temp[] = $path;
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");

        $this->actingAs($who)->post(route('attachment.store'), [
            'source_type' => Voucher::SOURCE_TYPES[$voucher->type] ?? $voucher->type,
            'source_id' => $voucher->id,
            'file' => new UploadedFile($path, $name, 'application/pdf', null, true),
        ])->assertRedirect();

        return Attachment::query()->where('original_name', $name)->firstOrFail();
    }

    /** @param  list<string>  $keys */
    private function userWith(array $keys): User
    {
        $user = User::factory()->create([
            'current_company_id' => $this->company->id,
            'current_branch_id' => $this->owner->current_branch_id,
            'is_active' => true,
        ]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        foreach ($keys as $key) {
            $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * কাগজ জোড়া আর সরানোর দরজা — ৩০ সেপ্টেম্বর ২০২৬ (নিরাপত্তা-অডিট ২৯ সেপ্টেম্বর, খোঁজ ১০)।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * - **জোড়া:** দরজা দেখত কেবল *ধরনের* `create` চাবি — যে কাগজে জুড়ছেন সেটা তিনি দেখতে
 *   পান কি না, দেখত না। যাঁর কাছে কাগজটা লুকানো, তিনিও আইডি ধরে তাতে ফাইল জুড়তে পারতেন।
 * - **সরানো:** আপলোডকারী **সবসময়** নিজের ফাইল সরাতে পারতেন — অনুমোদন হয়ে যাওয়ার পরেও।
 *   ব্যাংক স্লিপ দেখে সই দেওয়া হয়; সই হয়ে গেলে স্লিপটা সরে গেলে প্রমাণটাই থাকত না।
 *
 * ── ⭐ কীভাবে মাপা ──────────────────────────────────────────────────────
 * একই মানুষ দুইবার: চাবি ছাড়া দরজা বন্ধ, চাবি দিলে খোলা। ⚠️ মালিক (super_admin) সব
 * চাবি পান, তাই তিনি আটকান না।
 */
final class APaperCouldBeAttachedOrRemovedPastItsDoorTest extends TestCase
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

    public function test_attaching_needs_the_paper_to_be_visible_to_you(): void
    {
        $voucher = $this->draftVoucher();
        $clerk = $this->userWith(['accounts.voucher.create']);

        $this->actingAs($clerk)->post(route('attachment.store'), $this->form($voucher, 'blind.pdf'))
            ->assertForbidden();
        $this->assertSame(0, Attachment::query()->where('original_name', 'blind.pdf')->count(),
            '⛔ কাগজটা দেখতে না পেয়েও তাতে ফাইল জুড়ে গেল।');

        $this->grant($clerk, 'accounts.report');

        $this->actingAs($clerk->fresh())->post(route('attachment.store'), $this->form($voucher, 'seen.pdf'))
            ->assertRedirect();
        $this->assertSame(1, Attachment::query()->where('original_name', 'seen.pdf')->count());
    }

    public function test_the_uploader_cannot_pull_their_file_once_the_paper_is_signed(): void
    {
        $voucher = $this->draftVoucher();
        $clerk = $this->userWith(['accounts.voucher.create', 'accounts.report']);

        $early = $this->upload($clerk, $voucher, 'early.pdf');

        /* ⓘ সই হওয়ার আগে: নিজের ভুল ফাইল নিজে সরানো যায় — আগের মতোই */
        $this->actingAs($clerk)->delete(route('attachment.destroy', $early))->assertRedirect();
        $this->assertSoftDeleted($early);

        $slip = $this->upload($clerk, $voucher, 'slip.pdf');
        $this->signed($voucher);

        $this->actingAs($clerk->fresh())->delete(route('attachment.destroy', $slip))->assertForbidden();
        // ⛔ সই হয়ে যাওয়ার পরে আপলোডকারী প্রমাণের স্লিপটা সরিয়ে দিতে পারতেন
        $this->assertNotSoftDeleted($slip);

        /* ⭐ কাগজ সম্পাদনার চাবি যাঁর — তদারকি — তিনি এখনো পারেন */
        $this->grant($clerk, 'accounts.voucher.update');
        $this->actingAs($clerk->fresh())->delete(route('attachment.destroy', $slip))->assertRedirect();
        $this->assertSoftDeleted($slip);
    }

    public function test_the_owner_attaches_and_removes_freely(): void
    {
        $voucher = $this->draftVoucher();
        $mine = $this->upload($this->owner, $voucher, 'owner.pdf');
        $this->signed($voucher);

        $this->actingAs($this->owner)->delete(route('attachment.destroy', $mine))->assertRedirect();
        $this->assertSoftDeleted($mine);
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function draftVoucher(): Voucher
    {
        [$a, $b] = Account::query()->where('company_id', $this->company->id)
            ->postable()->active()->whereNull('money_kind')->orderBy('code')->take(2)->get()->all();

        return app(VoucherService::class)->create([
            'type' => Voucher::JOURNAL,
            'trx_date' => now()->toDateString(),
            'narration' => 'ATTACH-DOOR',
        ], [
            ['account_id' => $a->id, 'debit' => '500', 'credit' => '0'],
            ['account_id' => $b->id, 'debit' => '0', 'credit' => '500'],
        ]);
    }

    /** সই হয়ে গেছে — অনুমোদনের সারিটা "approved" */
    private function signed(Voucher $voucher): void
    {
        Approval::query()->create([
            'company_id' => $this->company->id,
            'approvable_type' => Voucher::class,
            'approvable_id' => $voucher->id,
            'module' => 'accounts',
            'action' => 'voucher.post',
            'amount' => '500',
            'status' => Approval::APPROVED,
            'current_level' => 1,
            'requested_by' => $this->owner->id,
            'requested_at' => now(),
            'decided_at' => now(),
        ]);
    }

    private function upload(User $who, Voucher $voucher, string $name): Attachment
    {
        $this->actingAs($who)->post(route('attachment.store'), $this->form($voucher, $name))->assertRedirect();

        return Attachment::query()->where('original_name', $name)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function form(Voucher $voucher, string $name): array
    {
        $path = tempnam(sys_get_temp_dir(), 'att');
        $this->temp[] = $path;
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");

        return [
            'source_type' => Voucher::SOURCE_TYPES[$voucher->type] ?? $voucher->type,
            'source_id' => $voucher->id,
            'file' => new UploadedFile($path, $name, 'application/pdf', null, true),
        ];
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
            $this->grant($user, $key);
        }

        return $user->fresh();
    }

    private function grant(User $user, string $key): void
    {
        $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

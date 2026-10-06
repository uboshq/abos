<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * যিনি ভাউচার লিখলেন তিনিই পোস্ট করলেন — ভাউচারের আন্তর্জাতিক পরিকল্পনা, অংশ ৩গ, ৭ অক্টোবর ২০২৬: "লেখক ≠ পাকাকারী"।
 *
 * ⭐ সুইচ `accounts.voucher_maker_checker` চালু থাকলে ভাউচারের পর্দায় নিজের লেখা ভাউচার নিজে পোস্ট হয় না — অন্য কেউ করেন
 * ([[VoucherService::writerMayNotPost()]])। লেখকের "সংরক্ষণ ও পোস্ট" খসড়া হয়ে থাকে, বার্তাসহ। মালিক একা করলে আটকায় না,
 * নিরীক্ষায় দাগ পড়ে। সুইচ বন্ধে আজকের আচরণ। আজকের সব কোম্পানিতে মাইগ্রেশন বন্ধ লেখে।
 */
final class TheWriterPostedTheirOwnVoucherTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        $this->switchTo(true);
    }

    public function test_the_writer_cannot_post_their_own_draft_and_another_hand_can(): void
    {
        $writer = $this->clerk();
        $checker = $this->clerk();

        $this->actingAs($writer);
        $this->post(route('accounts.voucher.store', 'journal'), $this->journal(['save_as_draft' => '1']))->assertSessionHasNoErrors();
        $voucher = Voucher::query()->latest('id')->firstOrFail();
        $this->assertSame($writer->id, (int) $voucher->created_by);

        $this->post(route('accounts.voucher.post', $voucher))->assertSessionHasErrors('status');
        $this->assertTrue($voucher->fresh()->isDraft(), '⛔ লেখক নিজের ভাউচার নিজে পোস্ট করলেন।');

        $this->actingAs($checker);
        $this->post(route('accounts.voucher.post', $voucher))->assertSessionHasNoErrors();
        $this->assertTrue($voucher->fresh()->isPosted(), '⛔ অন্য হাতও পোস্ট করতে পারল না।');
    }

    public function test_save_and_post_by_the_writer_keeps_a_draft_and_says_why(): void
    {
        $writer = $this->clerk();
        $this->actingAs($writer);

        $this->post(route('accounts.voucher.store', 'journal'), $this->journal())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warning', fn ($m) => str_contains((string) $m, __('accounts::message.voucher_awaits_another_hand', ['no' => Voucher::query()->latest('id')->value('document_no')])));
        $voucher = Voucher::query()->latest('id')->firstOrFail();
        $this->assertTrue($voucher->isDraft(), '⛔ লেখকের "সংরক্ষণ ও পোস্ট" খাতায় বসে গেল।');

        // ⓘ সম্পাদনা করে "সংরক্ষণ ও পোস্ট" — একই
        $this->put(route('accounts.voucher.update', $voucher), $this->journal(['narration' => 'আবার লেখা']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warning');
        $this->assertTrue($voucher->fresh()->isDraft(), '⛔ সম্পাদনার পথে লেখক নিজে পোস্ট করলেন।');
    }

    public function test_with_the_switch_off_the_writer_posts_as_today(): void
    {
        $this->switchTo(false);
        $writer = $this->clerk();
        $this->actingAs($writer);

        $this->post(route('accounts.voucher.store', 'journal'), $this->journal())->assertSessionHasNoErrors()->assertSessionHas('saved');
        $this->assertTrue(Voucher::query()->latest('id')->firstOrFail()->isPosted(), '⛔ সুইচ বন্ধেও আটকাল।');
    }

    public function test_the_owner_alone_passes_and_the_audit_trail_marks_it(): void
    {
        $this->post(route('accounts.voucher.store', 'journal'), $this->journal())->assertSessionHasNoErrors()->assertSessionHas('saved');
        $voucher = Voucher::query()->latest('id')->firstOrFail();
        $this->assertTrue($voucher->isPosted());
        $this->assertSame(1, $this->marks($voucher), '⛔ মালিক নিজের লেখা নিজে পোস্ট করলেন, নিরীক্ষায় দাগ নেই।');

        // ⓘ সুইচ বন্ধে দাগ নেই — কোনো নিয়ম ভাঙা হয়নি
        $this->switchTo(false);
        $this->post(route('accounts.voucher.store', 'journal'), $this->journal())->assertSessionHasNoErrors();
        $this->assertSame(0, $this->marks(Voucher::query()->latest('id')->firstOrFail()), '⛔ সুইচ বন্ধে দাগ পড়ল।');
    }

    public function test_a_system_post_is_not_a_hand_and_passes(): void
    {
        $writer = $this->clerk();
        $this->actingAs($writer);
        $this->post(route('accounts.voucher.store', 'journal'), $this->journal(['save_as_draft' => '1']))->assertSessionHasNoErrors();
        $voucher = Voucher::query()->latest('id')->firstOrFail();

        // ⓘ শেষ সইয়ের পরে নিজে-পাকা, ইমপোর্ট, সিস্টেমের এক-ধাপের ভাউচার — byHand ছাড়া
        app(VoucherService::class)->post($voucher);
        $this->assertTrue($voucher->fresh()->isPosted());
    }

    public function test_the_migration_writes_off_for_every_company_and_keeps_a_set_row(): void
    {
        $key = VoucherService::MAKER_CHECKER;
        DB::table('settings')->where('key', $key)->delete();
        $kept = Company::query()->where('id', '<>', $this->company->id)->firstOrFail();
        DB::table('settings')->insert(['company_id' => $kept->id, 'module' => 'accounts', 'key' => $key, 'type' => 'boolean',
            'value' => '1', 'group' => 'entry', 'created_at' => now(), 'updated_at' => now()]);

        (require base_path('app/Modules/Accounts/Database/Migrations/2027_02_17_120000_a_voucher_was_posted_by_its_writer.php'))->up();

        foreach (Company::query()->pluck('id') as $id) {
            $this->assertSame((int) $id === (int) $kept->id ? '1' : '0',
                (string) DB::table('settings')->where('company_id', $id)->where('key', $key)->value('value'),
                '⛔ কোম্পানি '.$id.'-এ সুইচ ভুল লেখা হলো।');
        }
    }

    private function marks(Voucher $voucher): int
    {
        return DB::table('audit_trails')->where('auditable_type', Voucher::class)->where('auditable_id', $voucher->id)
            ->where('action', 'maker_checker_override')->count();
    }

    private function switchTo(bool $on): void
    {
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, $on);
    }

    private function clerk(): User
    {
        $user = User::factory()->create();
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id])->save();

        foreach (['accounts.report', 'accounts.voucher.create', 'accounts.voucher.update'] as $key) {
            $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function journal(array $extra = []): array
    {
        [$a, $b] = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();

        return [
            'type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'মাসশেষের সমন্বয়',
            'lines' => [['account_id' => $a->id, 'debit' => '100', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '100']],
            ...$extra,
        ];
    }
}

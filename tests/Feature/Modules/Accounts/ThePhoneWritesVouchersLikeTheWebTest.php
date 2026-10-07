<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Sync\SyncService;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\SyncChange;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনে অফিসের লোকের ভাউচার, ওয়েবের নিয়মে — মালিক, ৭ অক্টোবর ২০২৬: "সব ভাউচার দেওয়ার কথা ছিল, সেগুলোও দেয়নি"।
 *
 * দাবি: লেখার চাবি ছাড়া কিছুই নয় (একই মানুষ চাবি পেলে লেখেন); ফোনের লেখা ভাউচার ওয়েবের অনুরোধের নিয়মে যাচাই হয়
 * আর ওয়েবের একই পথে বসে ([[VoucherSync]], [[VoucherWriter]]) — লেখক নিজে পাকা করেন না, অন্য হাত ফোনেই করেন;
 * সই লাগলে খসড়া সইয়ের অপেক্ষায়, পাকা নয়; একই ভাউচার দুবার পৌঁছালে একবার; খারাপ সারি একা ফেরে; ক্যাশিয়ার কেবল
 * নিজের লেখা দেখেন।
 */
final class ThePhoneWritesVouchersLikeTheWebTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, true);
    }

    /** একই মানুষ: লেখার চাবি ছাড়া দরজা আর সিঙ্ক দুটোই বন্ধ; চাবি পেলে লেখেন */
    public function test_without_the_write_key_nothing_and_with_it_a_voucher(): void
    {
        $reader = $this->person(['accounts.report']);

        $this->phone($reader)->getJson('/api/v1/accounts/vouchers/setup?type=journal')->assertForbidden();
        $this->phone($reader)->getJson('/api/v1/accounts/vouchers')->assertForbidden();
        $before = Voucher::query()->count();
        $out = $this->push($reader, [$this->change('k-1', $this->journal())]);
        $this->assertSame(SyncChange::REJECTED, $out[0]['status'], '⛔ লেখার চাবি ছাড়া ফোন থেকে ভাউচার বসল।');
        $this->assertSame($before, Voucher::query()->count());

        $this->grant($reader, 'accounts.voucher.create');
        $out = $this->push($reader->fresh(), [$this->change('k-2', $this->journal())]);
        $this->assertSame(SyncChange::APPLIED, $out[0]['status'], json_encode($out, JSON_UNESCAPED_UNICODE));
        $this->assertSame($before + 1, Voucher::query()->count());
    }

    /** লেখক ≠ পাকাকারী: লেখকের ভাউচার খসড়া, লেখক পাকা করতে পারেন না, অন্য হাত ফোনেই পারেন; একই সারি দুবার — একটাই */
    public function test_the_writer_leaves_a_draft_another_hand_posts_it_and_twice_is_once(): void
    {
        $writer = $this->person(['accounts.voucher.create', 'accounts.voucher.update', 'accounts.report']);
        $checker = $this->person(['accounts.voucher.create', 'accounts.voucher.update', 'accounts.report']);

        $out = $this->push($writer, [$this->change('w-1', $this->journal())]);
        $this->assertSame(SyncChange::APPLIED, $out[0]['status'], json_encode($out, JSON_UNESCAPED_UNICODE));
        $id = $out[0]['entityId'];
        $voucher = Voucher::query()->where('public_id', $id)->firstOrFail();
        $this->assertTrue($voucher->isDraft(), '⛔ লেখকের ভাউচার ফোন থেকে নিজে পাকা হলো।');
        $this->assertSame((int) $writer->id, (int) $voucher->created_by);

        $page = $this->phone($writer)->getJson('/api/v1/accounts/vouchers/'.$id)->assertOk()->json();
        $this->assertSame('draft', $page['state']);
        $this->assertFalse($page['can_post']);
        $this->assertTrue($page['awaits_another_hand']);
        $this->phone($writer)->postJson('/api/v1/accounts/vouchers/'.$id.'/post')->assertStatus(422);
        $this->assertTrue($voucher->fresh()->isDraft(), '⛔ লেখক ফোনে নিজের ভাউচার পাকা করলেন।');

        $this->assertTrue($this->phone($checker)->getJson('/api/v1/accounts/vouchers/'.$id)->json('can_post'));
        $posted = $this->phone($checker)->postJson('/api/v1/accounts/vouchers/'.$id.'/post')->assertOk()->json();
        $this->assertTrue($posted['posted']);
        $this->assertSame('posted', $posted['voucher']['state']);
        $this->assertTrue($voucher->fresh()->isPosted());

        $before = Voucher::query()->count();
        $again = $this->push($writer, [$this->change('w-1', $this->journal())]);
        $this->assertSame(SyncChange::DUPLICATE, $again[0]['status']);
        $this->assertSame($id, $again[0]['entityId']);
        $this->assertSame($before, Voucher::query()->count(), '⛔ একই ভাউচার দুবার বসল।');
    }

    /** সই লাগলে খসড়া সইয়ের অপেক্ষায় — অন্য হাতেও পাকা হয় না, কারণসহ */
    public function test_a_signature_flow_holds_it_as_awaiting_and_post_says_why(): void
    {
        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => 'accounts', 'action' => 'journal', 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => User::factory()->create()->id]);
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);
        $writer = $this->person(['accounts.voucher.create', 'accounts.voucher.update', 'accounts.report']);

        $out = $this->push($writer, [$this->change('s-1', $this->journal())]);
        $this->assertSame(SyncChange::APPLIED, $out[0]['status'], json_encode($out, JSON_UNESCAPED_UNICODE));
        $voucher = Voucher::query()->where('public_id', $out[0]['entityId'])->firstOrFail();
        $this->assertTrue($voucher->isDraft(), '⛔ সইয়ের ছক থাকতেও ফোনের ভাউচার পাকা হলো।');

        $this->assertSame('awaiting', $this->phone($writer)->getJson('/api/v1/accounts/vouchers/'.$voucher->public_id)->json('state'));
        $this->assertContains((string) $voucher->public_id,
            collect($this->phone($writer)->getJson('/api/v1/accounts/vouchers?awaiting=1')->json('rows'))->pluck('id')->all());

        $post = $this->phone($writer)->postJson('/api/v1/accounts/vouchers/'.$voucher->public_id.'/post')->assertOk()->json();
        $this->assertFalse($post['posted']);
        $this->assertTrue($voucher->fresh()->isDraft(), '⛔ সই ছাড়া ফোনের পাকা করার দরজায় পাকা হলো।');
    }

    /** ⭐ এক পথ — ওয়েবে সম্পাদনা করে "সংরক্ষণ ও পোস্ট" করলেও সই ঝুলে থাকা ভাউচার খসড়াই থাকে ([[VoucherWriter::update()]]) */
    public function test_editing_a_held_voucher_on_the_web_still_waits_for_the_signature(): void
    {
        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => 'accounts', 'action' => 'journal', 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => 'user', 'approver_id' => User::factory()->create()->id]);
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);
        $writer = $this->person(['accounts.voucher.create', 'accounts.voucher.update', 'accounts.report']);

        $out = $this->push($writer, [$this->change('e-1', $this->journal())]);
        $voucher = Voucher::query()->where('public_id', $out[0]['entityId'])->firstOrFail();
        $this->assertTrue($voucher->isDraft(), 'প্রস্তুতিটাই ভুল — সই ছাড়াই পাকা।');

        $this->app['auth']->forgetGuards();
        $this->actingAs($writer->fresh());
        $this->put(route('accounts.voucher.update', $voucher), [...$this->journal(), 'narration' => 'আবার লেখা'])->assertSessionHasNoErrors();
        $this->assertTrue($voucher->fresh()->isDraft(), '⛔ সম্পাদনা করে সংরক্ষণেই সই এড়িয়ে পাকা হলো।');
        $this->assertSame('আবার লেখা', $voucher->fresh()->narration);
    }

    /** ওয়েবের অনুরোধের নিয়ম — প্রতিটা খারাপ সারি একা ফেরে, ভালোটা বসে */
    public function test_the_webs_rules_refuse_each_bad_row_alone(): void
    {
        app(SettingsService::class)->set('accounts.require_narration', true);
        $writer = $this->person(['accounts.voucher.create', 'accounts.report']);
        $payable = $this->payable();
        $expense = Account::query()->postable()->active()->where('type', Account::EXPENSE)->orderBy('code')->firstOrFail();

        $unbalanced = $this->journal();
        $unbalanced['lines'][1]['credit'] = '90';
        $before = Voucher::query()->count();

        $out = $this->push($writer, [
            $this->change('b-1', $unbalanced),
            $this->change('b-2', array_diff_key($this->journal(), ['narration' => 1])),
            // ⓘ বাকিতে খরচ — দেনায় ক্রেডিট, পক্ষ নেই
            $this->change('b-3', ['type' => Voucher::EXPENSE, 'trx_date' => now()->toDateString(), 'narration' => 'হাম্মালি',
                'from_account_id' => $payable->id, 'to_account_id' => $expense->id, 'amount' => '500']),
            $this->change('b-4', array_merge($this->journal(), ['type' => 'gift'])),
            $this->change('b-5', array_merge($this->journal(), ['trx_date' => now()->addDay()->toDateString()])),
            $this->change('b-6', $this->journal()),
        ]);

        $this->assertSame(['REJECTED', 'REJECTED', 'REJECTED', 'REJECTED', 'REJECTED', 'APPLIED'], array_column($out, 'status'),
            json_encode($out, JSON_UNESCAPED_UNICODE));
        $this->assertSame($before + 1, Voucher::query()->count(), '⛔ ফেরত সারিরও খসড়া রয়ে গেল।');
        $this->assertSame((string) __('accounts::validation.credit_needs_a_party'), $out[2]['message'] ?? null,
            '⛔ বাকিতে খরচ অন্য কারণে ফিরল — পক্ষের নিয়ম মাপা হলো না।');
        $this->assertNotSame('', (string) ($out[1]['message'] ?? ''), 'বিবরণ ছাড়া ফেরার কারণ নেই।');
    }

    /** ক্যাশিয়ার (লেখার চাবি, রিপোর্টের নয়) কেবল নিজের লেখা দেখেন; রিপোর্টের চাবিতে সব */
    public function test_a_cashier_sees_only_their_own_and_a_reader_sees_all(): void
    {
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);
        $cashier = $this->person(['accounts.voucher.create']);
        $other = $this->person(['accounts.voucher.create', 'accounts.report']);

        $mine = $this->push($cashier, [$this->change('c-1', $this->journal())])[0]['entityId'];
        $theirs = $this->push($other, [$this->change('c-2', $this->journal())])[0]['entityId'];

        $ids = collect($this->phone($cashier)->getJson('/api/v1/accounts/vouchers')->assertOk()->json('rows'))->pluck('id');
        $this->assertTrue($ids->contains($mine));
        $this->assertFalse($ids->contains($theirs), '⛔ ক্যাশিয়ার অন্যের ভাউচার দেখলেন।');
        $this->phone($cashier)->getJson('/api/v1/accounts/vouchers/'.$theirs)->assertNotFound();

        $all = collect($this->phone($other)->getJson('/api/v1/accounts/vouchers')->json('rows'))->pluck('id');
        $this->assertTrue($all->contains($mine) && $all->contains($theirs));

        // ⓘ পাকা করার চাবি (accounts.voucher.update) ছাড়া পাকা করার দরজা বন্ধ
        $draft = $this->push($cashier, [$this->change('c-3', [...$this->journal(), 'save_as_draft' => true])])[0]['entityId'];
        $this->phone($cashier)->postJson('/api/v1/accounts/vouchers/'.$draft.'/post')->assertForbidden();
    }

    /** "খসড়া রাখুন" — সুইচ বন্ধেও, সই ছাড়াও খসড়াই; সইয়ের অপেক্ষার তালিকায় সাধারণ খসড়া নয় */
    public function test_keep_as_draft_stays_a_draft_and_is_not_awaiting(): void
    {
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);
        $writer = $this->person(['accounts.voucher.create', 'accounts.voucher.update']);

        $out = $this->push($writer, [$this->change('d-1', [...$this->journal(), 'save_as_draft' => true])]);
        $voucher = Voucher::query()->where('public_id', $out[0]['entityId'])->firstOrFail();
        $this->assertTrue($voucher->isDraft(), '⛔ "খসড়া রাখুন" চেয়েও পাকা হলো।');

        $page = $this->phone($writer)->getJson('/api/v1/accounts/vouchers/'.$voucher->public_id)->json();
        $this->assertSame('draft', $page['state']);
        $this->assertTrue($page['can_post']);
        $this->assertSame([], $this->phone($writer)->getJson('/api/v1/accounts/vouchers?awaiting=1')->json('rows'),
            '⛔ সই ছাড়া খসড়া "সইয়ের অপেক্ষায়" তালিকায়।');
    }

    /** খাতের তালিকা ওয়েবের ফর্মের: জাবেদায় দলহীন সব খাত; খরচের "কোথা থেকে"-তে টাকার খাত আর দেনা, "কোথায়"-তে খরচের খাত */
    public function test_the_setup_carries_the_webs_lists_for_each_type(): void
    {
        $writer = $this->person(['accounts.voucher.create']);

        $journal = $this->phone($writer)->getJson('/api/v1/accounts/vouchers/setup?type=journal')->assertOk()->json();
        $ids = collect($journal['accounts'])->pluck('id');
        $this->assertNotEmpty($ids);
        $this->assertSame(0, Account::query()->whereIn('id', $ids)->where('is_group', true)->count(), '⛔ দল-খাত জাবেদার তালিকায়।');

        $expense = $this->phone($writer)->getJson('/api/v1/accounts/vouchers/setup?type=expense')->assertOk()->json();
        $to = collect($expense['to']['accounts'])->pluck('id');
        $this->assertSame($to->count(), Account::query()->whereIn('id', $to)->where('type', Account::EXPENSE)->count(), '⛔ খরচের খাতে অন্য খাত।');
        $payable = $this->payable()->id;
        $this->assertContains((int) $payable, collect($expense['from']['accounts'])->pluck('id')->all(), '⛔ বাকিতে খরচের দেনা নেই।');

        $this->phone($writer)->getJson('/api/v1/accounts/vouchers/setup?type=gift')->assertStatus(422);
    }

    // ── যন্ত্রপাতি ─────────────────────────────────────────────────────────

    /** @param  list<string>  $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id])->save();
        foreach ($keys as $key) {
            $this->grant($user, $key);
        }

        return $user->fresh();
    }

    private function grant(User $user, string $key): void
    {
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate($key, 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function phone(User $user): self
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function push(User $user, array $rows): array
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user->fresh());

        return app(SyncService::class)->push($user->fresh(), 'phone-v', 'accounts', $rows);
    }

    /** @param  array<string, mixed>  $payload */
    private function change(string $id, array $payload): array
    {
        return ['changeId' => $id, 'entityType' => 'Voucher', 'operation' => 'CREATE', 'payloadJson' => json_encode($payload)];
    }

    /** দেনার দলের (২১১০) একটা পোস্টযোগ্য সন্তান — বাকিতে খরচের খাত */
    private function payable(): Account
    {
        return Account::query()->postable()->active()
            ->whereIn('parent_id', Account::query()->where('code', StandardChart::PAYABLE_GROUP)->select('id'))
            ->orderBy('code')->firstOrFail();
    }

    /** @return list<Account> */
    private function twoAccounts(): array
    {
        return Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();
    }

    /** @return array<string, mixed> */
    private function journal(): array
    {
        [$a, $b] = $this->twoAccounts();

        return [
            'type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'মাসশেষের সমন্বয়',
            'lines' => [['account_id' => $a->id, 'debit' => '100', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '100']],
        ];
    }
}

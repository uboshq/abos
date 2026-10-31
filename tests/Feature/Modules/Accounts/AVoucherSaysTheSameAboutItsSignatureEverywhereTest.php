<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\ListSummary;
use App\Http\Controllers\Api\AuthController;
use App\Models\Approval;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\VoucherApproval;
use App\Modules\Accounts\Services\VoucherSigning;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ একটা ভাউচারের সইয়ের কথা সব জায়গায় এক — পাতার নোট, ফোনের সারি, ওয়েবের অপেক্ষার তালিকা আর তালিকার মোট
 * ([[VoucherApproval::decide()]]; তালিকার মোটের চুক্তি v6 — fe, a4, a6, ২৪ অক্টোবর ২০২৬)।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────────
 * ফোন আর ওয়েবের অপেক্ষার তালিকা যেকোনো PENDING অনুরোধকেই "সইয়ের অপেক্ষায়" বলত — অনুরোধের পরে ভাউচার বদলালেও, অথচ পরের
 * পোস্টে সেই বাসি অনুরোধ তুলে নতুন চাওয়া হয় ([[VoucherApproval::stopping()]], RCV-0090)। আর পাতার নোট "অনুরোধই হয়নি" আর
 * "অনুরোধ গেছে" দুটোকেই 'awaiting' বলত। তালিকার মোট যখন বাসি আর না-পাঠানো খসড়াকে খসড়ায় গোনে, তখন একই কাগজ নিয়ে দুই কথা হত।
 *
 * ── সাত অবস্থা, এক সারণি ────────────────────────────────────────────────
 *   সই লাগে না · শুধু ছক (needs) · টাটকা অনুরোধ (awaiting) · বাসি অনুরোধ (stale) · ফেরানো (rejected) · ফেরানোর পরে বদলানো
 *   · সই আছে অথচ খসড়া (signed)। পাতা awaiting → মোটে pending, rejected → rejected, বাকি সব → draft।
 */
final class AVoucherSaysTheSameAboutItsSignatureEverywhereTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $company = Company::query()->findOrFail($this->owner->current_company_id);
        CompanyContext::set($company->id, $this->owner->current_branch_id ?? $company->defaultBranch()?->id);
        $this->actingAs($this->owner);

        // ⓘ ডেমোর নিজের ছক সরিয়ে কেবল খরচে একটা — জাবেদায় ছক নেই, তাই "সই লাগে না"-ও দেখা যায়
        DB::table('approval_flows')->where('company_id', $company->id)->where('module', VoucherApproval::MODULE)->delete();
        $this->approver = User::query()->where('id', '!=', $this->owner->id)->firstOrFail();
        $this->flowOnExpenses((int) $company->id, (int) $this->approver->id);
        app()->forgetInstance(\App\Core\Engines\Approval\ApprovalEngine::class);
        app()->forgetInstance(VoucherApproval::class);
    }

    public function test_the_page_the_phone_the_waiting_list_and_the_total_agree_in_all_seven_states(): void
    {
        $seven = $this->sevenStates('');
        $approval = app(VoucherApproval::class);

        $expect = [
            'none' => [null, 'draft', null, false, ListSummary::DRAFT],
            'needs' => [VoucherSigning::NEEDS, 'draft', VoucherSigning::NEEDS, false, ListSummary::DRAFT],
            'awaiting' => [VoucherSigning::AWAITING, 'awaiting', VoucherSigning::AWAITING, true, ListSummary::PENDING],
            'stale' => [VoucherSigning::STALE, 'draft', VoucherSigning::STALE, false, ListSummary::DRAFT],
            'rejected' => [VoucherSigning::REJECTED, 'draft', VoucherSigning::REJECTED, false, ListSummary::REJECTED],
            'rejected_then_changed' => [VoucherSigning::NEEDS, 'draft', VoucherSigning::NEEDS, false, ListSummary::DRAFT],
            'signed' => [VoucherSigning::SIGNED, 'draft', VoucherSigning::SIGNED, false, ListSummary::DRAFT],
        ];

        $standing = $approval->standing(new Collection(array_map(fn (Voucher $v) => $v->fresh(), array_values($seven))));
        $webWaiting = $this->get(route('accounts.voucher.index', ['type' => Voucher::EXPENSE, 'awaiting' => 1]))->assertOk()->viewData('awaitingIds');

        foreach ($expect as $name => [$page, $state, $signing, $waiting, $group]) {
            $voucher = $seven[$name]->fresh();

            $this->assertSame($page, $approval->waitFor($voucher), "⛔ {$name}: পাতার নোটের উত্তর ভুল");
            $this->assertSame($group, VoucherSigning::GROUP_OF[$page ?? ''] ?? ListSummary::DRAFT, "⛔ {$name}: সারণির নিজের ভুল");
            $this->assertSame($group, $standing[$voucher->id] ?? null, "⛔ {$name}: তালিকার মোট পাতার নোট থেকে আলাদা দলে গুনল");

            $row = $this->phone()->getJson('/api/v1/accounts/vouchers/'.$voucher->public_id)->assertOk()->json();
            $this->assertSame($state, $row['state'], "⛔ {$name}: ফোনের state পাতার নোটের সঙ্গে মেলে না — বাসি অনুরোধকে অপেক্ষা বলছে?");
            $this->assertSame($signing, $row['signing'], "⛔ {$name}: ফোনের signing পাতার নোটের উত্তর নয়");

            if ($voucher->type === Voucher::EXPENSE) {
                $this->assertSame($waiting, in_array((int) $voucher->id, $webWaiting, true), "⛔ {$name}: ওয়েবের অপেক্ষার তালিকা পাতার নোটের সঙ্গে মেলে না");
            }

            $phoneWaiting = collect($this->phone()->getJson('/api/v1/accounts/vouchers?awaiting=1')->json('rows'))->pluck('id')->all();
            $this->assertSame($waiting, in_array((string) $voucher->public_id, $phoneWaiting, true), "⛔ {$name}: ফোনের অপেক্ষার ছাঁকনি পাতার নোটের সঙ্গে মেলে না");
        }
    }

    public function test_the_page_note_says_each_state_in_words(): void
    {
        $seven = $this->sevenStates('');
        $words = [
            'needs' => 'needs', 'awaiting' => 'awaiting', 'stale' => 'stale', 'rejected' => 'rejected', 'signed' => 'signed',
        ];

        foreach ($words as $name => $key) {
            $this->post(route('accounts.voucher.overview', $seven[$name]))->assertOk()
                ->assertSee(__('accounts::overview_confirm.'.$key), false);
        }

        $this->post(route('accounts.voucher.overview', $seven['stale']))->assertOk()
            ->assertDontSee(__('accounts::overview_confirm.awaiting'), false);
    }

    public function test_the_phone_list_total_counts_only_final_papers_and_carries_the_summary(): void
    {
        // ⓘ ডেমোর নিজের ভাউচার থাকতে পারে — তাই নমুনার আগে আর পরে, তফাতটা মাপা
        $before = $this->phone()->getJson('/api/v1/accounts/vouchers?from=2020-01-01')->assertOk()->json();

        $this->sevenStates('');
        $this->bare('POSTED-1', DocumentStatus::CONFIRMED, '100');
        $this->bare('GONE-1', DocumentStatus::CANCELLED, '70');

        $after = $this->phone()->getJson('/api/v1/accounts/vouchers?from=2020-01-01')->assertOk()->json();
        $grew = fn (string $group) => [
            'count' => $after['summary'][$group]['count'] - $before['summary'][$group]['count'],
            'total' => bcsub($after['summary'][$group]['total'], $before['summary'][$group]['total'], 4),
        ];

        $this->assertSame('100.00', bcsub($after['total'], $before['total'], 2), '⛔ ফোনের পুরনো total-এ পাকা ছাড়া অন্য কিছু ঢুকল — অপেক্ষা বা খসড়া মোটে');
        $this->assertSame(['count' => 1, 'total' => '100.0000'], $grew('counted'), '⛔ summary-র counted-এ পাকা ছাড়া অন্য কিছু');
        $this->assertSame(['count' => 1, 'total' => '750.0000'], $grew('pending'), '⛔ টাটকা অনুরোধ pending-এ এল না');
        $this->assertSame(['count' => 1, 'total' => '750.0000'], $grew('rejected'), '⛔ ফেরানো ভাউচার rejected-এ এল না');
        $this->assertSame(['count' => 1, 'total' => '70.0000'], $grew('cancelled'), '⛔ বাতিল নিজের লাইনে এল না');
        $this->assertSame(['count' => 5, 'total' => '3040.0000'], $grew('draft'),
            '⛔ বাসি, না-পাঠানো, ফেরানোর পরে বদলানো, সই-আছে আর সই-লাগে-না — পাঁচটাই খসড়া হওয়ার কথা');
        $this->assertSame(['from' => '2020-01-01', 'to' => null], $after['summary']['period'], '⛔ যে তারিখ খাটানো হলো, summary তা বলল না');
    }

    public function test_the_questions_do_not_grow_with_the_drafts(): void
    {
        $few = new Collection(array_values($this->sevenStates('A')));
        $many = new Collection([...$few->all(), ...array_values($this->sevenStates('B')), ...array_values($this->sevenStates('C'))]);

        $this->assertSame($this->questions($few), $this->questions($many),
            '⛔ খসড়া বাড়তেই সইয়ের ভাগে প্রশ্ন বাড়ল — সারি ধরে প্রশ্ন (N+1)');
    }

    /** @return array<string, Voucher> */
    private function sevenStates(string $tag): array
    {
        $approval = app(VoucherApproval::class);
        $out = ['none' => $this->bare('JV-NONE'.$tag, DocumentStatus::DRAFT, '40', Voucher::JOURNAL)];
        $out['needs'] = $this->expense('NEEDS'.$tag);

        foreach (['awaiting', 'stale', 'rejected', 'rejected_then_changed', 'signed'] as $name) {
            $out[$name] = $this->expense(strtoupper($name).$tag);
            $this->assertNotNull($approval->stopping($out[$name]->fresh()), "নমুনা: {$name}-এ অনুরোধ লেখা হলো না");
        }

        $decide = fn (Voucher $v, string $status) => Approval::query()->where('approvable_type', Voucher::class)->where('approvable_id', $v->id)
            ->update(['status' => $status, 'decided_at' => now()->subMinute()]);
        $decide($out['rejected'], Approval::REJECTED);
        $decide($out['rejected_then_changed'], Approval::REJECTED);
        $decide($out['signed'], Approval::APPROVED);

        foreach (['stale', 'rejected_then_changed'] as $name) {
            $out[$name]->fresh()->forceFill(['narration' => 'বদলানো '.$name.$tag])->save();
        }

        return $out;
    }

    private function expense(string $narration): Voucher
    {
        $this->actingAs($this->owner);
        $this->post(route('accounts.voucher.store', Voucher::EXPENSE), [
            'type' => Voucher::EXPENSE,
            'trx_date' => now()->toDateString(),
            'from_account_id' => (int) Account::query()->money()->postable()->active()->orderBy('code')->value('id'),
            'to_account_id' => (int) Account::query()->postable()->active()->where('type', Account::EXPENSE)->orderBy('code')->value('id'),
            'amount' => '750',
            'narration' => $narration,
            'save_as_draft' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Voucher::query()->where('narration', $narration)->latest('id')->firstOrFail();
    }

    private function bare(string $no, string $status, string $amount, string $type = Voucher::JOURNAL): Voucher
    {
        return Voucher::query()->create([
            'company_id' => CompanyContext::id(),
            'branch_id' => CompanyContext::branchId(),
            'financial_year_id' => (int) FinancialYear::query()->where('company_id', CompanyContext::id())->value('id'),
            'type' => $type,
            'document_no' => $no,
            'trx_date' => now()->toDateString(),
            'amount' => $amount,
            'status' => $status,
        ]);
    }

    private function questions(Collection $vouchers): int
    {
        $fresh = new Collection($vouchers->map(fn (Voucher $v) => Voucher::query()->findOrFail($v->id))->all());
        $counting = true;
        $count = 0;
        DB::listen(function () use (&$count, &$counting) {
            $count += $counting ? 1 : 0;
        });

        app(VoucherApproval::class)->standing($fresh);
        $counting = false;

        return $count;
    }

    private function phone(): self
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner->fresh(), [AuthController::APP]);

        return $this;
    }

    private function flowOnExpenses(int $companyId, int $approverId): void
    {
        $flowId = DB::table('approval_flows')->insertGetId([
            'company_id' => $companyId, 'module' => VoucherApproval::MODULE, 'action' => Voucher::EXPENSE, 'document_type' => '',
            'threshold_amount' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            'public_id' => (string) Str::uuid(),
        ]);

        DB::table('approval_flow_steps')->insert([
            'approval_flow_id' => $flowId, 'level' => 1, 'approver_type' => 'user', 'approver_id' => $approverId,
            'requires_all' => false, 'created_at' => now(), 'updated_at' => now(), 'public_id' => (string) Str::uuid(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Accounts\Services\VoucherWriter;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ফোনের সই-বাক্স আর "আমার অনুরোধ" জানত না কোন ধাপে — a6, ২০ অক্টোবর ২০২৬।
 *
 * ⓘ ভাউচার আর দাবির সারির মতো ([[ThePhoneChipKnewNeitherTheStepNorWhyItStuckTest]]) অনুমোদনের দরজাতেও `approval_level` /
 * `approval_levels` — ইঞ্জিনের একই উৎস ([[ApprovalEngine::standingOf()]]), অপেক্ষায় না থাকলে null।
 *
 * দাবি:
 *  - দুই ধাপের অনুরোধ: "আমার অনুরোধ"-এ আর ধাপ ১-এর সইকারীর বাক্সে (আর তাঁর বিস্তারিত পাতায়) ২-এর ১; ধাপ ২-এর সইকারীর বাক্সে
 *    তখনো নেই। ধাপ ১ ফোনে সই দিলে উত্তরেই ২-এর ২, আর ধাপ ২-এর সইকারীর বাক্সে আর "আমার অনুরোধ"-এ ২-এর ২। শেষ সইয়ে "আমার অনুরোধ"-এ দুটোই null।
 *  - ⛔ সারি ধরে কোয়েরি নয় — "আমার অনুরোধ" ২ আর ৬ সারিতে একই সংখ্যক কোয়েরি; সই-বাক্সে ছকের (`approval_flow…`) প্রশ্ন ২ আর ৬ সারিতে একই।
 *    ⓘ সই-বাক্সের মোট কোয়েরি তুলনা হয় না: "সংশোধনে ফেরত" বোতামের হিসাব ([[ApprovalEngine::canSendBack()]]) আগে থেকেই সারি ধরে
 *    কাগজটা পড়ে — এই কাজের বাইরের পুরনো কথা। ধাপের সাহায্যকারী দুই তালিকায় একই ([[ApprovalApiController::standing()]]), তাই
 *    "আমার অনুরোধ"-এর মোট তুলনাই তার পাহারা।
 */
final class TheSigningBoxKnewNotWhichStepTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $first;

    private User $second;

    private User $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(SettingsService::class)->set(VoucherService::MAKER_CHECKER, false);
        app(SettingsService::class)->set(PhoneModules::PREFIX.'approval', true);

        $this->first = $this->person(['approval.decide', 'approval.view']);
        $this->second = $this->person(['approval.decide', 'approval.view']);
        $this->writer = $this->person(['accounts.voucher.create', 'approval.view']);

        $flow = ApprovalFlow::create(['company_id' => $this->company->id, 'module' => 'accounts', 'action' => 'journal', 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->first->id]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 2, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->second->id]);
        $this->app->forgetInstance(ApprovalEngine::class);
    }

    public function test_a_two_step_request_says_one_of_two_then_two_of_two_then_nothing_once_signed(): void
    {
        $approval = $this->asked();

        $this->assertLevel($this->mineRow($approval), 1, 2, '⛔ "আমার অনুরোধ" বলে না যে অনুরোধ ধাপ ১-এ, দুই ধাপের মধ্যে।');
        $this->assertLevel($this->inboxRow($this->first, $approval), 1, 2, '⛔ ধাপ ১-এর সইকারীর বাক্স "২-এর ১" বলে না।');
        $this->phone($this->first);
        $sheet = $this->getJson('/api/v1/approvals/'.$approval->public_id.'/sheet')->assertOk()->json();
        $this->assertLevel($sheet, 1, 2, '⛔ সইয়ের আগের বিস্তারিত পাতা ধাপ বলে না।');
        $this->assertNull($this->inboxRow($this->second, $approval, false), 'পরীক্ষার ভিত্তি — ধাপ ২-এর সইকারীর পালা এখনো নয়');

        // ⓘ ফোন থেকেই সই — সিদ্ধান্তের উত্তরও পরের ধাপ বলে ([[ApprovalApiController::outcome()]])
        $this->phone($this->first);
        $this->app->forgetInstance(ApprovalEngine::class);
        $signed = $this->postJson('/api/v1/approvals/'.$approval->public_id.'/approve')->assertOk()->json();
        $this->assertSame(Approval::PENDING, $signed['status'], 'পরীক্ষার ভিত্তি — প্রথম সইয়ের পরে অনুরোধ অপেক্ষায়');
        $this->assertLevel($signed, 2, 2, '⛔ প্রথম সইয়ের উত্তর বলে না যে অনুরোধ এখন ধাপ ২-এ।');

        $this->assertLevel($this->inboxRow($this->second, $approval), 2, 2, '⛔ ধাপ ২-এর সইকারীর বাক্স "২-এর ২" বলে না।');
        $this->assertLevel($this->mineRow($approval), 2, 2, '⛔ প্রথম সইয়ের পরে "আমার অনুরোধ" এখনো ধাপ ১ বলে।');

        $this->phone($this->second);
        $this->app->forgetInstance(ApprovalEngine::class);
        $last = $this->postJson('/api/v1/approvals/'.$approval->public_id.'/approve')->assertOk()->json();
        $this->assertSame(Approval::APPROVED, $last['status']);
        $this->assertArrayHasKey('approval_level', $last);
        $this->assertNull($last['approval_level'], '⛔ শেষ সইয়ের উত্তর এখনো ধাপ বলে।');

        $done = $this->mineRow($approval);
        $this->assertSame(Approval::APPROVED, $done['status'], 'পরীক্ষার ভিত্তি — শেষ সইয়ে অনুমোদিত');
        $this->assertArrayHasKey('approval_level', $done);
        $this->assertNull($done['approval_level'], '⛔ সই শেষ হওয়া অনুরোধ এখনো ধাপ বলে।');
        $this->assertNull($done['approval_levels'], '⛔ সই শেষ হওয়া অনুরোধ এখনো ধাপ বলে।');
    }

    public function test_both_lists_ask_no_more_for_six_rows_than_for_two(): void
    {
        foreach (range(1, 2) as $i) {
            $this->asked();
        }

        $this->listQueries($this->writer, '/api/v1/approvals/mine'); // ⓘ গরম করা — সেটিং আর চাবির প্রথম পড়া মাপের বাইরে
        $this->listQueries($this->first, '/api/v1/approvals/pending');
        [$mineTwo] = $this->listQueries($this->writer, '/api/v1/approvals/mine', 2);
        [, $flowsTwo] = $this->listQueries($this->first, '/api/v1/approvals/pending', 2);

        foreach (range(1, 4) as $i) {
            $this->asked();
        }

        [$mineSix] = $this->listQueries($this->writer, '/api/v1/approvals/mine', 6);
        [, $flowsSix] = $this->listQueries($this->first, '/api/v1/approvals/pending', 6);

        $this->assertSame($mineTwo, $mineSix, "⛔ \"আমার অনুরোধ\" সারি ধরে কোয়েরি বাড়ায়: ২ সারিতে {$mineTwo}, ৬ সারিতে {$mineSix}।");
        $this->assertSame($flowsTwo, $flowsSix, "⛔ সই-বাক্স সারি ধরে ছক তোলে: ২ সারিতে {$flowsTwo}, ৬ সারিতে {$flowsSix}।");
    }

    // ── যন্ত্রপাতি ─────────────────────────────────────────────────────

    /**
     * একটা তালিকার কোয়েরি — সব মিলিয়ে, আর কেবল ছকের (`approval_flow…`) — ইঞ্জিন নতুন করে, যাতে ছক এই অনুরোধেই পড়া হয়।
     *
     * @return array{0: int, 1: int}
     */
    private function listQueries(User $user, string $url, ?int $rows = null): array
    {
        $this->phone($user);
        $this->app->forgetInstance(ApprovalEngine::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $answer = $this->getJson($url)->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        if ($rows !== null) {
            $this->assertCount($rows, $answer->json('rows'), 'পরীক্ষার ভিত্তি — সারির সংখ্যা');
            $this->assertSame(array_fill(0, $rows, 2), collect($answer->json('rows'))->pluck('approval_levels')->all(), 'পরীক্ষার ভিত্তি — ধাপের ঘর ভরা');
        }

        $flows = count(array_filter($log, fn (array $q) => str_contains((string) $q['query'], 'approval_flow')));

        return [count($log), $flows];
    }

    /** @param  array<string, mixed>  $answer */
    private function assertLevel(array $answer, int $level, int $levels, string $why): void
    {
        $this->assertSame($level, $answer['approval_level'] ?? null, $why);
        $this->assertSame($levels, $answer['approval_levels'] ?? null, $why);
    }

    /** @return array<string, mixed> */
    private function mineRow(Approval $approval): array
    {
        $this->phone($this->writer);
        $row = collect($this->getJson('/api/v1/approvals/mine')->assertOk()->json('rows'))->firstWhere('id', $approval->public_id);
        $this->assertIsArray($row, 'পরীক্ষার ভিত্তি — অনুরোধটা লেখকের "আমার অনুরোধ"-এ');

        return $row;
    }

    /** @return array<string, mixed>|null */
    private function inboxRow(User $signer, Approval $approval, bool $mustBeThere = true): ?array
    {
        $this->phone($signer);
        $this->app->forgetInstance(ApprovalEngine::class);
        $row = collect($this->getJson('/api/v1/approvals/pending')->assertOk()->json('rows'))->firstWhere('id', $approval->public_id);

        if ($mustBeThere) {
            $this->assertIsArray($row, 'পরীক্ষার ভিত্তি — অনুরোধটা এই সইকারীর বাক্সে');
        }

        return $row;
    }

    /** লেখকের জাবেদা, সইয়ের অপেক্ষায় — আসল পথে ([[VoucherWriter::post()]]) */
    private function asked(): Approval
    {
        [$a, $b] = Account::query()->postable()->active()->whereNull('money_kind')->where('code', 'like', '5%')->orderBy('code')->take(2)->get()->all();

        $this->as($this->writer);
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'দুই ধাপের সই',
                'branch_id' => $this->company->defaultBranch()->id],
            [['account_id' => $a->id, 'debit' => '500', 'credit' => '0'], ['account_id' => $b->id, 'debit' => '0', 'credit' => '500']],
        );

        $stopping = app(VoucherWriter::class)->post($voucher);
        $this->assertInstanceOf(Approval::class, $stopping, 'পরীক্ষার ভিত্তি — ভাউচার সই ছাড়াই পাকা হলো।');

        return $stopping->fresh();
    }

    private function phone(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);
    }

    private function as(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user->fresh());
    }

    /** @param  list<string>  $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id])->save();
        CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(
            array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys)));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}

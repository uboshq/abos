<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalLimit;
use App\Models\Company;
use App\Models\User;
use App\Modules\Approval\Services\ApprovalInbox;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ অনুমোদন ভাগ ৩, দফা ২১ — অ্যাপের অংশ, সার্ভারের দিক (পরিকল্পনার পাতা ৭, সারি ২১; পাতা ৬-এর তিন ছবি):
 * "অ্যাপে একই ট্যাব, একই নাম আর একই নিয়ম।"
 *
 * ⛔ আগে: ফোনের দরজা দিত একটাই তালিকা (`/approvals/pending`) — ট্যাব নেই, কাজের চিপ নেই, শেষ সময় নেই, ধাপ "২" (কয়টার মধ্যে,
 * বলে না), মোট নেই, একসাথে অনুমোদন নেই; কাগজের বিস্তারিতে যাত্রাপথ আর সংযুক্তি নেই। নিয়মগুলো ছিল কেবল ওয়েবের ইনবক্সে।
 *
 * দাবি:
 *   ২১চ — চার ট্যাব, ওয়েবের একই নাম আর একই গোনা; ট্যাব বদলালে সারিও বদলায়; বেতন কোনো ট্যাবে, সংখ্যায় বা মোটে নেই;
 *   ২১ছ — কার্ডে শেষ সময় আর ধাপ ওয়েবের একই হিসাবের ([[ApprovalInbox]]), কাজের চিপ আর দেরির সংখ্যা, দুই মোট; টাকা নড়ার
 *          কাজ একসাথে অনুমোদনের যোগ্য নয়; পুরনো অ্যাপের `pending` কার্ড বদলায়নি (একই ঘর, একই মান);
 *   ২১জ — কাগজের পাতা: সারাংশ (টাকা কথায়, ধাপ, শেষ সময়), যাত্রাপথ (তৈরির পরের ধাপ, কে কী বললেন), সংযুক্তি;
 *   ২১ঝ — সংযুক্তি খোলে কেবল সই-পাহারায় আর কেবল এই কাগজের — অন্য কাগজের ফাইলের নম্বর বসালে ৪০৪;
 *   ২১ঞ — একসাথে অনুমোদন: আগে-দেখায় কোনগুলো যাবে, মোট, কোনগুলো বাদ আর ওয়েবের একই কারণ-লেখায়; অনুমোদনে সার্ভার আবার
 *          দেখে — টাকা নড়ার কাজ আর বেতন কখনো যায় না, আগে-দেখায় কিছু জমা পড়ে না;
 *   ২১ছ২ — "সবগুলোর" মোট দুই পাতা জুড়ে গোনে, "এই পাতার" মোট কেবল এই পাতার;
 *   ২১ট — পরিকল্পনার সারি ২২ ফোনের একসাথে অনুমোদনেও: নিজের কাগজ "নিজের" কারণে বাদ, অন্য কোম্পানির কাগজ দেখা বা পাশ
 *          হয় না, আর প্রতিটা সই রেকর্ডে (কে, কী সিদ্ধান্ত, কী মন্তব্য, কখন)।
 */
final class ThePhoneInboxKeepsTheWebsRulesTest extends TestCase
{
    use RefreshDatabase;

    private Company $depot;

    private User $signer;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->depot = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->depot->id, $this->depot->defaultBranch()?->id);
        app()->setLocale('bn');

        $this->signer = $this->member('দফা ২১-এর অনুমোদনকারী', ['approval.decide', 'approval.view']);
        $this->clerk = $this->member('কেরানি', ['approval.view']);

        ApprovalFlow::query()->where('module', 'purchase')->whereIn('action', ['order', 'bill'])->delete();
        foreach (['order', 'bill'] as $action) {
            $flow = ApprovalFlow::create(['module' => 'purchase', 'action' => $action]);
            ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->signer->id]);
        }
        $payroll = ApprovalFlow::create(['module' => 'hr', 'action' => 'payroll']);
        ApprovalFlowStep::create(['approval_flow_id' => $payroll->id, 'level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->signer->id]);
        ApprovalEngine::flowsChanged();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_dofa21f_four_tabs_with_the_webs_names_and_counts(): void
    {
        $this->paper('PO-21-0001', 'order', '1000');
        $this->paper('PO-21-0002', 'order', '2000');
        $this->payroll();
        $sent = $this->paper('PO-21-0003', 'order', '3000', by: $this->signer);
        $done = $this->paper('PO-21-0004', 'order', '4000');
        ApprovalDecision::create(['approval_id' => $done->id, 'level' => 1, 'user_id' => $this->signer->id, 'decision' => ApprovalDecision::APPROVED, 'decided_at' => now()]);
        $done->forceFill(['status' => Approval::APPROVED, 'decided_at' => now()])->save();

        $inbox = $this->inbox()->assertOk();

        $this->assertSame(ApprovalInbox::TABS, array_column($inbox->json('tabs'), 'key'), '⛔ দফা ২১: ফোনের ট্যাব ওয়েবের চারটা নয়, বা ক্রম আলাদা।');
        foreach ($inbox->json('tabs') as $tab) {
            $this->assertSame(__('approval::field.tab_'.$tab['key']), $tab['label'], "⛔ দফা ২১: \"{$tab['key']}\" ট্যাবের নাম ওয়েবের নাম নয়।");
        }
        $this->assertSame(['waiting' => 2, 'sent' => 1, 'done' => 1, 'behalf' => 0], array_column($inbox->json('tabs'), 'count', 'key'),
            '⛔ দফা ২১: ট্যাবের গোনা ভুল — বেতন গোনায় ঢুকেছে, বা অন্য ট্যাবের কাগজ।');
        $this->assertSame(['PO-21-0001', 'PO-21-0002'], $this->numbers($inbox), '⛔ দফা ২১: অপেক্ষার ট্যাবে বেতন বা অন্য ট্যাবের কাগজ।');
        $this->assertSame('3000.0000', $inbox->json('totals.amount'), '⛔ দফা ২১: অপেক্ষার মোটে বেতনের ৮,৭৫,০০০ ঢুকেছে।');

        $this->assertSame([$sent->public_id], array_column($this->inbox('?tab=sent')->assertOk()->json('rows'), 'id'), '⛔ দফা ২১: "আমার পাঠানো" ট্যাব নিজের অনুরোধ দেখায় না।');
        $doneRows = $this->inbox('?tab=done')->assertOk()->json('rows');
        $this->assertSame([$done->public_id], array_column($doneRows, 'id'), '⛔ দফা ২১: "আমি অনুমোদন দিয়েছি" ট্যাব নিজের দেওয়া অনুমোদন দেখায় না।');
        $this->assertSame(__('approval::status.approved'), $doneRows[0]['statusLabel'], '⛔ দফা ২১: শেষ হওয়া কাগজ নিজের অবস্থা বলে না।');
        $this->inbox('?tab=elsewhere')->assertUnprocessable();
    }

    public function test_dofa21g_a_card_carries_the_webs_deadline_step_chips_and_totals(): void
    {
        $late = $this->paper('PO-21-0011', 'order', '1500.50', due: now()->subHours(3));
        $this->paper('PO-21-0012', 'order', '2499.50', due: now()->addDays(3));
        $bill = $this->paper('PO-21-0013', 'bill', '7000');

        $inbox = $this->inbox()->assertOk();
        $rows = collect($inbox->json('rows'))->keyBy('documentNo');

        $web = app(ApprovalInbox::class);
        $this->assertSame($web->deadlineOf($late->fresh()), $rows['PO-21-0011']['deadline'], '⛔ দফা ২১: কার্ডের শেষ সময় ওয়েবের হিসাব নয়।');
        $this->assertSame('danger', $rows['PO-21-0011']['deadline']['tone'] ?? null, '⛔ দফা ২১: পেরোনো শেষ সময় লাল নয়।');
        $this->assertNull($rows['PO-21-0013']['deadline'], '⛔ দফা ২১: ঘড়ি নেই এমন কাগজে শেষ সময় বানানো।');
        $this->assertSame(['at' => 1, 'of' => 1, 'name' => null], $rows['PO-21-0011']['step'], '⛔ দফা ২১: কার্ডে "ধাপ ১ / ১" নেই — কয়টার মধ্যে বলে না।');
        $this->assertSame('PO-21-0011', $this->numbers($inbox)[0], '⛔ দফা ২১: ফোনে "দেরি আগে" সাজানো নয় — পেরোনোটা প্রথমে আসার কথা।');

        $this->assertTrue($rows['PO-21-0011']['bulkable'], '⛔ দফা ২১: সাধারণ কাগজ একসাথে অনুমোদনের যোগ্য নয়।');
        $this->assertFalse($rows['PO-21-0013']['bulkable'], '⛔ দফা ২১: টাকা নড়ার কাজ (ক্রয় বিল) একসাথে অনুমোদনে বাছা যায়।');
        $this->assertSame(1, $inbox->json('lateCount'), '⛔ দফা ২১: দেরির সংখ্যা ভুল।');
        $this->assertSame([['key' => 'purchase', 'count' => 3]], array_map(fn ($m) => ['key' => $m['key'], 'count' => $m['count']], $inbox->json('modules')),
            '⛔ দফা ২১: কাজের চিপ নেই বা তার গোনা ভুল।');
        $this->assertSame(['pageCount' => 3, 'pageAmount' => '11000.0000', 'count' => 3, 'amount' => '11000.0000'], $inbox->json('totals'),
            '⛔ দফা ২১: দুই মোট ভুল — এই পাতার আর সবগুলোর।');

        $only = $this->inbox('?late=1')->assertOk();
        $this->assertSame(['PO-21-0011'], $this->numbers($only), '⛔ দফা ২১: "দেরি" বাছলে কেবল দেরিরটা আসে না।');
        $this->assertSame('1500.5000', $only->json('totals.amount'), '⛔ দফা ২১: "দেরি" বাছার পরে মোট পুরো তালিকার।');

        // ⓘ পুরনো অ্যাপের কার্ড — একই ঘর, একই মান; নতুন ঘর কেবল যোগ হয়েছে
        $this->as($this->signer);
        $old = collect($this->getJson('/api/v1/approvals/pending')->assertOk()->json('rows'))->keyBy('documentNo');
        foreach ($old as $no => $card) {
            $this->assertSame($card, array_intersect_key($rows[$no], $card), "⛔ দফা ২১: পুরনো অ্যাপের কার্ড বদলেছে ({$no}) — এক কাগজ দুই অ্যাপে দুই রকম।");
        }
    }

    public function test_dofa21g2_the_whole_list_total_counts_every_page_not_just_this_one(): void
    {
        // ⓘ এক পাতায় ২৫ — ২৬টা কাগজে দ্বিতীয় পাতা হয়, তখন "এই পাতার" আর "সবগুলোর" মোট আলাদা হতেই হবে
        foreach (range(1, 26) as $n) {
            $this->paper(sprintf('PO-21-1%03d', $n), 'order', '100');
        }

        $first = $this->inbox()->assertOk();
        $this->assertSame(2, $first->json('nextPage'), '⛔ দফা ২১: ২৬টা কাগজে দ্বিতীয় পাতা নেই।');
        $this->assertSame(['pageCount' => 25, 'pageAmount' => '2500.0000', 'count' => 26, 'amount' => '2600.0000'], $first->json('totals'),
            '⛔ দফা ২১: "সবগুলোর" মোট কেবল এই পাতার — দ্বিতীয় পাতার কাগজ গোনায় নেই।');
    }

    /**
     * ⭐ ফোনের নতুন ইনবক্স — মালিক, ৮ নভেম্বর ২০২৬, নমুনা "ok": খোঁজার ঘর ("নম্বর, পক্ষ বা কে চেয়েছেন"), সাজানোর পছন্দ, কার্ডে
     * "📎 3", চাওয়ার কারণ, আর "দিয়েছি" ট্যাবে সিদ্ধান্তের সময়।
     */
    public function test_the_new_phone_inbox_finds_by_number_and_party_sorts_and_counts_attachments(): void
    {
        Storage::fake('local');
        $this->paper('PO-21-0031', 'order', '100');
        $big = $this->paper('PO-21-0032', 'order', '9000');
        $big->update(['requested_reason' => 'মাসের শেষ, সরবরাহ বন্ধের নোটিশ']);
        $this->paper('PO-21-0033', 'order', '500');
        $this->attach($big, 'advice.txt');
        $this->attach($big, 'bill.txt');

        $this->assertSame(['PO-21-0032'], $this->numbers($this->inbox('?q=0032')->assertOk()), '⛔ নম্বর লিখে খুঁজলে কাগজটা আসে না।');

        $supplier = Supplier::query()->findOrFail(PurchaseOrder::query()->where('document_no', 'PO-21-0031')->value('supplier_id'));
        $byParty = $this->numbers($this->inbox('?q='.urlencode((string) $supplier->name_en))->assertOk());
        sort($byParty);
        $this->assertSame(['PO-21-0031', 'PO-21-0032', 'PO-21-0033'], $byParty, '⛔ পক্ষের নামে খুঁজলে তাঁর কাগজ আসে না।');
        $this->assertSame([], $this->numbers($this->inbox('?q=NOSUCHPAPER-9')->assertOk()), '⛔ না-মেলা লেখায়ও কাগজ আসে — খোঁজা ছাঁকছে না।');

        $this->assertSame(['PO-21-0032', 'PO-21-0033', 'PO-21-0031'], $this->numbers($this->inbox('?sort=amount')->assertOk()),
            '⛔ "বড় অঙ্ক আগে" সাজানো নয়।');
        $this->inbox('?sort=sideways')->assertStatus(422);

        $rows = collect($this->inbox()->assertOk()->json('rows'))->keyBy('documentNo');
        $this->assertSame(2, $rows['PO-21-0032']['attachments'], '⛔ কার্ডে সংযুক্তির সংখ্যা ভুল।');
        $this->assertSame(0, $rows['PO-21-0031']['attachments'], '⛔ সংযুক্তিহীন কাগজে সংখ্যা শূন্য নয়।');
        $this->assertSame('মাসের শেষ, সরবরাহ বন্ধের নোটিশ', $rows['PO-21-0032']['reason'], '⛔ চাওয়ার কারণ কার্ডে নেই।');
        $this->assertNull($rows['PO-21-0031']['reason'], '⛔ কারণ না থাকলে খালি লেখা যায়।');
        $this->assertArrayHasKey('decidedAt', $rows['PO-21-0031'], '⛔ সিদ্ধান্তের সময়ের ঘর নেই।');
        $this->assertNull($rows['PO-21-0031']['decidedAt'], '⛔ অপেক্ষার কাগজে সিদ্ধান্তের সময়।');
    }

    public function test_dofa21h_the_paper_page_has_the_summary_journey_and_attachments(): void
    {
        Storage::fake('local');
        $paper = $this->paper('PO-21-0021', 'order', '125000', due: now()->addHours(5));
        $this->attach($paper, 'bank-advice.txt');

        $this->as($this->signer);
        $sheet = $this->getJson('/api/v1/approvals/'.$paper->public_id.'/sheet')->assertOk();

        $this->assertSame(\App\Core\Support\AmountInWords::of('125000'), $sheet->json('summary.amountWords'), '⛔ দফা ২১: কাগজের পাতায় টাকা কথায় নেই।');
        $this->assertSame(['at' => 1, 'of' => 1, 'name' => null], $sheet->json('summary.step'), '⛔ দফা ২১: কাগজের পাতায় ধাপ নেই।');
        $this->assertSame(app(ApprovalInbox::class)->deadlineOf($paper->fresh()), $sheet->json('summary.deadline'), '⛔ দফা ২১: কাগজের পাতার শেষ সময় ওয়েবের হিসাব নয়।');
        $this->assertSame('কেরানি', $sheet->json('summary.requesterName'), '⛔ দফা ২১: কাগজের পাতা বলে না কে চেয়েছেন।');

        $journey = $sheet->json('journey');
        $this->assertSame([1], array_column($journey, 'level'), '⛔ দফা ২১: যাত্রাপথে ধাপগুলো নেই।');
        $this->assertSame('now', $journey[0]['state'], '⛔ দফা ২১: যাত্রাপথ বলে না কাগজ এখন কোন ধাপে।');

        $this->assertSame(['bank-advice.txt'], array_column($sheet->json('attachments'), 'name'), '⛔ দফা ২১: কাগজের পাতায় সংযুক্তি নেই।');
    }

    public function test_dofa21i_an_attachment_opens_only_for_its_own_paper_and_its_signer(): void
    {
        Storage::fake('local');
        $paper = $this->paper('PO-21-0031', 'order', '5000');
        $other = $this->paper('PO-21-0032', 'order', '6000');
        $mine = $this->attach($paper, 'slip.txt', 'the slip');
        $theirs = $this->attach($other, 'other.txt', 'someone else');

        $this->as($this->signer);
        $got = $this->get('/api/v1/approvals/'.$paper->public_id.'/attachments/'.$mine)->assertOk();
        $this->assertSame('the slip', $got->streamedContent(), '⛔ দফা ২১: সংযুক্তি খোলে না।');

        $this->assertSame(404, $this->get('/api/v1/approvals/'.$paper->public_id.'/attachments/'.$theirs)->baseResponse->getStatusCode(),
            '⛔ দফা ২১: নিজের কাগজের নম্বর দিয়ে অন্য কাগজের সংযুক্তি নামানো যায়।');

        $this->as($this->giveKeys($this->member('অন্য কেউ', []), ['approval.decide']));
        $this->assertSame(403, $this->get('/api/v1/approvals/'.$paper->public_id.'/attachments/'.$mine)->baseResponse->getStatusCode(),
            '⛔ দফা ২১: যিনি এই কাগজে সিদ্ধান্ত দেন না, তিনিও সংযুক্তি নামাতে পারেন।');
    }

    public function test_dofa21j_bulk_approval_shows_first_and_the_server_checks_again(): void
    {
        $a = $this->paper('PO-21-0041', 'order', '1000');
        $b = $this->paper('PO-21-0042', 'order', '2500');
        $bill = $this->paper('PO-21-0043', 'bill', '9000');
        $payroll = $this->payroll();
        $ids = [$a->public_id, $b->public_id, $bill->public_id, $payroll->public_id];

        // ⓘ সীমা ঠিক করা না থাকলে একসাথে অনুমোদন কিছুই নেয় না ("no_limit" — [[BulkApproval::review()]]-এর কড়া দিক)
        $role = Role::query()->create(['name' => 'দফা ২১-এর সীমা', 'guard_name' => 'web', 'company_id' => $this->depot->id]);
        CompanyContext::forCompany($this->depot->id, fn () => $this->signer->assignRole($role));
        ApprovalLimit::query()->create(['company_id' => $this->depot->id, 'role_id' => $role->id, 'max_amount' => '100000']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->as($this->signer->fresh());
        $preview = $this->postJson('/api/v1/approvals/bulk-preview', ['ids' => $ids])->assertOk();

        $this->assertSame([$a->public_id, $b->public_id], array_column($preview->json('sign'), 'id'), '⛔ দফা ২১: আগে-দেখায় "যাবে" তালিকা ভুল।');
        $this->assertSame('3500.0000', $preview->json('total'), '⛔ দফা ২১: আগে-দেখার মোট ভুল।');
        $this->assertSame([['id' => $bill->public_id, 'why' => 'money', 'reason' => __('approval::message.bulk_why_money')]],
            array_map(fn ($s) => ['id' => $s['id'], 'why' => $s['why'], 'reason' => $s['reason']], $preview->json('skip')),
            '⛔ দফা ২১: টাকা নড়ার কাজ বাদ পড়ে না, বা কারণ ওয়েবের লেখা নয়; বেতন আগে-দেখায় এসেছে।');
        $this->assertSame(Approval::PENDING, $a->fresh()?->status, '⛔ দফা ২১: আগে-দেখাতেই অনুমোদন জমা পড়েছে।');

        $done = $this->postJson('/api/v1/approvals/bulk-approve', ['ids' => $ids, 'remarks' => 'ঠিক আছে'])->assertOk();
        $this->assertSame(2, $done->json('done'), '⛔ দফা ২১: একসাথে অনুমোদনে দুইটা যায়নি।');
        $this->assertSame(['money' => 1], $done->json('skipped'), '⛔ দফা ২১: সার্ভার আবার দেখে না — টাকা নড়ার কাজ বাদের হিসাবে নেই।');
        $this->assertSame([Approval::APPROVED, Approval::APPROVED, Approval::PENDING, Approval::PENDING],
            [$a->fresh()?->status, $b->fresh()?->status, $bill->fresh()?->status, Approval::query()->withoutGlobalScopes()->whereKey($payroll->id)->value('status')],
            '⛔ দফা ২১: একসাথে অনুমোদনে টাকা নড়ার কাজ বা বেতন পাশ হয়েছে।');
    }

    public function test_dofa21k_the_phones_bulk_door_keeps_row_22_own_paper_other_company_and_the_record(): void
    {
        // ⭐ পরিকল্পনার সারি ২২ — নিজের কাগজে সই হয় না, অন্য কোম্পানির কাগজ নাগালের বাইরে, প্রতিটা সই রেকর্ডে (কে, কখন, কী মন্তব্য)
        $theirs = $this->paper('PO-21-0051', 'order', '1000');
        $own = $this->paper('PO-21-0052', 'order', '1200', by: $this->signer);
        $elsewhere = Company::query()->whereKeyNot($this->depot->id)->firstOrFail();
        $foreign = Approval::query()->withoutGlobalScopes()->create([
            'company_id' => $elsewhere->id,
            'approvable_type' => PurchaseOrder::class,
            'approvable_id' => (int) $theirs->approvable_id,
            'module' => 'purchase',
            'action' => 'order',
            'amount' => '700',
            'status' => Approval::PENDING,
            'current_level' => 1,
            'requested_by' => $this->clerk->id,
            'requested_at' => now()->subHours(2),
        ]);
        $ids = [$theirs->public_id, $own->public_id, $foreign->public_id];

        $role = Role::query()->create(['name' => 'দফা ২১-এর সীমা', 'guard_name' => 'web', 'company_id' => $this->depot->id]);
        CompanyContext::forCompany($this->depot->id, fn () => $this->signer->assignRole($role));
        ApprovalLimit::query()->create(['company_id' => $this->depot->id, 'role_id' => $role->id, 'max_amount' => '100000']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->as($this->signer->fresh());
        $preview = $this->postJson('/api/v1/approvals/bulk-preview', ['ids' => $ids])->assertOk();
        $this->assertSame([$theirs->public_id], array_column($preview->json('sign'), 'id'), '⛔ দফা ২১/সারি ২২: ফোনের আগে-দেখায় নিজের বা অন্য কোম্পানির কাগজ "যাবে" তালিকায়।');
        $this->assertSame([[$own->public_id, 'own']], array_map(fn ($s) => [$s['id'], $s['why']], $preview->json('skip')),
            '⛔ দফা ২১/সারি ২২: নিজের কাগজ "নিজের" কারণে বাদ পড়ে না, বা অন্য কোম্পানির কাগজ ফোনে দেখা যায়।');

        $done = $this->postJson('/api/v1/approvals/bulk-approve', ['ids' => $ids, 'remarks' => 'ফোন থেকে'])->assertOk();
        $this->assertSame(1, $done->json('done'), '⛔ দফা ২১/সারি ২২: ফোনের একসাথে অনুমোদনে নিজের বা অন্য কোম্পানির কাগজও গেছে।');
        $this->assertSame([Approval::PENDING, Approval::PENDING],
            [$own->fresh()?->status, Approval::query()->withoutGlobalScopes()->whereKey($foreign->id)->value('status')],
            '⛔ দফা ২১/সারি ২২: নিজের বা অন্য কোম্পানির কাগজ ফোন থেকে পাশ হয়েছে।');

        $record = ApprovalDecision::query()->where('approval_id', $theirs->id)->first();
        $this->assertSame([$this->signer->id, 'approved', 'ফোন থেকে'], [(int) $record?->user_id, $record?->decision, $record?->remarks],
            '⛔ দফা ২১/সারি ২২: ফোনের একসাথে অনুমোদন রেকর্ডে নেই — কে দিলেন, কী মন্তব্য, বলা যায় না।');
        $this->assertNotNull($record?->decided_at, '⛔ দফা ২১/সারি ২২: রেকর্ডে কখন সই হলো নেই।');
    }

    private function member(string $name, array $keys): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true, 'locale' => 'bn', 'current_company_id' => $this->depot->id]);
        $user->companies()->attach($this->depot->id, ['is_active' => true]);

        return $this->giveKeys($user, $keys);
    }

    private function giveKeys(User $user, array $keys): User
    {
        CompanyContext::forCompany($this->depot->id, fn () => $user->givePermissionTo(array_map(fn ($k) => Permission::findOrCreate($k, 'web'), $keys)));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /**
     * ⭐ নমুনা v4 (মালিক "ok", ৮ নভেম্বর ২০২৬): কাগজের পাতায় "কেন আপনার অনুমোদন লাগছে" — ইঞ্জিনের নিয়ম (কাজের নাম আর ধাপ কত
     * টাকা থেকে), ধাপের নম্বর আর নাম, আর যিনি চেয়েছেন তার কারণ; কারণ না থাকলে `null`, বানানো লেখা নয়।
     */
    public function test_the_paper_page_says_why_this_signer_is_asked(): void
    {
        $flow = ApprovalFlow::query()->where('module', 'purchase')->where('action', 'order')->firstOrFail();
        ApprovalFlowStep::query()->where('approval_flow_id', $flow->id)->update(['from_amount' => '100000', 'step_name' => 'ম্যানেজার']);
        app(ApprovalEngine::class)::flowsChanged();
        $paper = $this->paper('PO-21-0031', 'order', '125000');
        $paper->update(['requested_reason' => 'মাসের শেষ, সরবরাহ বন্ধের নোটিশ এসেছে']);
        $bare = $this->paper('PO-21-0032', 'order', '150000');

        $this->as($this->signer);
        $why = $this->getJson('/api/v1/approvals/'.$paper->public_id.'/sheet')->assertOk()->json('why');
        $this->assertIsArray($why, '⛔ কাগজের পাতায় "কেন আপনার অনুমোদন লাগছে" নেই');

        $what = app(\App\Core\Engines\Approval\ApprovalLabel::class)->of('purchase', 'order');
        $this->assertSame($what.' — ৳'.\App\Core\Support\Money::format('100000').' বা তার বেশি হলে অনুমোদন লাগে', $why['rule'],
            '⛔ নিয়মটা বলে না কোন কাজ আর কত টাকা থেকে অনুমোদন লাগে');
        $this->assertSame('ধাপ 1 · ম্যানেজার', $why['step'], '⛔ কোন ধাপে আর কোন নামে — পাতায় নেই');
        $this->assertSame('মাসের শেষ, সরবরাহ বন্ধের নোটিশ এসেছে', $why['reason'], '⛔ যিনি চেয়েছেন তার লেখা কারণ পাতায় আসে না');

        $this->assertNull($this->getJson('/api/v1/approvals/'.$bare->public_id.'/sheet')->assertOk()->json('why.reason'),
            '⛔ কারণ লেখা হয়নি, তবু পাতায় একটা কারণ বসল');

        ApprovalFlowStep::query()->where('approval_flow_id', $flow->id)->update(['from_amount' => null, 'step_name' => null]);
        app(ApprovalEngine::class)::flowsChanged();
        $plain = $this->getJson('/api/v1/approvals/'.$bare->public_id.'/sheet')->assertOk()->json('why');
        $this->assertSame($what.' — এই কাজে অনুমোদন লাগে', $plain['rule'], '⛔ টাকার সীমা ছাড়া ধাপেও "থেকে" লেখা বসল');
        $this->assertSame('ধাপ 1', $plain['step']);
    }

    private function paper(string $no, string $action, string $amount, ?User $by = null, $due = null): Approval
    {
        $po = PurchaseOrder::create([
            'company_id' => $this->depot->id,
            'branch_id' => $this->depot->defaultBranch()?->id,
            'document_no' => $no,
            'supplier_id' => Supplier::query()->value('id'),
            'trx_date' => now()->toDateString(),
            'total' => $amount,
            'narration' => 'দফা ২১-এর কাগজ',
            'created_by' => ($by ?? $this->clerk)->id,
        ]);

        return Approval::create([
            'company_id' => $this->depot->id,
            'approvable_type' => PurchaseOrder::class,
            'approvable_id' => $po->id,
            'module' => 'purchase',
            'action' => $action,
            'amount' => $amount,
            'status' => Approval::PENDING,
            'current_level' => 1,
            'requested_by' => ($by ?? $this->clerk)->id,
            'requested_at' => now()->subHours(6),
            'due_at' => $due,
        ]);
    }

    private function payroll(): Approval
    {
        $run = PayrollRun::create([
            'company_id' => $this->depot->id,
            'document_no' => 'PRL-21-0001',
            'month' => now()->format('Y-m'),
            'trx_date' => now()->toDateString(),
            'gross_total' => '900000',
            'deduction_total' => '25000',
            'net_total' => '875000',
            'employee_count' => 12,
            'created_by' => $this->clerk->id,
        ]);

        return Approval::create([
            'company_id' => $this->depot->id,
            'approvable_type' => PayrollRun::class,
            'approvable_id' => $run->id,
            'module' => 'hr',
            'action' => 'payroll',
            'amount' => '875000',
            'status' => Approval::PENDING,
            'current_level' => 1,
            'requested_by' => $this->clerk->id,
            'requested_at' => now()->subDays(2),
            'due_at' => now()->subDay(),
        ]);
    }

    private function attach(Approval $approval, string $name, string $content = 'a slip'): int
    {
        return (int) app(AttachmentEngine::class)->store(
            file: UploadedFile::fake()->createWithContent($name, $content),
            module: 'purchase',
            entity: PurchaseOrder::drillSourceType(),
            entityId: (int) $approval->approvable_id,
            userId: $this->clerk->id,
        )->id;
    }

    private function as(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user, [AuthController::APP]);
    }

    private function inbox(string $query = ''): TestResponse
    {
        $this->as($this->signer);

        return $this->getJson('/api/v1/approvals/inbox'.$query);
    }

    /** @return list<string|null> */
    private function numbers(TestResponse $response): array
    {
        return array_column($response->json('rows'), 'documentNo');
    }
}

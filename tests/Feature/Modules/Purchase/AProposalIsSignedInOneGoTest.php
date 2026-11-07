<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Core\Engines\Approval\BulkApproval;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Services\DirectPurchaseService;
use App\Modules\Purchase\Services\PaymentProposalService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ ভা-২৩ — একসাথে অনেক সরবরাহকারীকে পরিশোধ, এক সইয়ে (মালিকের চেকলিস্ট, ২৪ অক্টোবর ২০২৬; SAP F110-এর পেমেন্ট-রান অনুমোদন)।
 *
 * ⓘ প্রস্তাব আর ব্যাংক ফাইল আগেই লাইভে; বাকি ছিল সইটা — প্রতিটা সরবরাহকারীর কাগজে আলাদা সই দিতে হত।
 * এখন প্রস্তাবের পাতায় একটা বোতাম: পাতার তালিকাই দেখা, সই সেই দেখা তালিকা আর মোটের — বদলে গেলে কিছুই নয়।
 *
 * ⛔ সীমাটা সরু: ইনবক্সের "একসাথে সই" টাকার কাগজ আগের মতোই নেয় না (মালিক, ২৪ সেপ্টেম্বর ২০২৬), আর যিনি একা
 * সই দিতে পারতেন না (পালা নয়, নিজের কাগজ), তিনি বোতাম পান না।
 */
final class AProposalIsSignedInOneGoTest extends TestCase
{
    use \Tests\Concerns\PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $proposer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
        app(StandardChart::class)->install();
        $this->putMoneyIn(Account::query()->findOrFail($this->till()), '100000');

        $this->proposer = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $this->proposer->companies()->attach($this->company->id, ['is_active' => true]);

        // ⓘ সইদাতা মালিক — প্রস্তাবক অন্য একজন, তাই ভা-১৪-এর "নিজের কাগজে নয়" এখানে বাধা নয়
        $flow = ApprovalFlow::query()->create(['company_id' => $this->company->id, 'module' => 'purchase', 'action' => 'payment',
            'document_type' => '', 'threshold_amount' => null, 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1, 'step_name' => 'signer',
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->owner->id]);
    }

    public function test_one_signature_approves_every_supplier_the_signer_saw(): void
    {
        [$no, $waiting] = $this->twoSupplierProposal();

        [$ids, $total, $text] = $this->form($no);
        $this->assertSame($waiting, $ids, '⛔ বোতামটা প্রস্তাবের সব অপেক্ষার কাগজ নেয় না, বা অন্য কাগজও নেয়।');
        $this->assertSame(0, bccomp('160', $total, 4), '⛔ বোতামের মোট প্রস্তাবের মোট নয়।');
        $this->assertStringContainsString('160.00', $text, '⛔ বোতামে মোট টাকা লেখা নেই।');

        $this->post(route('purchase.payment_schedule.sign_all', $no), ['ids' => $ids, 'total' => $total])
            ->assertRedirect()->assertSessionHasNoErrors();

        foreach ($waiting as $id) {
            $approval = Approval::query()->findOrFail($id);
            $this->assertSame(Approval::APPROVED, $approval->status, "⛔ এক সইয়ের পরেও কাগজ {$id} অনুমোদিত নয়।");
        }

        $after = (string) $this->get(route('purchase.payment_schedule.proposal', $no))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-proposal-sign-all', $after, '⛔ সই শেষ, তবু "এক সইয়ে সবগুলো" বোতাম আছে।');
    }

    public function test_a_list_that_changed_after_it_was_seen_signs_nothing(): void
    {
        [$no, $waiting] = $this->twoSupplierProposal();
        [$ids, $total] = $this->form($no);

        // ⓘ দেখার পরে একটা কাগজ আলাদা সই পেল — তালিকা বদলেছে
        app(\App\Core\Engines\Approval\ApprovalEngine::class)->approve(Approval::query()->findOrFail($waiting[0]), $this->owner);

        $this->post(route('purchase.payment_schedule.sign_all', $no), ['ids' => $ids, 'total' => $total])
            ->assertRedirect()->assertSessionHasErrors('ids');
        $this->assertSame(Approval::PENDING, Approval::query()->findOrFail($waiting[1])->status,
            '⛔ দেখা তালিকা বদলে যাওয়ার পরেও বাকি কাগজে সই বসল।');

        // ⓘ মোট বদলে পাঠালেও (হাতে বানানো অনুরোধ) কিছুই নয়
        [$ids2] = $this->form($no);
        $this->post(route('purchase.payment_schedule.sign_all', $no), ['ids' => $ids2, 'total' => '1'])
            ->assertRedirect()->assertSessionHasErrors('ids');
        $this->assertSame(Approval::PENDING, Approval::query()->findOrFail($waiting[1])->status, '⛔ ভুল মোটেও সই বসল।');
    }

    public function test_the_inbox_still_keeps_money_papers_out_of_its_bulk_signature(): void
    {
        [, $waiting] = $this->twoSupplierProposal();

        $review = app(BulkApproval::class)->review($waiting, $this->owner);
        $this->assertSame([], $review['sign'], '⛔ ইনবক্সের "একসাথে সই" এখন টাকার কাগজও নেয় — মালিকের ২৪ সেপ্টেম্বরের নিয়ম ভাঙল।');
        $this->assertSame(['money', 'money'], array_column($review['skip'], 'why'));
    }

    public function test_a_person_who_may_not_sign_gets_no_button_and_cannot_post_it(): void
    {
        [$no, $waiting] = $this->twoSupplierProposal();
        [$ids, $total] = $this->form($no);

        // ⓘ হিসাবরক্ষক এই ছকে সইদাতা নন — পাতা দেখলেও বোতাম তাঁর নয়, আর হাতে পাঠালে কিছুই বসে না
        $viewer = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        $page = $this->actingAs($viewer)->get(route('purchase.payment_schedule.proposal', $no));

        if ($page->status() === 200) {
            $this->assertStringNotContainsString('data-proposal-sign-all', (string) $page->getContent(),
                '⛔ যিনি সইদাতা নন, তিনিও "এক সইয়ে সবগুলো" বোতাম পেলেন।');
        } else {
            $this->assertSame(403, $page->status(), '⛔ পাতাটা অচেনা কারণে খুলল না: '.$page->status());
        }

        $this->actingAs($viewer)->post(route('purchase.payment_schedule.sign_all', $no), ['ids' => $ids, 'total' => $total]);
        foreach ($waiting as $id) {
            $this->assertSame(Approval::PENDING, Approval::query()->findOrFail($id)->status, '⛔ সইদাতা নন, অথচ এক সইয়ে সব বসল।');
        }

        // ⓘ প্রস্তাবক নিজেও নন (ভা-১৪)
        $this->actingAs($this->proposer)->post(route('purchase.payment_schedule.sign_all', $no), ['ids' => $ids, 'total' => $total]);
        foreach ($waiting as $id) {
            $this->assertSame(Approval::PENDING, Approval::query()->findOrFail($id)->status, '⛔ প্রস্তাবক নিজের প্রস্তাবে এক সইয়ে সব বসালেন।');
        }
    }

    /** @return array{0: string, 1: list<int>} প্রস্তাবের নম্বর আর অপেক্ষার অনুমোদনগুলো */
    private function twoSupplierProposal(): array
    {
        [$first, $second] = Supplier::query()->orderBy('id')->limit(2)->pluck('id')->all();
        $picks = [$this->bill((int) $first)->id => '100', $this->bill((int) $second)->id => '60'];

        $this->actingAs($this->proposer);
        $made = app(PaymentProposalService::class)->propose($picks, $this->till());
        $this->actingAs($this->owner);

        $this->assertCount(2, $made, 'প্রস্তুতিটাই ভুল — দুই সরবরাহকারীর দুই কাগজ হয়নি।');
        $no = (string) $made[0]->proposal_no;
        $this->assertSame($no, (string) $made[1]->proposal_no, 'প্রস্তুতিটাই ভুল — দুই কাগজ এক প্রস্তাবে নয়।');

        $waiting = Approval::query()->where('approvable_type', Payment::class)->whereIn('approvable_id', collect($made)->pluck('id'))
            ->where('status', Approval::PENDING)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertCount(2, $waiting, 'প্রস্তুতিটাই ভুল — দুই কাগজ সইয়ের অপেক্ষায় নেই।');

        return [$no, $waiting];
    }

    /** @return array{0: list<int>, 1: string, 2: string} পাতার ফর্ম যা পাঠাবে — কাগজ, মোট, বোতামের লেখা */
    private function form(string $no): array
    {
        $page = $this->actingAs($this->owner)->get(route('purchase.payment_schedule.proposal', $no))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$page->getContent());
        $xpath = new \DOMXPath($dom);
        $form = $xpath->query('//form[@data-proposal-sign-all]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $form, '⛔ প্রস্তাবের পাতায় "এক সইয়ে সবগুলো" বোতাম নেই।');

        $ids = [];
        foreach ($xpath->query('.//input[@name="ids[]"]', $form) as $input) {
            $ids[] = (int) $input->getAttribute('value');
        }
        sort($ids);

        return [$ids, (string) $xpath->query('.//input[@name="total"]', $form)->item(0)?->getAttribute('value'), (string) $form->textContent];
    }

    private function bill(int $supplierId): PurchaseBill
    {
        $this->actingAs($this->owner);

        return app(DirectPurchaseService::class)->complete([
            'supplier_id' => $supplierId,
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => now()->toDateString(), 'supplier_bill_no' => 'ONE-'.fake()->unique()->numberBetween(10000, 99999),
        ], [['product_id' => Product::query()->where('track_batch', false)->where('track_serial', false)->orderBy('id')->value('id'),
            'qty' => '2', 'rate' => '50', 'sales_price' => '50', 'tax' => '0']])['bill']->fresh();
    }

    private function till(): int
    {
        return (int) app(CashTillService::class)->ensurePrimaryTill()->account_id;
    }
}

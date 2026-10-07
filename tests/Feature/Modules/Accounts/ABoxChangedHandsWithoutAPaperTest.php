<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\CashCount;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\TillHandover;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\TillHandoverService;
use App\Modules\Approval\Services\ApprovalFlowService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ম৮ (দায়িত্ব) — ক্যাশবাক্সের দায়িত্ব বদল একটা কাগজ, জের গুনে, সই নিয়ে (Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬; ৬৩-এর সিদ্ধান্ত)।
 *
 * ⛔ আগে বাক্সের সম্পাদনায় হেফাজতকারী চুপচাপ বদলাত — কার হাত থেকে কার হাতে কত গেল তার কাগজ থাকত না।
 */
final class ABoxChangedHandsWithoutAPaperTest extends TestCase
{
    use PutsMoneyInTheTill;
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $rahim;

    private CashTill $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->rahim = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->rahim->companies()->attach($this->company->id, ['is_active' => true]);

        $this->till = app(CashTillService::class)->create(['name_en' => 'M8 box', 'holder_id' => $this->owner->id]);
        $this->putMoneyIn($this->till->account, '1000');
    }

    /** ⓘ ছক বন্ধে এখনই — আর বাক্স আগের জনের তালিকা থেকে নতুন জনের তালিকায় */
    public function test_without_a_flow_the_box_moves_at_once_and_changes_lists(): void
    {
        $this->assertTrue(CashTill::query()->heldBy((int) $this->owner->id)->whereKey($this->till->id)->exists(), 'প্রস্তুতি');

        $handover = app(TillHandoverService::class)->handOver($this->till, (int) $this->rahim->id);

        $this->assertSame(DocumentStatus::CONFIRMED, $handover->status);
        $this->assertSame(0, bccomp((string) $handover->book_balance, '1000', 4), 'হস্তান্তরের মুহূর্তের জের লেখা নেই।');
        $this->assertFalse(CashTill::query()->heldBy((int) $this->owner->id)->whereKey($this->till->id)->exists(),
            '⛔ হস্তান্তরের পরেও বাক্স আগের জনের তালিকায়।');
        $this->assertTrue(CashTill::query()->heldBy((int) $this->rahim->id)->whereKey($this->till->id)->exists(),
            '⛔ বাক্স নতুন জনের তালিকায় আসেনি।');
        $this->assertSame(0, LedgerEntry::query()->where('document_no', $handover->document_no)->count(), 'হস্তান্তর খাতায় কিছু লেখে না।');
    }

    public function test_with_a_flow_the_box_waits_for_the_last_signature(): void
    {
        $signer = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        app(ApprovalFlowService::class)->create(
            ['module' => 'accounts', 'action' => 'till_handover', 'is_active' => true],
            [['level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $signer->id, 'requires_all' => false]],
        );

        $handover = app(TillHandoverService::class)->handOver($this->till, (int) $this->rahim->id);

        $this->assertTrue($handover->isAwaiting(), '⛔ ছক চালু, তবু হস্তান্তর সইয়ের অপেক্ষা করল না।');
        $this->assertSame((int) $this->owner->id, (int) $this->till->fresh()->holder_id, '⛔ সইয়ের আগেই বাক্স হাতবদল।');

        app(ApprovalEngine::class)->approve(
            Approval::query()->where('approvable_type', $handover->getMorphClass())->where('approvable_id', $handover->id)->sole(), $signer);

        $this->assertSame((int) $this->rahim->id, (int) $this->till->fresh()->holder_id, '⛔ শেষ সইয়ের পরে বাক্স নতুন জনের হয়নি।');
        $this->assertTrue($handover->fresh()->isConfirmed());
    }

    /** ⓘ গুনে কম — পার্থক্য নগদ গণনার কাগজে, হস্তান্তর নিজে কোনো হিসাব লেখে না */
    public function test_a_short_count_goes_to_a_cash_count_paper(): void
    {
        $handover = app(TillHandoverService::class)->handOver($this->till, (int) $this->rahim->id, '900');

        $this->assertSame(0, bccomp((string) $handover->difference, '-100', 4));
        $count = CashCount::query()->findOrFail($handover->cash_count_id);
        $this->assertSame(0, bccomp((string) $count->counted_amount, '900', 4), 'গণনার কাগজে গোনা অঙ্ক নেই।');
        $this->assertSame(0, bccomp((string) $count->difference, '-100', 4));
        $this->assertSame(0, bccomp($this->till->fresh()->balance(), '1000', 4), 'হস্তান্তর নিজে খাতা বদলেছে।');
    }

    public function test_a_holder_with_money_does_not_change_through_the_edit_form(): void
    {
        $this->assertRefused(fn () => app(CashTillService::class)->update($this->till, ['holder_id' => $this->rahim->id]), 'holder_id',
            '⛔ টাকাসহ বাক্সের দায়িত্ব সাধারণ সম্পাদনায় বদলাল — কোনো কাগজ ছাড়া।');
        $this->assertSame((int) $this->owner->id, (int) $this->till->fresh()->holder_id);

        // ⓘ খালি বাক্সে আজকের মতোই
        $empty = app(CashTillService::class)->create(['name_en' => 'M8 empty', 'holder_id' => $this->owner->id]);
        app(CashTillService::class)->update($empty, ['holder_id' => $this->rahim->id]);
        $this->assertSame((int) $this->rahim->id, (int) $empty->fresh()->holder_id);
    }

    public function test_the_same_holder_an_outsider_or_a_second_open_handover_is_refused(): void
    {
        $this->assertRefused(fn () => app(TillHandoverService::class)->handOver($this->till, (int) $this->owner->id), 'to_holder_id', '⛔ নিজের কাছেই হস্তান্তর হলো।');

        $outsider = User::factory()->create();
        $this->assertRefused(fn () => app(TillHandoverService::class)->handOver($this->till, (int) $outsider->id), 'to_holder_id', '⛔ কোম্পানির বাইরের মানুষের হাতে বাক্স গেল।');

        $signer = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        app(ApprovalFlowService::class)->create(
            ['module' => 'accounts', 'action' => 'till_handover', 'is_active' => true],
            [['level' => 1, 'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $signer->id, 'requires_all' => false]],
        );
        $first = app(TillHandoverService::class)->handOver($this->till, (int) $this->rahim->id);

        $this->assertRefused(fn () => app(TillHandoverService::class)->handOver($this->till, (int) $signer->id), 'to_holder_id', '⛔ একই বাক্সের দুই হস্তান্তর একসাথে খোলা।');

        app(TillHandoverService::class)->cancel($first);
        $this->assertSame(DocumentStatus::CANCELLED, $first->fresh()->status);
        $this->assertSame((int) $this->owner->id, (int) $this->till->fresh()->holder_id, 'বাতিলের পরে বাক্স আগের জনেরই।');
    }

    public function test_the_box_page_hands_over(): void
    {
        $this->get(route('accounts.till.show', $this->till))->assertOk()->assertSee('data-handover', false)
            ->assertSee(route('accounts.till.handover', $this->till));

        $this->post(route('accounts.till.handover', $this->till), ['to_holder_id' => $this->rahim->id])
            ->assertRedirect(route('accounts.till.show', $this->till))->assertSessionHasNoErrors();

        $this->assertSame((int) $this->rahim->id, (int) $this->till->fresh()->holder_id);
        $this->assertSame(1, TillHandover::query()->where('cash_till_id', $this->till->id)->count());
    }

    private function assertRefused(\Closure $act, string $field, string $why): void
    {
        try {
            $act();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), 'আটকেছে, কিন্তু অন্য কারণে: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->fail($why);
    }
}

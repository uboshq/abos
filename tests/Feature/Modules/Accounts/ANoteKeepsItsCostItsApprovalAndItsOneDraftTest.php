<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Contracts\Drillable;
use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Engines\Drill\DrillResolver;
use App\Core\Security\FieldSecurity;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Models\NoteLine;
use App\Modules\Accounts\Models\TradeVoucher;
use App\Modules\Accounts\Models\TradeVoucherLine;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\TradeVoucherService;
use App\Modules\Accounts\Support\LineMath;
use App\Modules\Accounts\Support\PaperTaxFacts;
use App\Modules\Customer\Models\Customer;
use App\Modules\Purchase\Models\PurchaseReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\WritesNotesAgainstBills;
use Tests\TestCase;

/**
 * ⭐ নোট আর স্টক ছাড়া ভাউচারের সারাই — ২৪ নভেম্বর ২০২৬।
 *
 * দাবি:
 *  - কেনা দরের চাবি ছাড়া: ক্রয় বিলের JSON, নোটের পাতা, ভাউচারের পাতা আর ফর্মের উৎস — কোথাও দর নেই; চাবি থাকলে আছে;
 *  - নোটের ছক না থাকলে মাল ফেরত ক্রয় ফেরতের নিজের ছক এড়ায় না;
 *  - পাঠাতে গিয়ে থামলে একটাই খসড়া, আর তার পাতায় ফেরা;
 *  - ঋণাত্মক পরিমাণ নেয় না, "1e5" লেখায় ভাঙে না;
 *  - সারির public_id আর অডিট আছে; খাতার দুই নামই ভাউচারে ফেরে;
 *  - শেষ দিনের তালিকায় অন্য কোম্পানির পক্ষ আসে না।
 */
final class ANoteKeepsItsCostItsApprovalAndItsOneDraftTest extends TestCase
{
    use RefreshDatabase;
    use WritesNotesAgainstBills;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTheNoteScene();
    }

    public function test_no_purchase_price_reaches_a_person_without_the_cost_key(): void
    {
        $bill = $this->purchaseBill('10', '137.31');
        $note = $this->draftNote(Note::TYPE_DN, (int) $this->supplier->id, [
            ['type' => NoteLine::PRODUCT, 'source_line_id' => $bill->lines->first()->id, 'qty' => '2', 'rate' => '137.31'],
            ['type' => NoteLine::ACCOUNT, 'account_id' => $this->accountId('4300'), 'amount' => '3'],
        ], ['against_no' => $bill->document_no]);

        $this->switchOn();
        $voucher = app(TradeVoucherService::class)->create(TradeVoucher::PURCHASE, [
            'party_id' => $this->supplier->id, 'narration' => 'x', 'trx_date' => now()->toDateString(),
            'lines' => [['type' => NoteLine::PRODUCT, 'product_id' => $this->product->id, 'qty' => '3', 'rate' => '246.17'],
                ['type' => NoteLine::ACCOUNT, 'account_id' => (int) app(TradeVoucherService::class)->accountChoices(TradeVoucher::PURCHASE)[0]['id'], 'amount' => '4']],
        ]);

        $this->product->forceFill(['purchase_price' => '913.77'])->save();

        $pages = [
            [route('accounts.note.bill', ['type' => 'dn', 'kind' => 'supplier', 'party_id' => $this->supplier->id, 'no' => $bill->document_no]), '137.31'],
            [route('accounts.note.show', $note), '137.31'],
            [route('accounts.trade_voucher.show', ['kind' => 'purchase', 'voucher' => $voucher->id]), '246.17'],
            [route('accounts.note.dn.create'), '913.77'],
            [route('accounts.trade_voucher.create', ['kind' => 'purchase']), '913.77'],
        ];

        foreach ($pages as [$url, $price]) {
            $this->assertStringContainsString($price, (string) $this->get($url)->assertOk()->getContent(), "প্রস্তুতিটাই ভুল — চাবিধারী দর দেখেন না: {$url}");
        }

        $this->actingAs($this->clerk());

        foreach ($pages as [$url, $price]) {
            $this->assertStringNotContainsString($price, (string) $this->get($url)->assertOk()->getContent(), "⛔ চাবি ছাড়া কেনা দর গেল: {$url}");
        }

        $hidden = PaperTaxFacts::line($note->lines()->orderBy('line_no')->firstOrFail(), false);
        foreach (['rate', 'amount', 'vat', 'total'] as $field) {
            $this->assertSame(FieldSecurity::mask(), $hidden[$field], "⛔ ছাপায় {$field} খোলা।");
        }
        $this->assertSame('2', rtrim(rtrim($hidden['qty'], '0'), '.'), 'পরিমাণ ঢাকা পড়ার কথা নয়।');
    }

    public function test_goods_do_not_leave_past_the_purchase_returns_own_approval(): void
    {
        $bill = $this->purchaseBill('10', '100');
        $note = $this->draftNote(Note::TYPE_DN, (int) $this->supplier->id, [
            ['type' => NoteLine::PRODUCT, 'source_line_id' => $bill->lines->first()->id, 'qty' => '2', 'goods_moved' => true],
        ], ['against_no' => $bill->document_no]);

        ApprovalFlow::query()->where('module', 'accounts')->where('action', 'note')->delete();
        ApprovalFlow::query()->where('module', 'purchase')->where('action', 'return')->delete();
        $flow = ApprovalFlow::query()->create(['company_id' => $this->company->id, 'module' => 'purchase', 'action' => 'return', 'is_active' => true]);
        ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->owner->id]);
        ApprovalEngine::flowsChanged();

        $this->actingAs($this->clerk());

        try {
            app(NoteService::class)->confirm($note->fresh());
            $this->fail('⛔ নোটের ছক নেই, তবু ক্রয় ফেরতের ছক এড়িয়ে মাল বেরোল।');
        } catch (ValidationException $e) {
            $this->assertSame(__('accounts::note.return_needs_its_approval'), $e->errors()['status'][0] ?? null);
        }

        $this->assertTrue($note->fresh()->isDraft());
        $this->assertSame(0, PurchaseReturn::query()->withoutGlobalScopes()->where('purchase_bill_id', $bill->id)->count());
    }

    public function test_a_paper_that_stops_on_sending_leaves_one_draft_and_opens_it(): void
    {
        $this->switchOn();
        $this->customer->forceFill(['credit_limit' => '1'])->save();
        $before = TradeVoucher::query()->count();

        $answer = $this->post(route('accounts.trade_voucher.store', ['kind' => TradeVoucher::SALE]), [
            'party_id' => $this->customer->id, 'narration' => 'x', 'submit' => 'send',
            'lines' => [['type' => NoteLine::ACCOUNT, 'account_id' => $this->accountId('4300'), 'amount' => '5000']],
        ]);

        $made = TradeVoucher::query()->latest('id')->firstOrFail();

        if (TradeVoucher::query()->count() === $before) {
            $this->fail('প্রস্তুতিটাই ভুল — দেয়াল লেখার আগেই থামিয়েছে, খসড়া হয়নি।');
        }

        $answer->assertRedirect(route('accounts.trade_voucher.show', ['kind' => TradeVoucher::SALE, 'voucher' => $made->id]))->assertSessionHasErrors();
        $this->assertTrue($made->isDraft());
        $this->assertSame($before + 1, TradeVoucher::query()->count());
    }

    public function test_a_negative_quantity_is_refused_and_a_scientific_number_does_not_break(): void
    {
        $this->assertSame('0.0000', LineMath::money('1e5'));
        $this->assertSame('1234.5000', LineMath::money('+1,234.5'));

        $this->switchOn();
        $this->expectException(ValidationException::class);
        app(TradeVoucherService::class)->create(TradeVoucher::PURCHASE, [
            'party_id' => $this->supplier->id, 'narration' => 'x', 'trx_date' => now()->toDateString(),
            'lines' => [['type' => NoteLine::SERVICE, 'item_name' => 's', 'account_id' => (int) app(TradeVoucherService::class)->accountChoices(TradeVoucher::PURCHASE)[0]['id'], 'qty' => '-2', 'rate' => '-50']],
        ]);
    }

    public function test_every_line_has_a_public_key_and_an_audit_row_and_both_ledger_names_open_the_voucher(): void
    {
        $note = $this->draftNote(Note::TYPE_CN, (int) $this->customer->id, [
            ['type' => NoteLine::ACCOUNT, 'account_id' => $this->accountId('4300'), 'amount' => '10'],
        ]);
        $this->switchOn();
        $voucher = app(TradeVoucherService::class)->create(TradeVoucher::SALE, [
            'party_id' => $this->customer->id, 'narration' => 'x', 'trx_date' => now()->toDateString(),
            'lines' => [['type' => NoteLine::ACCOUNT, 'account_id' => $this->accountId('4300'), 'amount' => '10']],
        ]);

        foreach ([$note->lines()->firstOrFail(), TradeVoucherLine::query()->where('trade_voucher_id', $voucher->id)->firstOrFail()] as $line) {
            $this->assertNotNull($line->public_id, $line::class);
            $this->assertTrue(AuditTrail::query()->forRecord($line::class, (int) $line->id)->exists(), '⛔ সারি লেখার অডিট নেই: '.$line::class);
        }

        foreach (['sales_voucher', 'purchase_voucher', 'trade_voucher'] as $name) {
            $class = app(DrillResolver::class)->map()[$name];
            $this->assertTrue(is_subclass_of($class, Drillable::class));
            $this->assertSame($name, $class::drillSourceType());
        }

        $class = app(DrillResolver::class)->map()['sales_voucher'];
        $found = $class::query()->findOrFail($voucher->id);
        $this->assertSame($voucher->document_no, $found->drillDocumentNo());
        $this->assertSame(1, $found->lines()->count(), '⛔ ড্রিলের ক্লাসে সারির সম্পর্ক ভাঙা।');
    }

    public function test_the_due_days_list_never_holds_another_companys_party(): void
    {
        $other = Company::query()->whereKeyNot($this->company->id)->firstOrFail();
        $foreign = CompanyContext::forCompany($other->id, fn () => Customer::query()->create([
            'company_id' => $other->id, 'code' => 'FOREIGN-1', 'name_en' => 'Foreign', 'credit_limit' => 0, 'credit_days' => 77, 'is_active' => true]));

        $days = (new \ReflectionMethod(\App\Modules\Accounts\Http\Controllers\TradeVoucherController::class, 'creditDays'))
            ->invoke(app(\App\Modules\Accounts\Http\Controllers\TradeVoucherController::class), 'customer', [(int) $foreign->id, (int) $this->customer->id]);

        $this->assertSame([(int) $this->customer->id], array_keys($days), '⛔ অন্য কোম্পানির পক্ষের শর্ত এল।');
    }

    private function switchOn(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('accounts.stock_free_vouchers', true);
        $settings->flush();
    }

    /** হিসাবের লোক — নোট আর ভাউচার লেখেন, কেনা দর দেখেন না */
    private function clerk(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);

        foreach (['accounts.note.view', 'accounts.note.manage', 'accounts.report', 'accounts.voucher.create'] as $key) {
            Permission::findOrCreate($key, 'web');
            $user->givePermissionTo($key);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::query()->findOrFail($user->id);
        $this->assertFalse($user->can('inventory.cost.view'), 'প্রস্তুতিটাই ভুল — চাবিটা আছে।');
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        return $user;
    }
}

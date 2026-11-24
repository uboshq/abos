<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Security\FieldSecurity;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Http\Controllers\NotePrintController;
use App\Modules\Accounts\Http\Controllers\TradeVoucherPrintController;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Models\NoteLine;
use App\Modules\Accounts\Models\TradeVoucher;
use App\Modules\Accounts\Services\DayChecklist;
use App\Modules\Accounts\Services\TradeVoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\WritesNotesAgainstBills;
use Tests\TestCase;

/**
 * ⭐ নোট মেরামতের বাকি দাবি (২৪ নভেম্বর ২০২৬)।
 *
 * দাবি: চাবি ছাড়া সরবরাহকারীর নোট আর ক্রয় ভাউচারের ছাপায় পণ্যের দর, অঙ্ক আর উপ-যোগ ঢাকা, কিন্তু মোট খোলা; চাবি থাকলে আর বিক্রয়ের কাগজে
 * সব খোলা; দিন শেষের BIN-সতর্কতা কেবল পোস্ট হওয়া কাগজ গোনে; জায়গার মাপ অন্য কোম্পানির সারি গোনে না।
 */
final class ANotePrintHidesCostAndTheDayCountsPostedPapersTest extends TestCase
{
    use RefreshDatabase;
    use WritesNotesAgainstBills;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTheNoteScene();
        $settings = app(SettingsService::class);
        $settings->set('accounts.stock_free_vouchers', true);
        $settings->flush();
    }

    public function test_the_supplier_notes_print_hides_the_cost_without_the_key(): void
    {
        $bill = $this->purchaseBill('10', '137.31');
        $note = $this->draftNote(Note::TYPE_DN, (int) $this->supplier->id, [
            ['type' => NoteLine::PRODUCT, 'source_line_id' => $bill->lines->first()->id, 'qty' => '2', 'rate' => '137.31'],
        ], ['against_no' => $bill->document_no]);
        $total = \App\Core\Support\Money::format($note->grandTotal());

        $open = $this->paper(NotePrintController::class, $note);
        $this->assertStringContainsString('137.31', json_encode($open['price_lines']));
        $this->assertNotSame(FieldSecurity::mask(), $open['price_amount']);

        $this->actingAs($this->clerk());
        $shut = $this->paper(NotePrintController::class, $note);
        $this->assertStringNotContainsString('137.31', json_encode($shut['price_lines']), '⛔ ছাপায় কেনা দর।');
        $this->assertSame([FieldSecurity::mask(), FieldSecurity::mask()], [$shut['price_amount'], $shut['price_vat']], '⛔ উপ-যোগে দর বেরোয়।');
        $this->assertSame($total, $shut['grand_total'], 'মোট দেনার অঙ্ক — ঢাকার কথা নয়।');
    }

    public function test_a_credit_note_and_both_vouchers_print_by_the_same_rule(): void
    {
        $invoice = $this->salesInvoice('4', '222.22');
        $cn = $this->draftNote(Note::TYPE_CN, (int) $this->customer->id, [
            ['type' => NoteLine::PRODUCT, 'source_line_id' => $invoice->lines->first()->id, 'qty' => '1', 'rate' => '222.22'],
        ], ['against_no' => $invoice->document_no]);
        $make = fn (string $kind, int $party) => app(TradeVoucherService::class)->create($kind, ['party_id' => $party, 'narration' => 'x', 'trx_date' => now()->toDateString(),
            'lines' => [['type' => NoteLine::PRODUCT, 'product_id' => $this->product->id, 'qty' => '3', 'rate' => '246.17']]]);
        $sv = $make(TradeVoucher::SALE, (int) $this->customer->id);
        $puv = $make(TradeVoucher::PURCHASE, (int) $this->supplier->id);

        $this->assertStringContainsString('246.17', json_encode($this->paper(TradeVoucherPrintController::class, $puv)['lines']), 'প্রস্তুতিটাই ভুল।');
        $this->actingAs($this->clerk());
        $this->assertStringContainsString('222.22', json_encode($this->paper(NotePrintController::class, $cn)['price_lines']), 'বিক্রয়ের দর ঢাকা পড়ল।');
        $this->assertStringContainsString('246.17', json_encode($this->paper(TradeVoucherPrintController::class, $sv)['lines']), 'বিক্রয়ের দর ঢাকা পড়ল।');
        $this->assertStringNotContainsString('246.17', json_encode($this->paper(TradeVoucherPrintController::class, $puv)['lines']), '⛔ ক্রয় ভাউচারের ছাপায় কেনা দর।');
    }

    public function test_the_day_list_counts_only_posted_vat_papers(): void
    {
        Company::query()->whereKey(CompanyContext::id())->update(['bin' => null]);
        $today = Carbon::today();
        $count = fn (): int => (int) (collect(app(DayChecklist::class)->for($today))->firstWhere('key', 'bin_missing')['count'] ?? 0);
        $before = $count();

        $sv = app(TradeVoucherService::class)->create(TradeVoucher::SALE, ['party_id' => $this->customer->id, 'narration' => 'x', 'trx_date' => $today->toDateString(),
            'lines' => [['type' => NoteLine::ACCOUNT, 'account_id' => $this->accountId('4300'), 'amount' => '500']]]);
        $this->assertSame($before, $count(), '⛔ খসড়া গোনা হলো।');

        app(TradeVoucherService::class)->confirm($sv);
        $this->assertTrue($sv->fresh()->isConfirmed(), 'প্রস্তুতিটাই ভুল।');
        $this->assertSame($before + 1, $count());

        $sv->fresh()->forceFill(['status' => \App\Core\Support\DocumentStatus::CANCELLED])->save();
        $this->assertSame($before, $count(), '⛔ বাতিল গোনা হলো।');
    }

    public function test_the_room_on_a_bill_line_never_counts_another_companys_note(): void
    {
        $bill = $this->purchaseBill('10', '100');
        $line = $bill->lines->first();
        $note = $this->draftNote(Note::TYPE_DN, (int) $this->supplier->id, [
            ['type' => NoteLine::PRODUCT, 'source_line_id' => $line->id, 'qty' => '10', 'goods_moved' => true],
        ], ['against_no' => $bill->document_no]);

        // ⓘ একই বিল আর সারির id, অন্য কোম্পানির নোট — তার অপেক্ষার মাল এই বিলের জায়গা খায় না
        $other = Company::query()->whereKeyNot(CompanyContext::id())->firstOrFail();
        \Illuminate\Support\Facades\DB::table('acc_notes')->where('id', $note->id)->update(['company_id' => $other->id]);
        \Illuminate\Support\Facades\DB::table('acc_note_lines')->where('note_id', $note->id)->update(['company_id' => $other->id]);

        $again = $this->draftNote(Note::TYPE_DN, (int) $this->supplier->id, [
            ['type' => NoteLine::PRODUCT, 'source_line_id' => $line->id, 'qty' => '10', 'goods_moved' => true],
        ], ['against_no' => $bill->document_no]);
        $this->assertTrue($again->isDraft());
    }

    /** @return array<string, mixed> */
    private function paper(string $controller, object $paper): array
    {
        return (new \ReflectionMethod($controller, 'paper'))->invoke(app($controller), $paper->fresh());
    }

    private function clerk(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($user->can('inventory.cost.view'), 'প্রস্তুতিটাই ভুল।');

        return $user;
    }
}

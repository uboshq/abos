<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\AmountInWords;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Models\ChequeBook;
use App\Modules\Accounts\Models\ChequeLayout;
use App\Modules\Accounts\Models\ChequePrint;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\ChequeService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ চেক ছাপা — মালিকের অনুমোদিত নমুনা (W63BXTDuqM65tDaKRDnvwR, ১০ নভেম্বর ২০২৬: "Ok final cheek daw tarpor koro")।
 * [[ChequePrinter]] · [[ChequePrintController]]
 *
 * দাবি: দেওয়া চেক বইয়ের নম্বরে ছাপে আর খাতার চেকে আসল নম্বর বসে; একই চেক আবার ছাপতে কারণ লাগে, সারি একটাই; নষ্ট নম্বর আর কখনো
 * নয়; অন্য ব্যাংকের বা হিসাব ছাড়া বই চলে না; পাকা পরিশোধ ভাউচারে টাকা = ব্যাংক থেকে যা বেরোল; হিসাব ছাড়া চেক খাতায় একটা দাখিলাও
 * বসায় না আর সেই চাবি ছাড়া কেউ দেখে না; পাতাগুলো খোলে; "Taka … Only"।
 */
final class AChequeIsPrintedFromItsBookTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Account $bank;

    private ChequeLayout $layout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->bank = $this->bankAccount('1102-CHQP', 'Cheque print bank');
        $this->layout = ChequeLayout::query()->create(ChequeLayout::standard() + ['company_id' => $this->company->id, 'name' => 'সাধারণ পাতা']);
    }

    public function test_an_issued_cheque_prints_on_the_books_next_number_and_the_register_takes_that_number(): void
    {
        $book = $this->book($this->bank, 1234551, 1234600);
        $cheque = $this->issued('48440.49', 'হাতে-লেখা');

        $response = $this->post(route('accounts.cheque.print.cheque.store', $cheque), ['book' => $book->id, 'payee' => 'MESSRS RAHMAN TRADERS', 'crossed' => '1']);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'), '⛔ ছাপায় PDF এল না।');
        $print = ChequePrint::query()->where('cheque_id', $cheque->id)->sole();
        $this->assertSame(1234551, $print->cheque_no, '⛔ বইয়ের পরের নম্বর নয়।');
        $this->assertSame('48440.4900', bcadd((string) $print->amount, '0', 4), '⛔ চেকের টাকা খাতার চেকের টাকা নয়।');
        $this->assertTrue($print->crossed);
        $this->assertSame(1234552, $book->fresh()->next_no, '⛔ বইয়ের পরের নম্বর এগোয়নি।');
        $this->assertSame('1234551', $cheque->fresh()->cheque_no, '⛔ খাতার চেকে ছাপা নম্বরটা বসেনি — ব্যাংকের কাগজের সাথে মিলবে না।');
    }

    public function test_the_same_cheque_again_needs_a_reason_and_stays_one_row(): void
    {
        $book = $this->book($this->bank, 500, 549);
        $cheque = $this->issued('12000', '');
        $this->post(route('accounts.cheque.print.cheque.store', $cheque), ['book' => $book->id, 'payee' => 'KARIM', 'crossed' => '1'])->assertOk();

        $this->post(route('accounts.cheque.print.cheque.store', $cheque), ['book' => $book->id, 'payee' => 'KARIM', 'crossed' => '1'])
            ->assertSessionHasErrors('reason');
        $this->assertSame(1, ChequePrint::query()->where('cheque_id', $cheque->id)->sole()->times, '⛔ কারণ ছাড়াই আবার ছাপা গোনা হলো।');

        $this->post(route('accounts.cheque.print.cheque.store', $cheque), ['book' => $book->id, 'payee' => 'KARIM', 'crossed' => '1', 'reason' => 'প্রিন্টারে কাগজ আটকেছিল'])->assertOk();

        $print = ChequePrint::query()->where('cheque_id', $cheque->id)->sole();
        $this->assertSame(2, $print->times, '⛔ আবার ছাপা গোনা হয়নি।');
        $this->assertSame(500, $print->cheque_no, '⛔ আবার ছাপায় নতুন নম্বর নেওয়া হলো।');
        $this->assertSame('প্রিন্টারে কাগজ আটকেছিল', $print->reason);
        $this->assertSame(501, $book->fresh()->next_no);
    }

    public function test_a_cheque_written_with_a_number_of_the_book_keeps_it_and_a_spoiled_number_never_returns(): void
    {
        $book = $this->book($this->bank, 7001, 7050);
        $cheque = $this->issued('9000', '7010');

        $this->post(route('accounts.cheque.print.cheque.store', $cheque), ['book' => $book->id, 'payee' => 'NUR', 'crossed' => '0'])->assertOk();
        $first = ChequePrint::query()->where('cheque_id', $cheque->id)->sole();
        $this->assertSame(7010, $first->cheque_no, '⛔ চেকে লেখা বইয়ের নম্বর ফেলে অন্য নম্বর নিল।');
        $this->assertFalse($first->crossed);

        $this->post(route('accounts.cheque.print.spoil', $first), ['reason' => 'তারিখ সরে গেছে'])->assertRedirect();
        $this->assertSame(ChequePrint::SPOILED, $first->fresh()->status);

        // ⓘ নষ্টের পরে আবার ছাপা — নতুন নম্বরে, নষ্টটা খাতায় থেকে যায়
        $this->post(route('accounts.cheque.print.cheque.store', $cheque), ['book' => $book->id, 'payee' => 'NUR', 'crossed' => '0'])->assertOk();
        $numbers = collect(ChequePrint::query()->where('cheque_id', $cheque->id)->orderBy('id')->pluck('cheque_no'))->map(fn ($n) => (int) $n)->values()->all();
        $this->assertSame([7010, 7011], $numbers, '⛔ নষ্ট নম্বর আবার এল, বা নষ্ট সারিটা মুছে গেল।');
        $this->assertSame('0007011', $cheque->fresh()->cheque_no, '⛔ খাতার চেকে বইয়ের ছাপা নম্বর (সাত অঙ্ক) বসেনি।');
    }

    public function test_a_book_of_another_bank_or_off_the_books_is_refused_and_a_cleared_cheque_does_not_print(): void
    {
        $other = $this->book($this->bankAccount('1102-CHQQ', 'Other bank'), 1, 50);
        $own = $this->book(null, 1, 50);
        $cheque = $this->issued('5000', '');

        foreach ([$other, $own] as $book) {
            $this->post(route('accounts.cheque.print.cheque.store', $cheque), ['book' => $book->id, 'payee' => 'X', 'crossed' => '1'])
                ->assertSessionHasErrors('book');
        }

        $cheque->forceFill(['status' => Cheque::CLEARED])->save();
        $this->post(route('accounts.cheque.print.cheque.store', $cheque), ['book' => $this->book($this->bank, 1, 50)->id, 'payee' => 'X', 'crossed' => '1'])
            ->assertSessionHasErrors('cheque');
        $this->assertSame(0, ChequePrint::query()->count(), '⛔ ভুল বই বা ভাঙানো চেকেও খাতায় সারি উঠল।');
    }

    public function test_a_posted_cheque_payment_voucher_prints_what_left_the_bank(): void
    {
        $book = $this->book($this->bank, 100, 149);
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::PAYMENT, 'trx_date' => now()->toDateString(), 'narration' => 'চেকে ভাড়া', 'money_account_id' => $this->bank->id,
                // ⓘ ব্যাংকের লেনদেনে নম্বর লাগে ([[VoucherService]]) — ভাউচারে লেখা চেক নম্বর বইয়ের ভিতরের, তাই ছাপাও সেই নম্বরে
                'instrument' => 'cheque', 'instrument_no' => '0000105', 'instrument_date' => now()->addDays(3)->toDateString()],
            [
                ['account_id' => StandardChart::find(StandardChart::RENT)->id, 'debit' => '25000', 'credit' => '0'],
                ['account_id' => $this->bank->id, 'debit' => '0', 'credit' => '25000'],
            ],
        );
        app(VoucherService::class)->post($voucher);

        $this->get(route('accounts.cheque.print.voucher', $voucher))->assertOk()->assertSee('data-cheque-print-form', false);
        $this->post(route('accounts.cheque.print.voucher.store', $voucher), ['book' => $book->id, 'payee' => 'LANDLORD', 'crossed' => '1'])->assertOk();

        $print = ChequePrint::query()->where('voucher_id', $voucher->id)->sole();
        $this->assertSame('25000.0000', bcadd((string) $print->amount, '0', 4), '⛔ চেকের টাকা ব্যাংক থেকে যা বেরোল তা নয়।');
        $this->assertSame(now()->addDays(3)->toDateString(), $print->cheque_date?->toDateString(), '⛔ চেকের তারিখ ভাউচারের চেকের তারিখ নয়।');
        $this->assertSame(105, $print->cheque_no, '⛔ ভাউচারে লেখা বইয়ের নম্বর ফেলে অন্য নম্বরে ছাপা হলো।');
    }

    public function test_an_off_the_books_cheque_moves_no_money_and_only_its_key_holder_sees_it(): void
    {
        $own = $this->book(null, 45110, 45160);
        $company = $this->book($this->bank, 1, 50);
        $entries = DB::table('ledger_entries')->count();

        $this->post(route('accounts.cheque.print.free.store'), ['book' => $own->id, 'cheque_date' => now()->toDateString(), 'payee' => 'বাড়িওয়ালা জনাব করিম', 'amount' => '50000', 'crossed' => '1', 'purpose' => 'বাড়ি ভাড়া'])
            ->assertOk();
        $print = ChequePrint::query()->sole();
        $this->assertTrue($print->off_books);
        $this->assertNull($print->voucher_id);
        $this->assertSame(45110, $print->cheque_no);
        $this->assertSame($entries, DB::table('ledger_entries')->count(), '⛔ হিসাব ছাড়া চেক খাতায় দাখিলা বসাল।');

        $this->post(route('accounts.cheque.print.free.store'), ['book' => $company->id, 'cheque_date' => now()->toDateString(), 'payee' => 'X', 'amount' => '10', 'crossed' => '1'])
            ->assertSessionHasErrors('book');

        // ⛔ চেকের খাতা দেখেন, ছাপেনও — কিন্তু হিসাব ছাড়ার চাবি নেই
        $clerk = User::factory()->create(['current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id]);
        $clerk->companies()->attach($this->company->id, ['is_active' => true]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $role = Role::findOrCreate('Cheque clerk', 'web');
        $role->givePermissionTo(['accounts.view', 'accounts.cheque.view', 'accounts.cheque.print']);
        $clerk->assignRole($role);
        $this->actingAs($clerk);

        $this->get(route('accounts.cheque.print.log'))->assertOk()->assertDontSee('বাড়িওয়ালা জনাব করিম');
        $this->get(route('accounts.cheque.print.free'))->assertForbidden();
        $this->post(route('accounts.cheque.print.spoil', $print), ['reason' => 'x'])->assertForbidden();
    }

    public function test_the_pages_open_and_the_register_offers_print_on_a_pending_issued_cheque(): void
    {
        $this->book($this->bank, 1, 50);
        $this->book(null, 1, 50);
        $cheque = $this->issued('3000', '');

        $this->get(route('accounts.cheque.print.setup'))->assertOk()->assertSee('data-cheque-layout="new"', false)->assertSee('data-cheque-book="new"', false);
        $this->get(route('accounts.cheque.print.free'))->assertOk()->assertSee('data-free-warning', false);
        $this->get(route('accounts.cheque.print.log'))->assertOk()->assertSee('data-cheque-log', false);
        $this->get(route('accounts.cheque.print.cheque', $cheque))->assertOk()->assertSee('data-cheque-print-button', false);
        $this->get(route('accounts.cheque.index'))->assertOk()->assertSee(route('accounts.cheque.print.cheque', $cheque), false);
    }

    public function test_a_test_print_writes_nothing_and_a_layout_and_a_book_are_saved_from_the_setup_page(): void
    {
        $book = $this->book($this->bank, 1, 50);
        $cheque = $this->issued('3000', '');
        $this->post(route('accounts.cheque.print.cheque.test', $cheque), ['book' => $book->id, 'payee' => 'X', 'crossed' => '1'])->assertOk();
        $this->assertSame(0, ChequePrint::query()->count(), '⛔ পরীক্ষার ছাপ খাতায় উঠল।');
        $this->assertSame(1, $book->fresh()->next_no, '⛔ পরীক্ষার ছাপ বইয়ের নম্বর খেয়ে ফেলল।');

        $fields = ChequeLayout::standard()['fields'];
        $fields['payee']['x'] = 26.5;
        $this->post(route('accounts.cheque.print.setup.layout.store'), ['name' => 'DBBL', 'width_mm' => '200', 'height_mm' => '90', 'offset_x' => '1.5', 'offset_y' => '-0.5', 'fields' => $fields])
            ->assertRedirect()->assertSessionHasNoErrors();
        $saved = ChequeLayout::query()->where('name', 'DBBL')->sole();
        $this->assertSame('26.50', $saved->field('payee')['x']);

        $this->post(route('accounts.cheque.print.setup.book.store'), ['label' => 'DBBL বই ২', 'bank_account_id' => $this->bank->id, 'off_books' => '0', 'first_no' => '900', 'last_no' => '800', 'digits' => '7', 'layout_id' => $saved->id])
            ->assertSessionHasErrors('last_no');
        $this->post(route('accounts.cheque.print.setup.book.store'), ['label' => 'DBBL বই ২', 'off_books' => '0', 'first_no' => '800', 'last_no' => '900', 'digits' => '7', 'layout_id' => $saved->id])
            ->assertSessionHasErrors('bank_account_id');
        $this->post(route('accounts.cheque.print.setup.book.store'), ['label' => 'DBBL বই ২', 'bank_account_id' => $this->bank->id, 'off_books' => '0', 'first_no' => '800', 'last_no' => '900', 'digits' => '7', 'layout_id' => $saved->id, 'is_active' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame(800, ChequeBook::query()->where('label', 'DBBL বই ২')->sole()->next_no);
    }

    /**
     * ⭐ বই না থাকলেও "শুধু চেক ছাপা" কাজ করে — মালিক, 11 নভেম্বর 2026: "শুধু চেক ছাপা কি করে create করবে, কিছুই নাই, জাস্ট মেনুতে
     * চাপলে স্ক্রিন আসে"। ⛔ আগে হিসাব-ছাড়া বই না থাকলে পাতায় ফর্মই আসত না। ⓘ এখন ফর্মেই নতুন বই: পরীক্ষার ছাপে কিছু বসে না,
     * আসল ছাপায় বই বসে প্রথম নম্বরে ছাপে, একই বই আবার লিখলে নতুন বই নয় — পরের নম্বর।
     */
    public function test_with_no_book_the_cheque_only_page_still_prints_and_adds_the_book_from_the_form(): void
    {
        $this->assertSame(0, ChequeBook::query()->where('off_books', true)->count(), 'প্রস্তুতিটাই ভুল — হিসাব ছাড়া বই আগে থেকেই আছে।');

        $this->get(route('accounts.cheque.print.free'))->assertOk()
            ->assertSee('data-cheque-print-button', false)
            ->assertSee('data-cheque-new-book', false)
            ->assertSee('value="new" selected', false);

        $form = ['book' => 'new', 'new_label' => 'সোনালী — নিজের', 'new_first' => '0045110', 'new_last' => '0045160',
            'cheque_date' => now()->toDateString(), 'payee' => 'বাড়িওয়ালা জনাব করিম', 'amount' => '50000', 'crossed' => '1'];

        $this->post(route('accounts.cheque.print.free.store'), $form + ['test' => '1'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(0, ChequeBook::query()->where('off_books', true)->count(), '⛔ পরীক্ষার ছাপ বই বসিয়ে দিল।');
        $this->assertSame(0, ChequePrint::query()->count(), '⛔ পরীক্ষার ছাপ খাতায় উঠল।');

        $this->post(route('accounts.cheque.print.free.store'), $form)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $book = ChequeBook::query()->where('off_books', true)->sole();
        $this->assertSame([45110, 45160, 45111, 7], [$book->first_no, $book->last_no, $book->next_no, $book->digits], '⛔ ফর্মের বই ঠিক বসেনি — নম্বর, পরের পাতা বা অঙ্কের ঘর ভুল।');
        $this->assertSame('0045110', $book->number(ChequePrint::query()->sole()->cheque_no), '⛔ প্রথম পাতার নম্বরে ছাপেনি।');

        $this->post(route('accounts.cheque.print.free.store'), $form)->assertOk();
        $this->assertSame(1, ChequeBook::query()->where('off_books', true)->count(), '⛔ একই বই আবার লেখায় দ্বিতীয় বই হলো — একই নম্বর দুই বইয়ে।');
        $this->assertSame([45110, 45111], ChequePrint::query()->orderBy('id')->pluck('cheque_no')->all(), '⛔ দ্বিতীয় চেক পরের পাতায় ছাপেনি।');

        $this->post(route('accounts.cheque.print.free.store'), ['book' => 'new', 'new_label' => 'X', 'new_first' => '200', 'new_last' => '100'] + array_diff_key($form, ['new_label' => 1, 'new_first' => 1, 'new_last' => 1, 'book' => 1]))
            ->assertSessionHasErrors('new_last');
        $this->post(route('accounts.cheque.print.free.store'), ['book' => 'new'] + array_diff_key($form, ['new_label' => 1, 'book' => 1]))
            ->assertSessionHasErrors('new_label');
    }

    public function test_the_words_are_the_cheque_kind(): void
    {
        $this->assertSame('Taka One Lakh Twenty Five Thousand Only', AmountInWords::cheque('125000'));
        $this->assertSame('Taka Twelve Lakh Thirty Four Thousand Five Hundred Sixty Seven And Paisa Fifty Only', AmountInWords::cheque('1234567.5'));
        $this->assertSame('Taka One Crore Only', AmountInWords::cheque('10000000'));
    }

    private function bankAccount(string $code, string $name): Account
    {
        return Account::query()->create([
            'company_id' => $this->company->id, 'code' => $code, 'name_en' => $name, 'name_bn' => $name,
            'parent_id' => StandardChart::find(StandardChart::BANK)->id, 'type' => Account::ASSET,
            'nature' => Account::DEBIT, 'money_kind' => Account::BANK,
        ]);
    }

    private function book(?Account $bank, int $first, int $last): ChequeBook
    {
        return ChequeBook::query()->create([
            'company_id' => $this->company->id, 'bank_account_id' => $bank?->id, 'off_books' => $bank === null,
            'label' => $bank === null ? 'নিজের বই' : 'কোম্পানির বই '.$bank->code, 'first_no' => $first, 'last_no' => $last, 'next_no' => $first,
            'digits' => 7, 'layout_id' => $this->layout->id, 'is_active' => true,
        ]);
    }

    private function issued(string $amount, string $no): Cheque
    {
        return app(ChequeService::class)->create([
            'direction' => Cheque::ISSUED, 'cheque_date' => now()->toDateString(), 'received_on' => now()->toDateString(),
            'cheque_no' => $no, 'amount' => $amount, 'party_type' => 'supplier', 'party_id' => Supplier::query()->firstOrFail()->id,
            'bank_account_id' => $this->bank->id,
        ]);
    }
}

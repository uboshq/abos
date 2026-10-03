<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Services\PaperTrail;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\DocumentDelivery;
use App\Models\User;
use App\Modules\Accounts\Models\Note;
use App\Modules\Accounts\Services\NoteService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * ডেবিট/ক্রেডিট নোটের কোনো ছাপা ছিল না — মালিক, ৩ অক্টোবর ২০২৬ ([[NotePrintController]])।
 *
 * ⭐ এখন A4, A5, থার্মাল; দেখার পাতা আর তালিকার সারি থেকে; প্রতিটা ছাপা গোনা, দ্বিতীয়বার থেকে DUPLICATE, বাতিল
 * নোট "বাতিল" বলে। কাগজে দিক, নম্বর, পক্ষের নাম-কোড, কারণ, টাকা আর কথায়, বিপরীতের কাগজ।
 */
final class ANoteCouldNotBePrintedTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    /** @var list<array<string, mixed>> */
    private array $drawn = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();
        $this->customer = Customer::query()->firstOrFail();

        View::composer('print.note', function ($view) {
            $this->drawn[] = $view->getData();
        });
    }

    public function test_the_note_prints_on_every_paper_with_its_facts(): void
    {
        $note = $this->confirmedNote();

        foreach (['a4', 'a5', '80mm'] as $paper) {
            $this->get(route('accounts.note.print', ['note' => $note, 'paper' => $paper]))
                ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        }

        $facts = $this->drawn[0]['note'];

        $this->assertSame(__('accounts::note.credit_note'), $facts['direction']);
        $this->assertSame((string) $note->document_no, $facts['document_no']);
        $this->assertSame($this->customer->name(), $facts['party_name'], '⛔ কাগজে পক্ষের নাম নেই।');
        $this->assertSame((string) $this->customer->code, $facts['party_code'], '⛔ কাগজে পক্ষের কোড নেই।');
        $this->assertSame('INV-TEST-1', $facts['against_no']);
        $this->assertNotSame('', $facts['in_words'], '⛔ টাকার অঙ্ক কথায় নেই।');
        $this->assertCount(3, $this->drawn[0]['signatures']);
    }

    /** ⭐ প্রতিটা ছাপা গোনা — দ্বিতীয়বার থেকে DUPLICATE, nতম ছাপা */
    public function test_every_print_is_counted_and_a_reprint_says_duplicate(): void
    {
        $note = $this->confirmedNote();

        $this->get(route('accounts.note.print', $note))->assertOk();
        $this->get(route('accounts.note.print', $note))->assertOk();

        $this->assertNull($this->drawn[0]['notice'], '⛔ প্রথম ছাপাই DUPLICATE বলছে।');
        $this->assertSame(__('core.print.duplicate_notice', ['n' => 2]), $this->drawn[1]['notice'], '⛔ দ্বিতীয় ছাপায় DUPLICATE নেই।');
        $this->assertSame(2, app(PaperTrail::class)->countsFor('accounts_note', (int) $note->id)[DocumentDelivery::PRINTED]);
    }

    public function test_a_cancelled_note_says_so(): void
    {
        $note = $this->confirmedNote();
        app(NoteService::class)->cancel($note->fresh(), 'ভুল পক্ষ');

        $this->get(route('accounts.note.print', $note))->assertOk();

        $this->assertSame(__('accounts::print.cancelled'), $this->drawn[0]['notice']);
    }

    /** ⓘ দেখার পাতায় ছাপার মেনু, তালিকার সারিতে ছাপা — পথ না থাকলে ছাপাটা থেকেও নেই */
    public function test_the_show_page_and_the_list_row_offer_the_print(): void
    {
        $note = $this->confirmedNote();
        $url = route('accounts.note.print', $note);

        $this->get(route('accounts.note.show', $note))->assertOk()->assertSee($url, false);
        $this->get(route('accounts.note.index', ['direction' => Note::CREDIT]))->assertOk()
            ->assertSee('data-note-print', false)->assertSee($url, false);
    }

    public function test_the_print_asks_for_the_note_view_key(): void
    {
        $note = $this->confirmedNote();
        $outsider = User::factory()->create(['current_company_id' => CompanyContext::id()]);
        $outsider->companies()->attach(CompanyContext::id(), ['is_active' => true]);

        $this->actingAs($outsider)->get(route('accounts.note.print', $note))->assertForbidden();

        $outsider->givePermissionTo('accounts.note.view');
        $this->actingAs($outsider->fresh())->get(route('accounts.note.print', $note))->assertOk();
    }

    private function confirmedNote(): Note
    {
        $service = app(NoteService::class);

        return $service->confirm($service->create([
            'direction' => Note::CREDIT,
            'party_type' => 'customer',
            'party_id' => $this->customer->id,
            'trx_date' => now()->toDateString(),
            'amount' => '1200',
            'tax_amount' => '0',
            'reason' => 'price_correction',
            'against_no' => 'INV-TEST-1',
            'narration' => 'দাম ভুল বসেছিল',
        ]));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\OpenPeriod;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\PeriodLock;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ বন্ধ মাসের বিল সম্পাদনা হয় না — fe, ২১ অক্টোবর ২০২৬ (IAS 8; [[SaleEditor::assertEditable()]], [[SaleEditor::edit()]])।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * সম্পাদনা পুরনো বিল উল্টাত আজকের তারিখে আর নতুনটা বসাত আজ (তারিখ ফাঁকা হলে) — আগস্টের বন্ধ মাসের বিক্রি চুপচাপ অক্টোবরে
 * সরে যেত, আর কোনো তালা থামাত না, কারণ দুই দাখিলাই খোলা মাসে। পর্দা পুরনো তারিখ পাঠালে নতুন রূপ বসতে চাইত বন্ধ মাসে।
 *
 * ── ⭐ এখন ───────────────────────────────────────────────────────────
 *   · বিলের মাস বন্ধ → সম্পাদনা নয়: "এই মাস বন্ধ — বিলটা বাতিল করে নতুন বিল করুন";
 *   · নতুন রূপের তারিখ বন্ধ মাসে → একই বার্তা, কিছু উল্টানোর আগে।
 * আসল দরজা দিয়ে (`sales.direct.store`, ওয়েব — থামা মানে ৩০২ আর সেশনের ত্রুটি); খাতা, খরচের স্তরের টান আর মজুদের চলাচলে
 * একটা সারিও বাড়ে না।
 */
final class AClosedMonthsBillIsNotEditedTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['ledger_entries', 'inv_cost_layer_uses', 'inv_stock_movements'];

    private Company $company;

    private User $owner;

    private Product $biscuit;

    private Warehouse $warehouse;

    /** @var array<string, mixed> */
    private array $data;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->biscuit = Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->data = [
            'customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
            'warehouse_id' => $this->warehouse->id,
            'own_transport' => '1',
        ];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** ⛔ আগস্টের বিল, আগস্ট বন্ধ, আজ অক্টোবর — সম্পাদনা থামে, কিছুই বদলায় না */
    public function test_a_bill_in_a_closed_month_is_not_edited(): void
    {
        Carbon::setTestNow('2026-08-10 10:00:00');
        $sale = $this->sell('2');

        Carbon::setTestNow('2026-10-10 10:00:00');
        $this->closeAugust();

        // প্রস্তুতি: বিলটা সত্যিই আগস্টের, আগস্ট সত্যিই বন্ধ, অক্টোবর খোলা
        $this->assertSame('2026-08-10', $sale->trx_date->toDateString(), 'প্রস্তুতিটাই ভুল — বিলটা আগস্টের নয়।');
        $this->assertSame(DocumentStatus::CONFIRMED, $sale->status, 'প্রস্তুতিটাই ভুল — বিলটা পাকা নয়।');
        $this->assertNotNull(app(OpenPeriod::class)->lockOn('2026-08-10'), 'প্রস্তুতিটাই ভুল — আগস্ট বন্ধ নয়।');
        $this->assertNull(app(OpenPeriod::class)->lockOn('2026-10-10'), 'প্রস্তুতিটাই ভুল — অক্টোবরও বন্ধ।');

        $rows = $this->lastIds();
        $edit = route('sales.direct.create', ['edit' => $sale->id]);

        // ⓘ তারিখ ফাঁকা — কাউন্টারের নিয়মে আজ (অক্টোবর); তবু থামে, কারণ বিলের মাস বন্ধ
        $response = $this->from($edit)->post(route('sales.direct.store'), $this->form($sale, '3'));

        $response->assertStatus(302)->assertRedirect($edit);
        $response->assertSessionHasErrors(['edit' => __('sales::validation.edit_month_closed', ['no' => $sale->document_no])]);
        $this->assertSame('এই মাস বন্ধ — বিলটা বাতিল করে নতুন বিল করুন', __('sales::validation.edit_month_closed', [], 'bn'),
            '⛔ বার্তাটা fe-র বাংলা কথা নয়।');

        $fresh = $sale->fresh();
        $this->assertSame(DocumentStatus::CONFIRMED, $fresh->status, '⛔ বন্ধ মাসের বিল খসড়ায় পড়ে আছে।');
        $this->assertSame(0, bccomp('20', (string) $fresh->total, 4), '⛔ বন্ধ মাসের বিলের মোট বদলেছে।');
        $this->assertSame('2026-08-10', $fresh->trx_date->toDateString(), '⛔ বন্ধ মাসের বিল অন্য মাসে সরে গেছে।');
        $this->assertSame($rows, $this->lastIds(), '⛔ থামা সম্পাদনায় খাতা, খরচের স্তর বা মজুদে নতুন সারি বসেছে।');

        // ⓘ বিলের পাতায় সম্পাদনার বোতামও নেই — একই পাহারা ([[SaleEditor::assertEditable()]])
        $this->get(route('sales.invoice.show', $sale))->assertOk()->assertDontSee('data-edit-sale', false);
    }

    /** ⛔ এ মাসের বিল, কিন্তু নতুন রূপের তারিখ বন্ধ আগস্টে — থামে; একই বিল অক্টোবরের তারিখে সম্পাদনা চলে */
    public function test_an_edit_onto_a_closed_months_date_is_refused(): void
    {
        Carbon::setTestNow('2026-10-10 10:00:00');
        $sale = $this->sell('2');
        $this->closeAugust();

        $this->assertSame('2026-10-10', $sale->trx_date->toDateString(), 'প্রস্তুতিটাই ভুল — বিলটা অক্টোবরের নয়।');
        $this->assertNotNull(app(OpenPeriod::class)->lockOn('2026-08-15'), 'প্রস্তুতিটাই ভুল — আগস্ট বন্ধ নয়।');

        $rows = $this->lastIds();
        $edit = route('sales.direct.create', ['edit' => $sale->id]);

        $response = $this->from($edit)->post(route('sales.direct.store'), $this->form($sale, '3', '2026-08-15'));

        $response->assertStatus(302)->assertRedirect($edit);
        $response->assertSessionHasErrors(['edit' => __('sales::validation.edit_month_closed', ['no' => $sale->document_no])]);
        $this->assertSame(DocumentStatus::CONFIRMED, $sale->fresh()->status, '⛔ থামা সম্পাদনায় বিল খসড়ায় পড়ে আছে।');
        $this->assertSame(0, bccomp('20', (string) $sale->fresh()->total, 4), '⛔ থামা সম্পাদনায় মোট বদলেছে।');
        $this->assertSame($rows, $this->lastIds(), '⛔ বন্ধ মাসের তারিখের সম্পাদনায় খাতা, খরচের স্তর বা মজুদে নতুন সারি বসেছে।');

        // ⓘ খোলা মাসের তারিখে একই সম্পাদনা চলে — থামার কারণ কেবল তারিখটা
        $this->post(route('sales.direct.store'), $this->form($sale, '3', '2026-10-10'))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, bccomp('30', (string) $sale->fresh()->total, 4), 'প্রস্তুতিটাই ভুল — খোলা মাসেও সম্পাদনা হয়নি।');
        $this->assertSame(DocumentStatus::CONFIRMED, $sale->fresh()->status);
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function closeAugust(): void
    {
        PeriodLock::query()->create([
            'company_id' => $this->company->id, 'year' => 2026, 'month' => 8,
            'reason' => 'আগস্টের হিসাব ছাপা', 'locked_by' => $this->owner->id, 'locked_at' => now(),
        ]);
    }

    private function sell(string $qty): SalesInvoice
    {
        $this->actingAs($this->owner);

        return app(DirectSaleService::class)->complete($this->data, [['product_id' => $this->biscuit->id, 'qty' => $qty, 'rate' => '10', 'free_qty' => '0']])['invoice']->fresh();
    }

    /** @return array<string, mixed> */
    private function form(SalesInvoice $sale, string $qty, ?string $date = null): array
    {
        return [
            ...$this->data,
            'edit_invoice_id' => $sale->id,
            'payment_term' => 'credit',
            'lines' => [['product_id' => $this->biscuit->id, 'qty' => $qty, 'rate' => '10', 'free_qty' => '0']],
            ...($date === null ? [] : ['trx_date' => $date]),
        ];
    }

    /** @return array<string, int> */
    private function lastIds(): array
    {
        return collect(self::TABLES)->mapWithKeys(fn (string $t) => [$t => (int) DB::table($t)->max('id')])->all();
    }
}

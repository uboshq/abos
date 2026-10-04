<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Print\PrintableDocument;
use App\Core\Engines\Print\PrintProfile;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Sales\Support\InvoicePaperView;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বিলের ACCOUNT MOVEMENT — মালিক, ৪ অক্টোবর ২০২৬ (INV-0002-এর ছাপা):
 * *"SL-3064 — গ্রাহকের কাছে পাওনা ei sobdo ta na likhe smart ekta vasa likho, Balance ta actual amount ta likho"*।
 *
 * ⭐ বিবরণ = নম্বর — কাগজের ধরন ("SL-3064 — Sales Invoice"); জের = খাতার নিয়মে "(Dr) …" / "(Cr) …"।
 * ⓘ ধরন অজানা হলে খাতার নিজের বিবরণই থাকে — কোনো সারি ফাঁকা হয় না।
 */
final class TheMovementSaysWhatEachPaperWasTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_each_row_names_its_paper_and_the_balance_is_the_amount(): void
    {
        $rows = $this->paper([
            ['date' => '01/10/2026', 'text' => '', 'debit' => '', 'credit' => '', 'balance' => '-100000'],
            ['date' => '01/10/2026', 'text' => 'SL-3064 — গ্রাহকের কাছে পাওনা', 'debit' => '49973.12', 'credit' => '', 'balance' => '-50026.88',
                'doc' => 'SL-3064', 'kind' => 'sales_invoice'],
            ['date' => '02/10/2026', 'text' => 'RCV-1 — জমা', 'debit' => '', 'credit' => '500', 'balance' => '-50526.88',
                'doc' => 'RCV-1', 'kind' => 'receipt'],
            ['date' => '03/10/2026', 'text' => 'X-9 — পুরনো বিবরণ', 'debit' => '60000', 'credit' => '', 'balance' => '9473.12',
                'doc' => 'X-9', 'kind' => null],
        ])->movement([]);

        $this->assertSame('SL-3064 — Sales Invoice', $rows[1]['text'], '⛔ বিক্রয় বিলের সারি এখনো খাতার বিবরণ ছাপে।');
        $this->assertSame('RCV-1 — Payment Received', $rows[2]['text']);
        $this->assertSame('X-9 — পুরনো বিবরণ', $rows[3]['text'], '⛔ অজানা ধরনের সারির বিবরণ হারিয়েছে।');

        $this->assertSame('(Cr) 1,00,000.00', $this->normal($rows[0]['balance']), '⛔ শুরুর জের আসল অঙ্কে নয়: '.$rows[0]['balance']);
        $this->assertStringNotContainsString('Advance', $rows[1]['balance'], '⛔ জেরে এখনো "Advance" শব্দ।');
        $this->assertStringStartsWith('(Cr) ', $rows[1]['balance']);
        $this->assertStringStartsWith('(Dr) ', $rows[3]['balance'], '⛔ বকেয়ার জের (Dr) নয়।');
    }

    /** ⓘ দেশি না আন্তর্জাতিক কমা — কোম্পানির নিয়ম; এখানে কেবল অঙ্কটা মেলানো */
    private function normal(string $text): string
    {
        return str_replace(['1,00,000.00', '100,000.00'], '1,00,000.00', $text);
    }

    /** @param  list<array<string, mixed>>  $movement */
    private function paper(array $movement): InvoicePaperView
    {
        $facts = [
            'bill_to' => ['name' => 'X', 'point' => '', 'phone' => '', 'address' => ''],
            'transport' => ['carrier' => '', 'driver_name' => '', 'driver_phone' => '', 'vehicle' => '', 'delivery_date' => ''],
            'bill' => ['bill_date' => '', 'bill_no' => 'S-1', 'order_no' => '', 'type' => '', 'created_by' => ''],
            'items' => ['rows' => [], 'totals' => ['qty' => '', 'free' => '', 'total_qty' => '', 'amount' => '0.00']],
            'sums' => [
                'grand_total' => '0.00', 'discount' => '0.00', 'vat' => '0.00', 'rounding' => '0.00', 'net_payable' => '0.00',
                'paid' => '0.00', 'invoice_due' => '0.00', 'previous_due' => '0.00', 'outstanding' => '0.00',
            ],
            'target' => null,
            'words' => '',
            'scan_url' => '',
            'movement' => $movement,
        ];

        return new InvoicePaperView(new PrintableDocument(title: 'Invoice'), $facts, $this->company, PrintProfile::everything());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Services\PartyRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ জাবেদার সারিতে খোঁজা যায় এমন পক্ষ আর খাত — মালিক, ৫ অক্টোবর ২০২৬ (সমন্বয়কের মারফত): "প্রতিটা সারির পক্ষ বাছার
 * ঘর সাধারণ select, খোঁজা যায় না, গ্রাহকের লম্বা তালিকা স্ক্রল করতে হয়"।
 *
 * ⭐ দাবি:
 *   প্রতিটা সারিতে খাত আর পক্ষের ঘর খোঁজার পিকার — পুরনো `<select>` নেই, খোঁজার ঘরের `name` নেই;
 *   পক্ষের তালিকা দল ধরে (গ্রাহক · সরবরাহকারী · ব্যক্তি …), মান "type:id", দুটোই ঐচ্ছিক ("—");
 *   আগের নামেই মান যায় — বাছা পক্ষ খাতায় তাঁর নামে বসে; সম্পাদনায় আগের বাছাই ঘরে ফেরে।
 */
final class TheJournalRowsHadNoSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
    }

    public function test_every_row_offers_a_search_for_its_account_and_its_party_and_no_plain_select(): void
    {
        $xpath = $this->xpath((string) $this->get(route('accounts.voucher.create', 'journal'))->assertOk()->getContent());

        $rows = $xpath->query('//form//tbody/tr')->length;
        $this->assertGreaterThanOrEqual(5, $rows, 'প্রস্তুতিটাই ভুল — জাবেদায় অন্তত পাঁচটা সারি থাকে।');

        foreach (['account_id' => 'data-journal-account', 'party' => 'data-journal-party'] as $field => $marker) {
            $this->assertSame(0, $xpath->query("//select[contains(@name, '[{$field}]')]")->length,
                "⛔ সারির {$field} এখনো খোঁজা-ছাড়া `<select>`।");
            $this->assertSame($rows, $xpath->query("//*[@{$marker}]//input[@data-party-search]")->length,
                "⛔ প্রতিটা সারির {$field} ঘরে খোঁজার ঘর নেই।");
            $this->assertSame(0, $xpath->query("//*[@{$marker}]//input[@data-party-search][@name]")->length,
                '⛔ খোঁজার ঘরের `name` আছে — লেখাটা ফর্মের সাথে চলে যেত।');
            $this->assertSame(1, $xpath->query("//input[@type='hidden'][@name='lines[0][{$field}]']")->length,
                "⛔ প্রথম সারির {$field} আগের নামে ফর্মের সাথে যায় না।");
        }

        /** @var DOMElement $party */
        $party = $xpath->query('//*[@data-journal-party]')->item(0);
        $data = $party->getAttribute('x-data');
        $customer = collect(app(PartyRegistry::class)->forPicker())->firstWhere('type', 'customer');
        $this->assertStringContainsString('clearable: true', $data, 'পক্ষ ঐচ্ছিক — "—" দিয়ে খালি করা যায়।');
        $this->assertStringContainsString(json_encode('customer:'.$customer['options'][0]['id']), $data, 'মান "type:id" — আগের select-এর হুবহু।');
        $this->assertStringContainsString(json_encode($customer['label'], JSON_UNESCAPED_UNICODE), $data, 'পক্ষের দলের নাম তালিকায় নেই।');
    }

    public function test_a_picked_party_lands_in_its_name_and_comes_back_when_editing(): void
    {
        $customer = collect(app(PartyRegistry::class)->forPicker())->firstWhere('type', 'customer')['options'][0];
        $receivable = Account::query()->where('code', '1110')->firstOrFail();
        $sales = Account::query()->where('code', '4100')->firstOrFail();

        $this->post(route('accounts.voucher.store', 'journal'), [
            'type' => 'journal',
            'trx_date' => now()->toDateString(),
            'narration' => 'খোঁজার পিকারে বাছা পক্ষ',
            'save_as_draft' => '1',
            'lines' => [
                ['account_id' => $receivable->id, 'debit' => '100', 'credit' => '', 'party' => 'customer:'.$customer['id']],
                ['account_id' => $sales->id, 'debit' => '', 'credit' => '100', 'party' => ''],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $voucher = Voucher::query()->where('type', 'journal')->latest('id')->firstOrFail();
        $line = $voucher->lines()->where('account_id', $receivable->id)->firstOrFail();
        $this->assertSame('customer', $line->party_type);
        $this->assertSame((int) $customer['id'], (int) $line->party_id, '⛔ পিকারে বাছা পক্ষ সারিতে বসেনি।');

        $xpath = $this->xpath((string) $this->get(route('accounts.voucher.edit', $voucher))->assertOk()->getContent());
        $values = collect(iterator_to_array($xpath->query("//input[@type='hidden'][contains(@name, '[party]')]")))
            ->map(fn (DOMElement $i) => $i->getAttribute('value'))->filter()->values()->all();
        $this->assertSame(['customer:'.$customer['id']], $values, '⛔ সম্পাদনায় আগের বাছা পক্ষ ঘরে ফেরেনি।');
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}

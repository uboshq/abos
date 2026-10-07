<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Services\PartyRegistry;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;
use Database\Seeders\DemoSeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ ডিপোজিটর / প্রাপকের নামে খোঁজা — মালিক, ৩ অক্টোবর ২০২৬।
 *
 * ── ⛔ অভিযোগ ─────────────────────────────────────────────────────────
 * রসিদের "ডিপোজিটরের নাম" আর পরিশোধের "প্রাপকের নাম" একটা লম্বা সাধারণ
 * `<select>` — খোঁজার ঘর নেই। ইউবি-তে ৪১৪ জন গ্রাহক, আর মালিক *নাম খুঁজে
 * পান না*। ⚠️ তালিকায় একই নামের দুইটা দোকানও ("M/S. Bismillah Store") —
 * নাম একা তাঁদের আলাদা করে না।
 *
 * ── ⓘ এই পরীক্ষা যা ধরে ───────────────────────────────────────────────
 * ১. দুই পর্দাতেই খোঁজার ঘর আছে, আর তার কোনো `name` নেই (থাকলে লেখাটাও
 *    ফর্মের সাথে যেত)।
 * ২. `party_id` আগের নামেই যায় — এখন লুকানো ঘরে, `<select>`-এ নয় — আর
 *    ভুল জমার পরে বাছা নামটা ফেরে।
 * ৩. একই নামের দুই দোকানের তালিকার সারিতে কোড আর পয়েন্ট, আর খোঁজার লেখায়
 *    কোড · পয়েন্ট · মোবাইল।
 *
 * ⚠️ খোঁজা, কীবোর্ড আর loadDue-র দাবি JS-এর: `resources/js/party-voucher.test.js`।
 */
final class TheDepositorListHadNoSearchTest extends TestCase
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

    public function test_both_screens_offer_a_search_box_that_is_not_sent_with_the_form(): void
    {
        foreach ([Voucher::RECEIPT, Voucher::PAYMENT] as $type) {
            $xpath = $this->dom($type);

            $search = $xpath->query('//input[@data-party-search]');
            $this->assertSame(1, $search->length, "⛔ {$type}: নামের তালিকায় খোঁজার ঘর নেই।");

            /** @var DOMElement $box */
            $box = $search->item(0);
            $this->assertFalse($box->hasAttribute('name'),
                "⛔ {$type}: খোঁজার ঘরের `name` আছে — লেখাটা ফর্মের সাথে চলে যেত।");
            $this->assertSame((string) __('accounts::field.party_search'), $box->getAttribute('placeholder'));

            $this->assertSame(1, $xpath->query('//button[@data-party-picker]')->length,
                "⛔ {$type}: নাম বাছার বোতাম নেই।");
        }
    }

    public function test_party_id_still_goes_under_its_own_name_and_comes_back_after_a_failed_save(): void
    {
        $customer = Customer::query()->firstOrFail();

        foreach ([Voucher::RECEIPT, Voucher::PAYMENT] as $type) {
            $fresh = $this->dom($type);

            // ⓘ simple-form-এর নিজের পক্ষের ঘরটা রসিদ/পরিশোধে `disabled` — জমা পড়ে না, তাই গোনা হয় না
            $this->assertSame(0, $fresh->query('//select[@name="party_id" and not(@disabled)]')->length,
                "⛔ {$type}: পুরনো খোঁজা-ছাড়া `<select>`-টা এখনো আছে।");

            $sent = $fresh->query('//form//input[@name="party_id"]');
            $this->assertSame(1, $sent->length, "⛔ {$type}: `party_id` ফর্মের সাথে যায় না — সার্ভার পক্ষ পেত না।");

            /** @var DOMElement $hidden */
            $hidden = $sent->item(0);
            $this->assertSame('hidden', $hidden->getAttribute('type'));
            $this->assertFalse($hidden->hasAttribute('disabled'));

            // ⓘ ভুল জমার পরে old() — বাছা নামটা লুকানো ঘরে ফিরে আসে
            $again = $this->dom($type, ['party_type' => 'customer', 'party_id' => (string) $customer->id]);

            /** @var DOMElement $kept */
            $kept = $again->query('//form//input[@name="party_id"]')->item(0);
            $this->assertSame((string) $customer->id, $kept->getAttribute('value'),
                "⛔ {$type}: ভুল জমার পরে বাছা নামটা হারিয়ে যায়।");
        }
    }

    public function test_two_shops_with_one_name_are_told_apart_by_code_and_point(): void
    {
        $point = Location::query()->create([
            'company_id' => $this->company->id, 'code' => 'PT-KB', 'level' => Location::POINT,
            'name_en' => 'Kawran Bazar Point', 'name_bn' => null, 'is_active' => true,
        ]);

        $one = $this->shop('BIS-001', $point->id, '01711000001');
        $two = $this->shop('BIS-002', null, '01711000002');

        $group = collect(app(PartyRegistry::class)->forPicker())->firstWhere('type', 'customer');
        $rows = collect($group['options'])->keyBy('id');

        $first = $rows[(int) $one->id];
        $second = $rows[(int) $two->id];

        $this->assertSame($first['label'], $second['label'], 'দুই দোকানের নাম এক — পরীক্ষার পূর্বশর্ত।');

        $this->assertSame('BIS-001 · Kawran Bazar Point · 01711000001', $first['hint']);
        $this->assertSame('BIS-002 · 01711000002', $second['hint']);

        foreach (['bis-001', 'kawran', '01711000001', 'bismillah'] as $needle) {
            $this->assertStringContainsString($needle, $first['find'], "⛔ '{$needle}' দিয়ে দোকানটা খুঁজে পাওয়া যেত না।");
        }

        // ⓘ পর্দার x-data-তেই সারিগুলো পৌঁছায় — নিয়ন্ত্রক থেকে ব্লেড পর্যন্ত পথটা খোলা
        $html = (string) $this->get(route('accounts.voucher.create', ['type' => Voucher::RECEIPT]))->assertOk()->getContent();
        $this->assertStringContainsString('BIS-001', $html, '⛔ কোড পর্দায় পৌঁছায়নি।');
        $this->assertStringContainsString('Kawran Bazar Point', $html, '⛔ পয়েন্ট পর্দায় পৌঁছায়নি।');
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    private function shop(string $code, ?int $locationId, string $phone): Customer
    {
        return Customer::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'location_id' => $locationId,
            'code' => $code,
            'name_en' => 'M/S. Bismillah Store',
            'phone' => $phone,
            'status' => DocumentStatus::CONFIRMED,
            'is_active' => true,
        ]);
    }

    /** @param  array<string, string>  $old */
    private function dom(string $type, array $old = []): DOMXPath
    {
        $html = (string) $this->withSession($old === [] ? [] : ['_old_input' => $old])
            ->get(route('accounts.voucher.create', ['type' => $type]))
            ->assertOk()
            ->getContent();

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}

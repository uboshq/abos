<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\CompanyContext;
use App\Core\Support\MoneyMethod;
use App\Models\Company;
use Database\Seeders\DemoSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ দফা #২ (শুধু দরকারি ঘর) আর #৯ (চার্জ এক ঘরে, আমাদের খরচ) — টাকা নেওয়া-দেওয়ার ভাগ-করা পদ্ধতির ব্লক
 * ([[components.money.method]], [[MoneyMethod]]; মালিক, ২১ অক্টোবর ২০২৬, fe-র মাধ্যমে)।
 *
 * ⓘ এই দাবি ব্লকটাকে নিজে আঁকে — এখনো কোনো পর্দা এটা বসায়নি (ধাপ B আর C-তে বসবে, তখন দাবি আসল দরজা দিয়ে)। মাপা হয়:
 *  - পাঁচটা পদ্ধতি, নকশার ক্রমে; প্রতিটার ব্লকে কেবল তার ঘর;
 *  - সরানো ঘরগুলো (দাতার ব্যাংক, ব্রাঞ্চ, হিসাবধারী, হিসাব নম্বর, "চার্জ কে দিল") কোথাও নেই;
 *  - সার্ভার অন্য পদ্ধতির মান ফেলে দেয়, আর রেফারেন্স ছাড়া ব্যাংক/MFS/নগদ জমা পোস্ট হয় না।
 */
final class TheMoneyMethodAsksOnlyWhatItsMethodNeedsTest extends TestCase
{
    use RefreshDatabase;

    /** প্রতিটা পদ্ধতির ব্লকে যে ঘরগুলো — নকশার হুবহু */
    private const OWN = [
        'mfs' => ['instrument_no', 'charge_amount'],
        'transfer' => ['transfer_mode_id', 'instrument_no', 'value_date', 'charge_amount'],
        'deposit' => ['deposit_slip_no', 'value_date', 'charge_amount'],
        'cheque' => ['instrument_no', 'instrument_date', 'from_bank'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        // ⓘ ব্লক নিজে আঁকা — দরজা ছাড়া `$errors` ভাগ হয় না, আর ঘরগুলো ([[ui.field]]) ওটা পড়ে
        $this->withViewErrors([]);
    }

    public function test_item_2_five_methods_in_the_designs_order_and_each_block_holds_only_its_own_fields(): void
    {
        $html = (string) $this->blade('<form><x-money.method direction="out" /></form>');
        $xpath = $this->xpath($html);

        $chips = [];
        foreach ($xpath->query('//input[@name="instrument"][@type="radio"]') as $chip) {
            $chips[] = $chip->getAttribute('value');
        }
        $this->assertSame(['cash', 'mfs', 'transfer', 'deposit', 'cheque'], $chips, '⛔ #২: পদ্ধতির সেগমেন্ট নকশার পাঁচটা নয়, বা ক্রম আলাদা।');

        foreach (self::OWN as $method => $fields) {
            $block = $xpath->query("//*[@data-method-block='{$method}']")->item(0);
            $this->assertNotNull($block, "⛔ #২: {$method}-এর নিজের ব্লক নেই।");

            $names = [];
            foreach ($xpath->query('.//*[@name]', $block) as $field) {
                $names[] = $field->getAttribute('name');
            }
            $this->assertSame($fields, array_values(array_unique($names)), "⛔ #২: {$method}-এর ব্লকে নিজের ঘরের বাইরে কিছু, বা কম।");
        }

        $this->assertSame(0, $xpath->query("//*[@data-method-block='cash']")->length, '⛔ #২: নগদে কোনো ঘর থাকার কথা নয় (গোনা চাইলে তবে)।');
    }

    public function test_item_2_the_payers_bank_fields_and_the_charge_bearer_are_gone(): void
    {
        $html = (string) $this->blade('<form><x-money.method direction="in" /></form>');

        foreach (['from_branch', 'from_account_name', 'from_account_no', 'charge_borne_by', 'wallet', 'counterparty_phone'] as $gone) {
            $this->assertStringNotContainsString('name="'.$gone.'"', $html, "⛔ #২: সরানো ঘর {$gone} ফিরে এসেছে।");
        }
        $this->assertStringContainsString('data-charge-line', $html, '⛔ #৯: চার্জ কোথায় যাবে সেই এক লাইন নেই।');
        $this->assertStringContainsString('data-line-in="'.e(__('money.charge_line.in')).'"', $html, '⛔ #৯: চার্জের লাইনের ছাঁচ পাতায় নেই।');
    }

    public function test_item_2_an_old_voucher_keeps_its_own_method_and_the_value_date_defaults_to_today_only_on_a_new_paper(): void
    {
        $old = (object) ['instrument' => 'card', 'instrument_no' => 'POS-1', 'value_date' => null];
        $html = (string) $this->blade('<form><x-money.method :record="$r" /></form>', ['r' => $old]);
        $this->assertStringContainsString('value="card"', $html, '⛔ পুরনো কার্ডের কাগজে পদ্ধতিটা হারাল — সম্পাদনায় নীরবে বদলে যেত।');

        // ⓘ তারিখের ঘর ([[ui.date]]) মানটা `x-data`-র বীজে রাখে, `value`-তে নয় — তাই আজকের ISO তারিখটাই খোঁজা হয়
        $fresh = (string) $this->blade('<form><x-money.method /></form>');
        $this->assertStringContainsString(today()->format('Y-m-d'), $fresh, '⛔ #২: নতুন কাগজে মূল্য-তারিখ আজ নয়।');
        $this->assertStringNotContainsString(today()->format('Y-m-d'), (string) $this->blade(
            '<form><x-money.method :record="$r" /></form>', ['r' => (object) ['instrument' => 'transfer', 'value_date' => null]]),
            '⛔ পুরনো কাগজে ফাঁকা মূল্য-তারিখে আন্দাজে আজ বসল।');
    }

    public function test_item_2_the_server_drops_another_methods_values_and_refuses_to_post_without_a_reference(): void
    {
        $kept = MoneyMethod::onlyItsOwn([
            'instrument' => 'cash', 'instrument_no' => 'TRX-1', 'charge_amount' => '25', 'value_date' => '2026-10-21',
            'deposit_slip_no' => 'S-1', 'transfer_mode_id' => 3, 'from_bank' => 'X',
        ]);
        $this->assertSame([null, 0, null, null, null, null], [$kept['instrument_no'], $kept['charge_amount'], $kept['value_date'],
            $kept['deposit_slip_no'], $kept['transfer_mode_id'], $kept['from_bank']], '⛔ নগদে লুকানো চার্জ বা নম্বর থেকে গেল — খাতায় ৫২১০-এ বসত।');

        $bank = MoneyMethod::onlyItsOwn(['instrument' => 'transfer', 'instrument_no' => 'BF1', 'charge_amount' => '25', 'deposit_slip_no' => 'S-1']);
        $this->assertSame(['BF1', '25', null], [$bank['instrument_no'], $bank['charge_amount'], $bank['deposit_slip_no']]);

        foreach (['transfer' => 'instrument_no', 'mfs' => 'instrument_no', 'cheque' => 'instrument_no', 'deposit' => 'deposit_slip_no'] as $method => $field) {
            try {
                MoneyMethod::assertReadyToPost(['instrument' => $method, $field => '  ']);
                $this->fail("⛔ #২: {$method}-এ রেফারেন্স ছাড়া পোস্ট আটকায়নি।");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
                $this->assertStringContainsString('রেফারেন্স লাগবে', $e->errors()[$field][0]);
            }
        }

        MoneyMethod::assertReadyToPost(['instrument' => 'cash']);
        MoneyMethod::assertReadyToPost(['instrument' => 'transfer', 'instrument_no' => 'BF26102100981']);
        $this->addToAssertionCount(1);
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR);

        return new DOMXPath($dom);
    }
}

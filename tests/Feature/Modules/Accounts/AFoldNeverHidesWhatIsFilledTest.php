<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ টাকা নেওয়া আর দেওয়ার ফর্মে ভাঁজ — মালিক, ৮ নভেম্বর ২০২৬ ("egulo vaj kore rako zokon lagbe tokhon kule nibe";
 * নমুনা "টাকা নেওয়ার ভাঁজ করা ফর্ম" v5, "ok")।
 *
 * দাবি:
 *   ১. নতুন ফর্মে তিন ভাঁজ — কাটা আর কম-বেশি, খোলা বিল, সংযুক্তি — সব বন্ধ; প্রত্যেকের মাথায় ভিতরের ছোট লেখা
 *   ২. বিবরণ ভাঁজে নয় — নিজের কার্ডে, সবসময় খোলা (এটা ছাড়া জমা হয় না)
 *   ৩. ⛔ ভরা ঘর কখনো ভাঁজে লুকায় না: আগের VDS আর "কার মাধ্যমে" নিয়ে কাগজ খুললে দুই ভাঁজ খোলা
 *   ৪. "খাতে উঠবে (নিট)" সবুজ গোল বাক্সে; সারাংশের বাকি সারি আগের মতো
 *   ৫. মাথার লেখার ছাঁচ দুই ভাষায় একই চাবিতে
 */
final class AFoldNeverHidesWhatIsFilledTest extends TestCase
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

    public function test_a_new_form_folds_three_parts_and_keeps_the_narration_open(): void
    {
        $this->bank();

        foreach ([Voucher::RECEIPT, Voucher::PAYMENT] as $type) {
            $xpath = $this->dom((string) $this->get(route('accounts.voucher.create', ['type' => $type]))->assertOk()->getContent());

            foreach (['cut' => 'cutPeek', 'bills' => 'billsPeek', 'attach' => 'attachPeek'] as $fold => $peek) {
                $box = $xpath->query('//details[@data-fold="'.$fold.'"]')->item(0);
                $this->assertNotNull($box, "⛔ {$type}: \"{$fold}\" ভাঁজ নেই");
                $this->assertFalse($box->hasAttribute('open'), "⛔ {$type}: ফাঁকা নতুন ফর্মে \"{$fold}\" ভাঁজ খোলা — নমুনায় বন্ধ");
                $this->assertSame($peek, $xpath->query('./summary//*[@data-fold-peek]', $box)->item(0)?->getAttribute('x-text'),
                    "⛔ {$type}: \"{$fold}\" ভাঁজের মাথায় ভিতরের ছোট লেখা নেই");
            }

            // ⓘ ভিতরের ঘর সত্যিই ভাঁজের ভিতরে — মাথা কেবল সাজ নয়
            foreach (['cut' => 'vds_amount', 'attach' => 'carried_by'] as $fold => $field) {
                $this->assertSame(1, $xpath->query('//details[@data-fold="'.$fold.'"]//*[@name="'.$field.'"]')->length, "⛔ {$type}: {$field} \"{$fold}\" ভাঁজের ভিতরে নেই");
            }
            $this->assertSame(1, $xpath->query('//details[@data-fold="attach"]//input[@name="attachment"]')->length, "⛔ {$type}: সংযুক্তির ঘর ভাঁজের ভিতরে নেই");

            $narration = $xpath->query('//*[@name="narration"]')->item(0);
            $this->assertNotNull($narration, "⛔ {$type}: বিবরণের ঘর নেই");
            $this->assertNull($xpath->query('ancestor::details', $narration)->item(0), "⛔ {$type}: বিবরণ ভাঁজের ভিতরে — নমুনায় সবসময় খোলা");
            $this->assertNotNull($xpath->query('ancestor::section[@data-voucher-note]', $narration)->item(0), "⛔ {$type}: বিবরণ নিজের কার্ডে নেই");
        }
    }

    public function test_a_filled_part_opens_its_fold(): void
    {
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $carrier = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $voucher = app(VoucherService::class)->create(
            ['type' => Voucher::RECEIPT, 'trx_date' => now()->toDateString(), 'amount' => '1000', 'party_type' => 'customer', 'party_id' => $customer->id],
            app(VoucherService::class)->twoLineEntry(Voucher::RECEIPT, StandardChart::find(StandardChart::RECEIVABLE)->id, $this->bank()->id, '1000'),
        );
        Voucher::query()->whereKey($voucher->id)->update(['vds_amount' => '50', 'carried_by' => $carrier->id]);

        $xpath = $this->dom((string) $this->get(route('accounts.voucher.edit', $voucher))->assertOk()->getContent());

        $this->assertTrue($xpath->query('//details[@data-fold="cut"]')->item(0)?->hasAttribute('open') ?? false,
            '⛔ VDS বসানো কাগজে কাটার ভাঁজ বন্ধ — ভরা অঙ্ক চোখের আড়ালে');
        $this->assertTrue($xpath->query('//details[@data-fold="attach"]')->item(0)?->hasAttribute('open') ?? false,
            '⛔ "কার মাধ্যমে" বসানো কাগজে সংযুক্তির ভাঁজ বন্ধ — ভরা ঘর চোখের আড়ালে');
        $this->assertFalse($xpath->query('//details[@data-fold="bills"]')->item(0)?->hasAttribute('open') ?? true,
            '⛔ কোনো বিল বাছা নেই, তবু খোলা বিলের ভাঁজ খোলা');
    }

    public function test_the_net_line_sits_in_a_green_round_box(): void
    {
        $this->bank();

        foreach ([Voucher::RECEIPT, Voucher::PAYMENT] as $type) {
            $xpath = $this->dom((string) $this->get(route('accounts.voucher.create', ['type' => $type]))->assertOk()->getContent());
            $net = $xpath->query('//*[@data-sum-net]')->item(0);
            $this->assertNotNull($net, "⛔ {$type}: নিটের অঙ্ক নেই");
            $this->assertNotNull($xpath->query('ancestor::div[contains(concat(" ", @class, " "), " money-net ")]', $net)->item(0),
                "⛔ {$type}: \"খাতে উঠবে (নিট)\" সবুজ বাক্সে নেই");
        }

        $css = (string) file_get_contents(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression('/\.money-net \{[^}]*border: 2px solid var\(--color-badge-success-ink\);[^}]*border-radius: var\(--radius-card\);[^}]*color: var\(--color-badge-success-ink\);/s', $css,
            '⛔ নিটের বাক্স সবুজ গোল নয় (মালিক: "sobuj koro r ekta round box koro")');
    }

    public function test_the_peek_words_have_the_same_keys_in_both_languages(): void
    {
        $bn = trans('money.fold_peek', [], 'bn');
        $en = trans('money.fold_peek', [], 'en');
        $this->assertIsArray($bn);
        $this->assertIsArray($en);
        $this->assertSame(array_keys($bn), array_keys($en), '⛔ ভাঁজের মাথার লেখার চাবি দুই ভাষায় আলাদা');
        foreach (['cut' => [':vds', ':ait', ':diff'], 'bills_in' => [':n', ':due', ':applied'], 'file' => [':name'], 'carrier' => [':name']] as $key => $marks) {
            foreach ($marks as $mark) {
                $this->assertStringContainsString($mark, $bn[$key], "⛔ bn {$key}-এ {$mark} নেই");
                $this->assertStringContainsString($mark, $en[$key], "⛔ en {$key}-এ {$mark} নেই");
            }
        }
    }

    private function dom(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    private function bank(): Account
    {
        return Account::query()->where('money_kind', Account::BANK)->postable()->active()->orderBy('code')->first()
            ?? app(AccountService::class)->create([
                'code' => StandardChart::BANK.'-01',
                'name_en' => 'Test bank account',
                'parent_id' => Account::query()->where('code', StandardChart::BANK)->value('id'),
            ]);
    }
}

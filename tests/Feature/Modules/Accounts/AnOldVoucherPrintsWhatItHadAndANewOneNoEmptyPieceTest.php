<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Print\PaperSize;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Accounts\Support\VoucherDesigns;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * ⭐ নতুন ভাউচারের বাক্স ([[x-money.method]]) — মালিকের ২১ অক্টোবর ২০২৬-এর রায়: ভাউচারের ছবি দেখিয়ে *"এতগুলো দেওয়া কি int.
 * standard?"*, তারপর নমুনা দেখে *"ok ekoni kaj kore live e daw"*। পর্দা আর ওয়ালেট, প্রেরকের ব্যাংক-হিসাব ইত্যাদি চায় না;
 * কলাম থাকে (fe)।
 *
 * দাবি, প্রতিটা নকশায়, প্রতিটা মাপে, দুই অবস্থায়:
 *   ① পুরনো ভাউচার (ওয়ালেট, প্রেরকের ব্যাংক আর হিসাব নম্বর ভরা) — কাগজে আগের মতোই: মাধ্যমের সারিতে ওয়ালেট, আর যে নকশা
 *     প্রেরকের ব্যাংক ছাপে তাতে "ব্যাংক · হিসাব নম্বর";
 *   ② নতুন ভাউচার (ঘরগুলো ফাঁকা) — কোথাও ফাঁকা টুকরো নয়: দুই বিভাজকের মাঝে খালি (`· ·`) বা শেষে ঝুলন্ত বিভাজক নেই।
 * ⓘ ছাপা PDF, তাই নকশার HTML-টাই মাপা হয় — ছাঁচ যা পায় ঠিক তা দিয়ে নতুন করে আঁকা ([[PrintEngine::render()]])।
 */
final class AnOldVoucherPrintsWhatItHadAndANewOneNoEmptyPieceTest extends TestCase
{
    use RefreshDatabase;

    private Voucher $voucher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $dealer = Customer::query()->create(['code' => 'OLDV-1', 'name_en' => 'Old Voucher Traders', 'name_bn' => 'পুরনো ভাউচার ট্রেডার্স', 'is_active' => true]);
        $receivable = (int) Account::query()->where('is_group', false)->where('code', 'like', StandardChart::RECEIVABLE.'%')->orderBy('code')->value('id');
        $income = (int) Account::query()->where('is_group', false)->where('type', 'income')->orderBy('code')->value('id');

        $this->voucher = app(VoucherService::class)->create([
            'type' => Voucher::JOURNAL,
            'trx_date' => now()->toDateString(),
            'narration' => 'Old and new voucher fields',
        ], [
            ['account_id' => $receivable, 'debit' => '3000', 'credit' => '0', 'party_type' => 'customer', 'party_id' => $dealer->id],
            ['account_id' => $income, 'debit' => '0', 'credit' => '3000'],
        ]);
        $this->voucher->forceFill(['party_type' => 'customer', 'party_id' => $dealer->id])->save();
    }

    public function test_every_design_prints_an_old_vouchers_fields_and_no_empty_piece_for_a_new_one(): void
    {
        $old = ['instrument' => 'mfs', 'instrument_no' => 'TRXOLD77', 'wallet' => 'bkash', 'from_bank' => 'Sonali Bank', 'from_account_no' => '0012345674521'];
        $new = ['instrument' => 'transfer', 'instrument_no' => 'BFNEW88', 'wallet' => null, 'from_bank' => null, 'from_account_no' => null];
        $checked = 0;

        foreach (VoucherDesigns::SIZES as $size) {
            foreach (VoucherDesigns::codes($size) as $code) {
                $this->voucher->forceFill($old)->save();
                $then = $this->html($size, $code);
                $this->voucher->forceFill($new)->save();
                $now = $this->html($size, $code);

                // ① পুরনোর মাধ্যমের সারি ("পদ্ধতি · ওয়ালেট · নম্বর") যে নকশা ছাপে, তাতে ওয়ালেট থাকে। ⓘ সারিটা চেনা যায় নম্বরের
                //    আগের "·" দিয়ে — যে নকশা নম্বরটা কেবল "রেফারেন্স" ঘরে একা ছাপে (a4/sap_document), সে কখনো ওয়ালেট ছাপেনি,
                //    আগেও না (cb_v11-এ ধরা: দাবির অনুমান ভুল ছিল, কাগজ নয়)
                if (preg_match('/·\s*TRXOLD77/u', $then) === 1) {
                    $this->assertStringContainsString('bkash', $then, "⛔ {$size}/{$code}: পুরনো ভাউচারের ওয়ালেট কাগজ থেকে হারাল");
                }
                // ① প্রেরকের ব্যাংক যে নকশা ছাপে, তাতে "ব্যাংক · হিসাব নম্বর" অক্ষত
                if (str_contains($then, 'Sonali Bank')) {
                    $this->assertStringContainsString('Sonali Bank · 0012345674521', $then, "⛔ {$size}/{$code}: পুরনো ভাউচারের প্রেরকের হিসাব নম্বর কাগজ থেকে হারাল");
                }

                // ② নতুনে ফাঁকা টুকরো নয়
                $text = preg_replace('/\s+/u', ' ', strip_tags($now));
                $this->assertDoesNotMatchRegularExpression('/·\s*·/u', $text, "⛔ {$size}/{$code}: নতুন ভাউচারের কাগজে দুই বিভাজকের মাঝে ফাঁকা টুকরো");
                $this->assertStringNotContainsString('Sonali Bank', $now, "প্রস্তুতিটাই ভুল — {$size}/{$code}: নতুনেও পুরনো ব্যাংক");
                $checked++;
            }
        }

        $this->assertGreaterThan(20, $checked, 'প্রস্তুতিটাই ভুল — নকশাগুলো ঘোরা হয়নি');
    }

    /** ⓘ বাছা নকশায় ছাপার অনুরোধ, আর ছাঁচ যা পেল তা দিয়ে HTML — PDF নয় */
    private function html(string $size, string $code): string
    {
        $settings = app(SettingsService::class);
        $paper = ['a4' => PaperSize::A4, 'a5' => PaperSize::A5, 'thermal' => PaperSize::THERMAL_80];
        $settings->set('accounts.print.paper.voucher', $paper[$size]);
        $settings->set(VoucherDesigns::key($size), $code);
        $settings->flush();

        $seen = null;
        View::composer('print.voucher*', function ($view) use (&$seen): void {
            $seen ??= [$view->getName(), $view->getData()];
        });

        $this->get(route('accounts.voucher.print', $this->voucher->fresh()))->assertOk();
        $this->assertNotNull($seen, "প্রস্তুতিটাই ভুল — {$size}/{$code}: ছাঁচ ডাকাই হয়নি");

        return View::make($seen[0], $seen[1])->render();
    }
}

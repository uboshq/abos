<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Finance\Models\Deposit;
use App\Modules\Finance\Models\DepositKind;
use App\Modules\Finance\Services\DepositKindInstaller;
use App\Modules\Finance\Services\DepositService;
use App\Modules\Finance\Services\RiskBoard;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ সতর্কবার্তাগুলো ছড়িয়ে ছিল ছয় পর্দায় — অর্থের মানচিত্র §১, ২০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ঘটত ─────────────────────────────────────────────────────────
 * নগদের পূর্বাভাস এক পাতায়, ব্যাংক সুবিধা আরেক পাতায়, জমার মেয়াদ তৃতীয়
 * পাতায়, ঋণের কিস্তি চতুর্থ পাতায়, বসে থাকা মাল পঞ্চম পাতায়। ⓘ প্রতিটাই
 * ঠিক ছিল, কিন্তু কেউ রোজ পাঁচটা পাতা খোলে না — তাই খবরগুলো সময়মতো
 * কারও চোখে পড়ত না।
 *
 * ⭐ এই পাতা নতুন কিছু গোনে না, কেবল **এক জায়গায় আনে**। তাই পরীক্ষার
 * কাজ দুইটা: যা ঝুঁকি তা আসে, আর যা ঝুঁকি নয় তা আসে **না**।
 */
final class TheWarningSignsWereScatteredAcrossSixScreensTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(DepositKindInstaller::class)->install();
        $this->cash = app(CashTillService::class)->ensurePrimaryTill()->account;
    }

    /**
     * ⭐ প্রতিটা সারিতে যা যা থাকার কথা, তাই আছে — আর স্তর দুইটার একটা।
     */
    public function test_every_row_is_shaped_the_way_the_page_reads_it(): void
    {
        foreach (app(RiskBoard::class)->risks() as $risk) {
            $this->assertSame(
                ['key', 'level', 'value', 'hint', 'href'],
                array_keys($risk),
                'ঝুঁকির সারির ঘরগুলো বদলে গেছে — পাতাটা পড়তে পারবে না।',
            );

            $this->assertContains($risk['level'], ['bad', 'warn'], "অচেনা স্তর: {$risk['level']}।");
            $this->assertIsArray($risk['hint']);
        }
    }

    /**
     * ⭐ প্রতিটা ঝুঁকির কথা দুই ভাষাতেই আছে, আর ফাঁকা ঘরগুলোও মেলে।
     *
     * ⚠️ ভাষার ফাইল ছাড়া একটা ঝুঁকি যোগ করলে পাতায় কাঁচা চাবি ছাপা হত;
     * ⓘ আর বার্তায় এমন ঘর চাইলে যেটা কোড পাঠায় না, লোকে `:days` লেখাই
     * দেখত। দুইটাই এখানে মাপা হয়।
     */
    public function test_every_risk_has_words_in_both_languages(): void
    {
        $source = file_get_contents(app_path('Modules/Finance/Services/RiskBoard.php'));
        preg_match_all("/'key' => '(\w+)'/", (string) $source, $found);

        $this->assertSame(
            RiskBoard::KEYS,
            array_values(array_unique($found[1])),
            'সেবাটা যে ঝুঁকিগুলো তোলে আর KEYS তালিকা — দুইটা মেলেনি।',
        );

        // কোড কোন ঘরগুলো পাঠায়, চাবি ধরে
        $hints = [];

        foreach ($this->hintSources($source) as $key => $given) {
            $hints[$key] = $given;
        }

        foreach (['bn', 'en'] as $lang) {
            foreach (RiskBoard::KEYS as $key) {
                foreach ([$key, $key.'_hint'] as $needed) {
                    $words = __('finance::risk.'.$needed, [], $lang);

                    $this->assertNotSame('finance::risk.'.$needed, $words, "{$lang}: {$needed} ভাষার ফাইলে নেই।");
                }

                preg_match_all('/:(\w+)/', (string) __('finance::risk.'.$key.'_hint', [], $lang), $wanted);

                foreach ($wanted[1] as $placeholder) {
                    $this->assertContains(
                        $placeholder,
                        $hints[$key] ?? [],
                        "{$lang}: {$key}_hint বার্তা :{$placeholder} চায়, কিন্তু কোড ওই ঘরটা পাঠায় না।",
                    );
                }
            }
        }
    }

    /**
     * ⭐ সামনের মাসে মেয়াদ শেষ হওয়া জমা পাতায় আসে — টাকাসহ।
     */
    public function test_a_deposit_maturing_this_month_reaches_the_board(): void
    {
        $this->deposit('FDR-SOON', now()->addDays(10)->toDateString(), '250000');

        $risk = $this->risk('deposits_maturing');

        $this->assertNotNull($risk, 'দশ দিন পরে মেয়াদ শেষ হওয়া জমা পাতায় আসেনি।');
        $this->assertSame('1', $risk['value'], 'জমার সংখ্যা ভুল।');
        $this->assertSame(
            0,
            bccomp($risk['hint']['amount'], '250000', 4),
            "জমার টাকা {$risk['hint']['amount']}, ২,৫০,০০০ হওয়ার কথা — ঘরের নামটা ভুল হলে এটা শূন্য দেখায়।",
        );
    }

    /**
     * ⛔ বহু পরের মেয়াদ আজকের ঝুঁকি নয়।
     *
     * ⚠️ এটা না মাপলে দিনের সীমাটা ফেলে দিলেও পরীক্ষা সবুজ থাকত, আর
     * পাতাটা এক বছর পরের সব জমা দেখিয়ে নিজেকে অকেজো করে তুলত।
     */
    public function test_a_deposit_that_matures_next_year_stays_off_the_board(): void
    {
        $this->deposit('FDR-FAR', now()->addDays(200)->toDateString(), '250000');

        $this->assertNull($this->risk('deposits_maturing'), 'দুইশ দিন পরের জমাও ঝুঁকি হিসেবে এসেছে।');
    }

    /**
     * ⛔ বন্ধ হয়ে যাওয়া জমার মেয়াদ নিয়ে সতর্ক করার কিছু নেই।
     */
    public function test_a_closed_deposit_is_not_a_risk(): void
    {
        $deposit = $this->deposit('FDR-DONE', now()->addDays(5)->toDateString(), '100000');
        $deposit->forceFill(['status' => Deposit::CLOSED])->save();

        $this->assertNull($this->risk('deposits_maturing'), 'বন্ধ জমাও ঝুঁকির তালিকায় এসেছে।');
    }

    /**
     * ⭐ পাতাটা খোলে, আর কথা বলে — চাবি ছাপে না।
     */
    public function test_the_page_speaks_words_not_keys(): void
    {
        $this->deposit('FDR-SOON', now()->addDays(10)->toDateString(), '250000');

        $html = $this->get(route('finance.risk'))->assertOk()->getContent();

        $this->assertStringContainsString(__('finance::risk.title'), $html);
        $this->assertStringContainsString(__('finance::risk.deposits_maturing'), $html);
        $this->assertStringNotContainsString('finance::risk.', $html, 'অনুবাদ না পেয়ে চাবিটাই ছাপা হয়েছে।');

        // ⓘ ইঙ্গিতের ফাঁকা ঘরগুলো ভরেছে কি না — `:days` লেখা থেকে গেলে ভরেনি
        $this->assertStringNotContainsString(':days', $html, 'বার্তার ফাঁকা ঘর ভরেনি।');
        $this->assertStringNotContainsString(':amount', $html, 'বার্তার ফাঁকা ঘর ভরেনি।');
    }

    /**
     * ⭐ কিছু না থাকলে পাতাটা ফাঁকা নয় — সেটা কথায় বলে।
     */
    public function test_a_calm_day_says_so(): void
    {
        /*
         * ⓘ পর্দাটা সরাসরি আঁকা হয় একটা ফাঁকা তালিকা দিয়ে — [[RiskBoard]]
         * `final`, তাই ওটাকে নকল করা যায় না, আর ডেমোর খাতা ফাঁকা করাও
         * এই প্রশ্নের উত্তর নয়। ⚠️ মাপা হচ্ছে পাতার **ফাঁকা শাখাটা**।
         */
        $html = view('finance::risk.index', [
            'menu' => app(MenuBuilder::class)->forUser(auth()->user()),
            'risks' => [],
        ])->render();

        $this->assertStringContainsString(__('finance::risk.all_clear'), $html);
        $this->assertStringContainsString(__('finance::risk.all_clear_hint'), $html);
    }

    /**
     * ⛔ যাঁর পূর্বাভাস দেখার অনুমতি নেই, তাঁর জন্য দরজা বন্ধ।
     */
    public function test_a_salesman_cannot_open_it(): void
    {
        $this->actingAs(User::query()->where('email', 'sales@abos.test')->firstOrFail())
            ->get(route('finance.risk'))
            ->assertForbidden();
    }

    /**
     * সেবার লেখা থেকে: কোন ঝুঁকি কোন কোন ঘর পাঠায়।
     *
     * ⓘ সারিগুলো চালিয়ে দেখা যেত না — কিছু ঝুঁকি কেবল বিশেষ অবস্থায় ওঠে
     * (সুবিধার সীমা, মেয়াদ পেরোনো কিস্তি), আর তখন ওগুলো কখনো মাপা হত না।
     *
     * @return array<string, list<string>>
     */
    private function hintSources(string $source): array
    {
        preg_match_all(
            "/'key' => '(\w+)',.*?'hint' => \[(.*?)\],\n/s",
            $source,
            $blocks,
            PREG_SET_ORDER,
        );

        $out = [];

        foreach ($blocks as [, $key, $body]) {
            preg_match_all("/'(\w+)' =>/", $body, $fields);
            $out[$key] = $fields[1];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function risk(string $key): ?array
    {
        foreach (app(RiskBoard::class)->risks() as $risk) {
            if ($risk['key'] === $key) {
                return $risk;
            }
        }

        return null;
    }

    private function deposit(string $reference, string $matures, string $principal): Deposit
    {
        return app(DepositService::class)->open([
            'kind_id' => DepositKind::query()->where('code', 'FDR')->firstOrFail()->id,
            'institution' => 'সোনালী ব্যাংক',
            'reference_no' => $reference,
            'held_by' => Deposit::BUSINESS,
            'principal' => $principal,
            'return_word' => 'interest',
            // ⓘ ডেমোর অর্থবছর ১ জুলাই থেকে — তার আগের তারিখে জমা খোলা যায় না
            'opened_on' => now()->subMonths(2)->toDateString(),
            'matures_on' => $matures,
            'funded_from_account_id' => $this->cash->id,
        ]);
    }
}

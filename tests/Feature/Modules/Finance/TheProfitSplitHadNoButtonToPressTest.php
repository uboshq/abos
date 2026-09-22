<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Models\ProfitShare;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ভাগ করার যন্ত্র ছিল, চাপার বোতাম ছিল না।
 *
 * ── ⛔ কেন এই পরীক্ষাটা আলাদা ───────────────────────────────────────
 * [[TheProfitWasSharedAndNobodyCouldSayWhoWasOwedWhatTest]] সেবাটার
 * অঙ্ক মাপে — টাকা ঠিক খাতে বসে কি না, ভাগ মেলে কি না।
 *
 * ⚠️ কিন্তু সেবাটা নিখুঁত হয়েও **অপৌঁছনীয়** থাকতে পারে: রুট নেই,
 * মেনুতে সারি নেই, ফর্মের ঠিকানা ভুল। ⓘ তখন কিছুই লাল হয় না, কারণ
 * অঙ্কের দাবিগুলো সেবাটাকে সরাসরি ডাকে — পর্দা দিয়ে নয়।
 *
 * ⭐ এই রিপোর সবচেয়ে চেনা ভুলটাই এটা: *অংশগুলো আছে, জোড়াটা নেই, আর
 * কিছুই ভাঙে না* — যতক্ষণ না একজন মানুষ সারিতায় চাপেন আর ৪০৪ পান।
 */
final class TheProfitSplitHadNoButtonToPressTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->companyId = (int) $company->id;
        $this->owner->switchCompany($this->companyId);
        $this->be($this->owner);

        // ⚠️ ভাগ পাওয়ার মতো কেউ না থাকলে নিচের দাবিগুলো কিছুই মাপত না
        $this->contribute('SCR-A', 'Screen partner A', '700000');
        $this->contribute('SCR-B', 'Screen partner B', '300000');
    }

    /**
     * ⭐ পাতাটা খোলে, আর মেনুতে তার সারি আছে।
     */
    public function test_the_page_opens_and_the_menu_leads_to_it(): void
    {
        $page = $this->actingAs($this->owner)
            ->get(route('finance.profit.index'));

        $page->assertOk();

        /*
         * ⓘ তারিখের ঘরে `max` সত্যি পৌঁছায় কি না। ⚠️ `x-ui.field`
         * একটা অধোষিত গুণ নিরবে গিলে ফেলতে পারত, আর সৌজন্যটা
         * থাকত কেবল ব্লেডের লেখায়।
         */
        $page->assertSee('max="'.now()->format('Y-m-d').'"', false);

        /*
         * ⛔ সারিতা মেনুতে না থাকলে পাতাটা কেবল ঠিকানা জানা লোকের।
         * ⓘ ১৮ সেপ্টেম্বরে `expiring` রিপোর্টে হুবহু এটাই হয়েছিল, উল্টো
         * দিক থেকে: মেনুতে সারি ছিল, রুট ছিল না।
         */
        $module = require app_path('Modules/Finance/module.php');

        $routes = collect($module['menu'] ?? [])
            ->flatten(1)
            ->pluck('route')
            ->filter()
            ->all();

        $this->assertContains('finance.profit.index', $routes, implode("\n", [
            'মেনুতে লাভ বণ্টনের সারিটা নেই।',
            '',
            'ⓘ পাতাটা খোলে, কিন্তু কেউ সেখানে পৌঁছাবে কীভাবে?',
        ]));
    }

    /**
     * ⭐ "ভাগটা দেখুন" কিছুই খাতায় বসায় না।
     *
     * ── ⚠️ কেন এই দাবিটা জরুরি ──────────────────────────────────────
     * দুই ধাপের গোটা কারণই এটা: আগে দেখা, তারপর ঘোষণা। ⛔ দেখার
     * ধাপেই লিখে ফেললে ভুল অঙ্ক বসিয়ে একবার চাপলেই খাতায় বসে যেত,
     * আর ফেরানোর একমাত্র পথ উল্টো দাখিলা।
     */
    public function test_previewing_writes_nothing(): void
    {
        $response = $this->actingAs($this->owner)
            ->post(route('finance.profit.preview'), ['profit' => '100000']);

        $response->assertOk();

        $this->assertSame(0, ProfitShare::query()->count(), implode("\n", [
            'কেবল দেখতে চেয়েছিলাম, অথচ ভাগ লেখা হয়ে গেছে।',
            '',
            '⛔ তাহলে দুই ধাপের কোনো মানেই নেই।',
        ]));

        // ⭐ দেখাটা সত্যিই কিছু দেখিয়েছে কি না — নাহলে দাবিটা ফাঁকা
        $response->assertSee('70,000.00');
        $response->assertSee('30,000.00');
    }

    /**
     * ⭐ বোতামটা সত্যিই ঘোষণা করে।
     */
    public function test_the_button_actually_declares(): void
    {
        $this->actingAs($this->owner)
            ->post(route('finance.profit.declare'), [
                'trx_date' => now()->toDateString(),
                'profit' => '100000',
            ])
            ->assertRedirect(route('finance.profit.index'))
            ->assertSessionHas('saved');

        $rows = ProfitShare::query()->posted()->get();

        $this->assertCount(2, $rows, 'দুইজনের ভাগ খাতায় বসার কথা।');

        $sum = $rows->reduce(fn (string $s, $r) => bcadd($s, (string) $r->amount, 4), '0');

        $this->assertSame(0, bccomp($sum, '100000', 4),
            'ভাগের যোগফল ১,০০,০০০ নয় — এসেছে '.$sum.'।');
    }

    /**
     * ⛔ যাঁর পোস্ট করার চাবি নেই, তিনি ঘোষণা করতে পারেন না।
     *
     * ── ⚠️ কেন `capital.post`, `capital.create` নয় ──────────────────
     * এখানে খসড়া বলে কিছু নেই — ঘোষণা মানেই খাতায় বসা। ⓘ যিনি
     * মূলধনের খসড়া বসাতে পারেন তিনি লাভ বণ্টনের সিদ্ধান্ত নিতে পারেন
     * না; ওটা মালিকের কাজ।
     */
    public function test_declaring_needs_the_posting_key(): void
    {
        $clerk = User::query()->where('email', '!=', 'owner@abos.test')->firstOrFail();
        $clerk->switchCompany($this->companyId);

        $response = $this->actingAs($clerk)
            ->post(route('finance.profit.declare'), [
                'trx_date' => now()->toDateString(),
                'profit' => '50000',
            ]);

        $this->assertContains($response->status(), [403, 302], implode("\n", [
            'পোস্টের চাবি ছাড়াই ঘোষণা করা গেল — সাড়া '.$response->status().'।',
        ]));

        $this->assertSame(0, ProfitShare::query()->count(),
            'অনুমতি ছাড়া ঘোষণা খাতায় বসে গেছে।');
    }

    /**
     * ⛔ আগামীকালের তারিখে ঘোষণা করা যায় না।
     *
     * ── ⚠️ কেন পর্দা দিয়ে, ফাইল পড়ে নয় ─────────────────────
     * [[NoDocumentIsDatedInTheFutureTest]] সোর্সের লেখা পড়ে দেখে
     * `before_or_equal` শব্দটা আছে কি না। ⓘ কিন্তু লেখা মিথ্যা
     * বলতে পারে — নিয়মটা ভুল বাক্সে বসলে, বা ফর্ম অন্য
     * পথে ঘোষণা করলেও সেই পাহারা সবুজই থাকত।
     *
     * ⭐ তাই এই দাবিটা মানুষের পথে দাঁড়ায়: ফর্মে আগামীকাল বসিয়ে
     * বোতাম চাপা, আর খাতা ফাঁকা থাকে কি না দেখা।
     *
     * ⓘ ভবিষ্যতের একটা সারি বসলে **আজকের জেরই** ভুল দেখায় —
     * দেনাটা খাতায় আছে, অথচ লাভটা এখনো ঘোষিত হওয়ার কথা নয়।
     */
    public function test_tomorrow_cannot_be_declared(): void
    {
        $response = $this->actingAs($this->owner)
            ->from(route('finance.profit.index'))
            ->post(route('finance.profit.declare'), [
                'trx_date' => now()->addDay()->toDateString(),
                'profit' => '100000',
            ]);

        $response->assertSessionHasErrors('trx_date');

        $this->assertSame(0, ProfitShare::query()->count(), implode("\n", [
            'আগামীকালের তারিখে ঘোষণা খাতায় বসে গেছে।',
            '',
            '⛔ তাহলে আজকের জের একটা না-ঘটা ঘটনাও গোনে।',
        ]));

        /*
         * ⭐ নিয়মটা সীমায় ঠিক বসেছে তো? ⓘ `before_or_equal:tomorrow`
         * লেখা থাকলে উপরের দাবিটাও সবুজ থাকত না, কিন্তু
         * `:yesterday` লেখা থাকলে থাকত — আর তখন আজকের
         * ঘোষণাটাই আটকাত, যা মালিকের রোজের কাজ।
         */
        $this->actingAs($this->owner)
            ->post(route('finance.profit.declare'), [
                'trx_date' => now()->toDateString(),
                'profit' => '100000',
            ])
            ->assertSessionHasNoErrors();
    }

    /**
     * ⭐ বছর-শেষের বোতামটাও সত্যি কাজ করে।
     *
     * ── ⚠️ এই ফাইলে কেন, সেবার ফাইলে নয় ────────────────
     * [[TheProfitNobodyTookHadNowhereToGoTest]] অঙ্কটা মাপে — দায়
     * কমে কি না, মালিকানা বাড়ে কি না। ⓘ কিন্তু সে সেবাটাকে
     * সরাসরি ডাকে — রুট মুছলে, চাবি বদলালে বা ফর্মের
     * ঠিকানা ভুল হলে সে সবুজই থাকত।
     */
    public function test_the_year_end_button_moves_the_money(): void
    {
        $this->actingAs($this->owner)
            ->post(route('finance.profit.declare'), [
                'trx_date' => now()->toDateString(),
                'profit' => '100000',
            ])
            ->assertSessionHas('saved');

        /*
         * ⭐ পাতায় বাক্সটা দেখা যায় তো? ⓘ না দেখা গেলে নিচের
         * POST-টা পাস করলেও মানুষ কখনো সেখানে পৌঁছাতেন না।
         */
        $this->actingAs($this->owner)
            ->get(route('finance.profit.index'))
            ->assertOk()
            ->assertSee(__('finance::action.capitalise_now'));

        $this->actingAs($this->owner)
            ->post(route('finance.profit.capitalise'), [
                'trx_date' => now()->toDateString(),
                'entry_type' => CapitalEntry::CONTRIBUTION,
            ])
            ->assertRedirect(route('finance.profit.index'))
            ->assertSessionHas('saved');

        $rows = CapitalEntry::query()->where('in_kind', CapitalEntry::PROFIT)->get();

        $this->assertCount(2, $rows, implode("
", [
            'বোতামটা চাপা হলো, অথচ মূলধনের সারি বসল না।',
        ]));

        $sum = $rows->reduce(fn (string $s, $r) => bcadd($s, (string) $r->amount, 4), '0');

        $this->assertSame(0, bccomp($sum, '100000', 4),
            'মূলধনে গেল '.$sum.', যাওযার কথা ১,০০,০০০।');

        /*
         * ⛔ বাক্সটা এখন আর থাকার কথা নয় — কারও কিছু বাকি
         * নেই। ⓘ এই অর্ধেকটা না মাপলে `@if` শর্তটা তুলে দিলেও
         * দাবিটা সবুজ থাকত, আর মালিক একটা খালি বোতাম দেখতেন।
         */
        $this->actingAs($this->owner)
            ->get(route('finance.profit.index'))
            ->assertOk()
            ->assertDontSee(__('finance::action.capitalise_now'));

        /*
         * ⭐ মূলধনের পাতায় টাকাটা কোথা থেকে এল সেটা লেখা আছে।
         *
         * ⚠️ ঘরটা না থাকলে সারিটা হুবহু একটা **নগদ অনুদানের**
         * মতো দেখাত — দুইটাই "মূলধন", দুইটারই একই অঙ্ক।
         * ⓘ তখন *"এই টাকাটা কোথা থেকে এল"* প্রশ্নের উত্তর
         * কেবল খতিয়ানে থাকত, পর্দায় না।
         */
        $this->actingAs($this->owner)
            ->get(route('finance.capital.index'))
            ->assertOk()
            ->assertSee(__('finance::field.in_kind_profit'));
    }

    private function contribute(string $code, string $name, string $amount): void
    {
        $person = Person::query()->create([
            'code' => $code,
            'name_en' => $name,
            'name_bn' => $name,
            'is_active' => true,
        ]);

        CapitalEntry::query()->create([
            'branch_id' => Branch::query()->firstOrFail()->id,
            'document_no' => 'CAP-'.$code,
            'person_id' => $person->id,
            'contributor_type' => CapitalEntry::CONTRIBUTION,
            'entry_type' => CapitalEntry::CONTRIBUTION,
            'in_kind' => CapitalEntry::CASH,
            'trx_date' => now()->subMonth()->toDateString(),
            'amount' => $amount,
            'status' => CapitalEntry::POSTED,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\PartyRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ডিলার টাকাটা কোম্পানিকে দিলেন, আমাকে নয়।
 *
 * ── কেন এটা লাগে ────────────────────────────────────────────────────
 * সুপার ডিপোর রোজকার ঘটনা। ABC এন্টারপ্রাইজ আলিন ফুডের মাল বেচে, আর
 * ডিলার টাকাটা পাঠান **সরাসরি আলিন ফুডের ব্যাংকে**। কোম্পানি সেটা ABC-র
 * লেজারে জমা দেয়, আর ABC মাল ছাড়ে।
 *
 * টাকা ABC-র হাত ছোঁয় না, তবু **দুইটা খাতা নড়ে**: সরবরাহকারীর কাছে দেনা
 * কমে, আর ডিলারের কাছে প্রাপ্য কমে। এটাকে তিন কোণা সমন্বয় বলে।
 *
 * ── আগে কেন লেখা যেত না ─────────────────────────────────────────────
 * ভাউচারের মাথায় একটামাত্র পক্ষের ঘর ছিল, তাই এক ভাউচারে দুই পক্ষ
 * বসানো যেত না। ফলে খাতা বলত ABC কোম্পানিকে পুরো টাকা দেনা **আর**
 * ডিলারও ABC-কে পুরো টাকা দেনা — দুইটাই মিথ্যা, আর প্রতি মাসে ফাঁকটা
 * বাড়ত।
 */
class TheDealerPaidTheCompanyNotMeTest extends TestCase
{
    use RefreshDatabase;

    private Customer $dealer;

    private Supplier $principal;

    private Account $receivable;

    private Account $payable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        app(StandardChart::class)->install();

        $this->dealer = Customer::query()->firstOrFail();
        $this->principal = Supplier::query()->firstOrFail();
        $this->receivable = StandardChart::find(StandardChart::RECEIVABLE);
        $this->payable = StandardChart::find(StandardChart::PAYABLE);
    }

    /**
     * এক ভাউচার, দুই পক্ষ — ডেবিট সরবরাহকারী, ক্রেডিট ডিলার।
     *
     * @param  array<string, mixed>  $extra
     */
    private function settle(string $amount = '100000', array $extra = []): Voucher
    {
        return app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(),
                'narration' => 'ডিলার সরাসরি কোম্পানিকে দিয়েছেন'],
            [
                [
                    'account_id' => $this->payable->id,
                    'party_type' => 'supplier',
                    'party_id' => $this->principal->id,
                    'debit' => $amount,
                    ...$extra,
                ],
                [
                    'account_id' => $this->receivable->id,
                    'party_type' => 'customer',
                    'party_id' => $this->dealer->id,
                    'credit' => $amount,
                ],
            ],
        );
    }

    /** @return array<string, string> */
    private function partyBalances(): array
    {
        return LedgerEntry::query()
            ->whereNotNull('party_type')
            ->get()
            ->groupBy(fn (LedgerEntry $e) => $e->party_type.':'.$e->party_id)
            ->map(fn ($rows) => $rows->reduce(
                fn (string $carry, LedgerEntry $e) => bcsub(bcadd($carry, (string) $e->debit, 4), (string) $e->credit, 4),
                '0',
            ))
            ->all();
    }

    /**
     * দুইটা পক্ষই খতিয়ানে নিজের নামে বসে।
     *
     * এটাই পুরো কাজটা: একটা কাগজ, দুইজনের নাম।
     */
    public function test_one_voucher_carries_two_different_parties(): void
    {
        $voucher = $this->settle();
        app(VoucherService::class)->post($voucher);

        $balances = $this->partyBalances();

        $this->assertSame(0, bccomp($balances['supplier:'.$this->principal->id] ?? '0', '100000', 4),
            'সরবরাহকারীর কাছে দেনা কমেনি — ডেবিটটা তাঁর নামে বসেনি।');

        $this->assertSame(0, bccomp($balances['customer:'.$this->dealer->id] ?? '0', '-100000', 4),
            'ডিলারের প্রাপ্য কমেনি — ক্রেডিটটা তাঁর নামে বসেনি।');
    }

    /**
     * আর বকেয়ার রিপোর্টও সেটা দেখে।
     *
     * ── কেন এই পরীক্ষাটা আলাদা করে ──────────────────────────────────
     * খতিয়ানে সারিটা বসেছে মানেই ব্যবহারকারী সেটা দেখতে পান না।
     * বকেয়ার বয়স (ageing) `party_type` **আর** `party_id` মিলিয়ে খোঁজে,
     * তাই ভুল ধরনের একটা অক্ষরও ওই সারিটাকে অদৃশ্য করে দিত — অথচ
     * ভাউচারটা দেখতে ঠিকই থাকত।
     */
    public function test_the_dealers_ageing_falls(): void
    {
        $before = $this->ageingOf($this->dealer);

        app(VoucherService::class)->post($this->settle('40000'));

        $this->assertSame(0, bccomp(bcsub($before, $this->ageingOf($this->dealer), 4), '40000', 4),
            'বকেয়ার রিপোর্টে ডিলারের অঙ্ক কমেনি।');
    }

    private function ageingOf(Customer $customer): string
    {
        return (string) (LedgerEntry::query()
            ->where('party_type', 'customer')
            ->where('party_id', $customer->id)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as due')
            ->value('due') ?? '0');
    }

    // ── পর্দা ও যাচাই ───────────────────────────────────────────────

    /** জাবেদার ফর্মে পক্ষ বাছার ঘরটা সত্যিই আছে। */
    public function test_the_form_offers_a_party_on_every_line(): void
    {
        $this->get(route('accounts.voucher.create', ['type' => 'journal']))
            ->assertOk()
            ->assertSee(__('accounts::field.party'))
            ->assertSee('name="lines[0][party]"', false)
            ->assertSee($this->dealer->drillLabel());
    }

    /**
     * পর্দার একটা ঘর ধরন ও নামে ভাগ হয়ে খতিয়ানে পৌঁছায়।
     *
     * ভাগটা না হলে ভাউচারটা সেভ হত, কিন্তু পক্ষ ছাড়া — আর কেউ টের
     * পেত না, কারণ পর্দায় নামটা বাছাই করা দেখাত।
     */
    public function test_the_picked_party_reaches_the_ledger(): void
    {
        $this->post(route('accounts.voucher.store', ['type' => 'journal']), [
            'type' => Voucher::JOURNAL,
            'trx_date' => now()->toDateString(),
            'lines' => [
                ['account_id' => $this->payable->id, 'debit' => '5000',
                    'party' => 'supplier:'.$this->principal->id],
                ['account_id' => $this->receivable->id, 'credit' => '5000',
                    'party' => 'customer:'.$this->dealer->id],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('ledger_entries', [
            'party_type' => 'supplier',
            'party_id' => $this->principal->id,
            'debit' => '5000.0000',
        ]);

        $this->assertDatabaseHas('ledger_entries', [
            'party_type' => 'customer',
            'party_id' => $this->dealer->id,
            'credit' => '5000.0000',
        ]);
    }

    /**
     * অচেনা ধরনের পক্ষ খতিয়ানে বসে না।
     *
     * ── কেন এটাই সবচেয়ে জরুরি পাহারা ────────────────────────────────
     * কন্ট্রোলার জাবেদার সারিগুলো কাঁচা ইনপুট থেকে নেয়, তাই ঘরটা
     * পর্দায় না থাকলেও যে কেউ অনুরোধে যা খুশি পাঠাতে পারত। খতিয়ানে
     * অচেনা একটা `party_type` বসলে টাকাটা কারও নামেই দেখা যেত না —
     * ভাউচার ঠিক, রিপোর্ট নীরবে ভুল।
     */
    public function test_an_unknown_party_kind_is_refused(): void
    {
        $this->post(route('accounts.voucher.store', ['type' => 'journal']), [
            'type' => Voucher::JOURNAL,
            'trx_date' => now()->toDateString(),
            'lines' => [
                ['account_id' => $this->payable->id, 'debit' => '5000',
                    'party_type' => 'whatever', 'party_id' => 1],
                ['account_id' => $this->receivable->id, 'credit' => '5000'],
            ],
        ])->assertSessionHasErrors('lines.0.party_type');

        $this->assertDatabaseMissing('ledger_entries', ['party_type' => 'whatever']);
    }

    /** যে পক্ষটা নেই, তার নামেও কিছু বসে না। */
    public function test_a_party_that_does_not_exist_is_refused(): void
    {
        $this->post(route('accounts.voucher.store', ['type' => 'journal']), [
            'type' => Voucher::JOURNAL,
            'trx_date' => now()->toDateString(),
            'lines' => [
                ['account_id' => $this->payable->id, 'debit' => '5000',
                    'party' => 'supplier:99999'],
                ['account_id' => $this->receivable->id, 'credit' => '5000'],
            ],
        ])->assertSessionHasErrors('lines.0.party_id');
    }

    /**
     * অর্ধেক লেখা পক্ষও আটকায়।
     *
     * ধরন আছে অথচ নাম নেই — এমন সারি খতিয়ানে বসলে বকেয়ার রিপোর্ট
     * ওটাকে কোনো ডিলারের নিচে আনতে পারত না, আর টাকাটা কার সেটা আর
     * জানা যেত না।
     */
    public function test_half_a_party_is_refused(): void
    {
        $this->post(route('accounts.voucher.store', ['type' => 'journal']), [
            'type' => Voucher::JOURNAL,
            'trx_date' => now()->toDateString(),
            'lines' => [
                ['account_id' => $this->payable->id, 'debit' => '5000', 'party_type' => 'supplier'],
                ['account_id' => $this->receivable->id, 'credit' => '5000'],
            ],
        ])->assertSessionHasErrors('lines.0.party_id');
    }

    /** পক্ষ ছাড়া সাধারণ জাবেদা আগের মতোই চলে — পাহারাটা রোজকার কাজ থামায় না। */
    public function test_a_journal_without_any_party_still_works(): void
    {
        $this->post(route('accounts.voucher.store', ['type' => 'journal']), [
            'type' => Voucher::JOURNAL,
            'trx_date' => now()->toDateString(),
            'lines' => [
                ['account_id' => $this->payable->id, 'debit' => '700'],
                ['account_id' => $this->receivable->id, 'credit' => '700'],
            ],
        ])->assertSessionHasNoErrors();
    }

    // ── ঘোষণাটা মডিউলের, কোরের নয় ───────────────────────────────────

    /**
     * পক্ষের তালিকা আসে মডিউলের ঘোষণা থেকে।
     *
     * কোরে হাতে লেখা থাকলে নতুন কোনো পক্ষ (যেমন কর্মী) যোগ করতে গেলে
     * কোরের ফাইল খুলতে হত — সেকশন ১৯.৭ ঠিক এটাই নিষেধ করে।
     */
    public function test_the_kinds_come_from_the_modules(): void
    {
        $registry = app(PartyRegistry::class);

        $this->assertEqualsCanonicalizing(['customer', 'supplier'], $registry->types());
        $this->assertSame(__('customer::menu.party'), $registry->labelFor('customer'));
    }

    /**
     * কোরের কোনো ফাইলে পক্ষের ধরনগুলো হাতে লেখা নেই।
     *
     * ── কেন মন্তব্য বাদ দিয়ে দেখা হয় ────────────────────────────────
     * প্রথমে পুরো ফাইলের লেখা ধরে খোঁজা হয়েছিল, আর তাতে
     * `ContributesFacts` ধরা পড়ল — যেখানে 'customer' কথাটা আছে
     * **একটা docblock-এর উদাহরণে**, কোডে নয়। ওটা সীমা ভাঙা নয়;
     * উল্টো ওটা পড়েই মানুষ বোঝেন ঘরটায় কী বসে।
     *
     * সীমাটা কোডের, লেখার নয় — তাই টোকেন ধরে দেখা হয়।
     */
    public function test_core_never_names_a_party_kind(): void
    {
        $offenders = [];

        foreach ((new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Core'))
        )) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }

                if (in_array(trim($token[1], "'\""), ['customer', 'supplier'], true)) {
                    $offenders[] = $file->getFilename().':'.$token[2];
                }
            }
        }

        $this->assertSame([], $offenders,
            'কোরের কোডে পক্ষের ধরন হাতে লেখা: '.implode(', ', $offenders));
    }
}

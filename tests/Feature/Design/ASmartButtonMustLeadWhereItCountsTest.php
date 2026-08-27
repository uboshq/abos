<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Panels\FactRegistry;
use App\Core\Support\CompanyContext;
use App\Core\Support\Ui;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ঘরে যে সংখ্যাটা লেখা, ক্লিক করলে ঠিক ততগুলোই পাওয়া যায়।
 *
 * ── কেন এটাই এই ফিচারের আসল ঝুঁকি ────────────────────────────────────
 * ওডুর রেকর্ড-পাতায় "৭টা চালান" লেখা একটা ঘর থাকে, আর ক্লিক করলে ওই
 * সাতটার তালিকা খোলে। সংখ্যাটা আসে এক কোয়েরি থেকে, তালিকাটা আরেক
 * কোয়েরি থেকে — **দুইটা আলাদা জায়গায় লেখা**।
 *
 * দুইটা যদি একই শর্ত না মানে, ফল হয় সবচেয়ে খারাপ ধরনের ভুল: পর্দা
 * আত্মবিশ্বাসের সাথে সাত বলে, তালিকা নয়টা দেখায়, আর কোনটা সত্যি সেটা
 * ব্যবহারকারীর জানার উপায় থাকে না। বইয়ের কাজে ওরকম একটা সংখ্যা
 * বিশ্বাস হারানোর জন্য যথেষ্ট।
 *
 * ── কেন লিংকটা খোঁজার ঘর দিয়ে বানানো হয়নি ────────────────────────────
 * `?q=<গ্রাহকের নাম>` দিয়েও তালিকাটা আনা যেত, আর বেশিরভাগ সময় কাজও
 * করত। কিন্তু দুইজন গ্রাহকের নামে একই শব্দ থাকলেই সংখ্যা আর তালিকা
 * আলাদা কথা বলত। তাই ছাঁকনিটা আইডি ধরে, আর সেটা এই পরীক্ষার বিষয়।
 *
 * পাশের পাহারা: [[AClonePromisedAShapeAndDrewAnotherTest]]।
 */
class ASmartButtonMustLeadWhereItCountsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $busy;

    private Customer $quiet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs($this->user);

        /*
         * চালানগুলো এখানেই বানানো হয়, ডেমো থেকে নেওয়া হয় না।
         *
         * ── কেন ─────────────────────────────────────────────────────
         * প্রথম লেখায় ডেমোর সারিগুলোর উপর ভরসা করা হয়েছিল, আর তিনটা
         * দাবিই লাল হলো — কারণ **ডেমোতে একটাও চালান নেই**। ভালো যে
         * ওরা ফাঁকা অবস্থায় সবুজ হতে রাজি হয়নি; একটা পাহারা যদি
         * ডেটা না থাকলেও পাশ করে, সে কোনোদিন কিছু ধরবে না।
         *
         * দুইজন গ্রাহক ইচ্ছাকৃত: একজনের তিনটা চালান, একজনের একটা।
         * একজনকে নিয়ে পরীক্ষা করলে ছাঁকনির নাম ভুল থাকলেও সব সারি
         * ফেরত আসত আর সংখ্যাটা মিলে যেত — অর্থাৎ ভুলটাই ধরা পড়ত না।
         */
        $customers = Customer::query()->orderBy('id')->take(2)->get();

        $this->busy = $customers->first();
        $this->quiet = $customers->last();

        $this->assertTrue(
            $this->busy->isNot($this->quiet),
            'ডেমোতে অন্তত দুইজন গ্রাহক থাকতে হবে, নাহলে ছাঁকনিটা যাচাই করা যায় না।',
        );

        foreach ([[$this->busy, 3], [$this->quiet, 1]] as [$customer, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $this->invoiceFor($customer);
            }
        }
    }

    /** এক গ্রাহকের নামে একটা নিশ্চিত চালান। */
    private function invoiceFor(Customer $customer): void
    {
        $service = app(SalesInvoiceService::class);

        $service->confirm(
            $service->create(
                [
                    'customer_id' => $customer->id,
                    'warehouse_id' => Warehouse::query()->orderBy('id')->firstOrFail()->id,
                    'trx_date' => Carbon::today()->toDateString(),
                ],
                [[
                    'product_id' => Product::query()->orderBy('id')->firstOrFail()->id,
                    'qty' => '1',
                    'rate' => '100.00',
                ]],
            )
        );
    }

    /**
     * প্রতিটা গোনা-ঘরের সংখ্যা আর তার তালিকার সারি এক।
     *
     * ── কেন সব গ্রাহকের উপর চালানো হয় ───────────────────────────────
     * একজনকে নিয়ে পরীক্ষা করলে সেই একজনের চালান শূন্য হলে পরীক্ষাটা
     * কিছুই প্রমাণ করত না — শূন্য বনাম শূন্য সবসময় মেলে। তাই যাঁদের
     * অন্তত একটা চালান আছে তাঁদের ধরা হয়, আর অন্তত একজন যে পাওয়া
     * গেছে সেটাও দাবি করা হয়।
     */
    public function test_the_number_on_the_tile_matches_the_list_it_opens(): void
    {
        /** @var FactRegistry $registry */
        $registry = app(FactRegistry::class);

        $checked = 0;
        $wrong = [];

        foreach (Customer::query()->get() as $customer) {
            foreach ($registry->forRecord('customer', $customer->id) as $fact) {
                // কেবল গোনা-ঘরগুলো — যাদের ঠিকানা আছে আর মানটা সংখ্যা
                if ($fact->url === null || ! ctype_digit((string) $fact->value)) {
                    continue;
                }

                $said = (int) $fact->value;

                if ($said === 0) {
                    continue;
                }

                $rows = $this->rowsOn($fact->url);
                $checked++;

                if ($rows !== $said) {
                    $wrong[] = sprintf(
                        '%s — ঘরে %d, তালিকায় %d (%s)',
                        $customer->name(),
                        $said,
                        $rows,
                        $fact->url,
                    );
                }
            }
        }

        $this->assertGreaterThan(0, $checked, implode("\n", [
            'একটাও গোনা-ঘর পরীক্ষা করা যায়নি — হয় কোনো গ্রাহকের চালান নেই,',
            'নয় ঘরগুলো আর ঠিকানা দিচ্ছে না। দুইটার যেকোনোটাই মানে পাহারাটা অচল।',
        ]));

        $this->assertSame([], $wrong, implode("\n", [
            'ঘরের সংখ্যা আর তালিকার সারি মিলছে না:',
            ...$wrong,
            '',
            'সংখ্যাটা আর তালিকাটা একই শর্ত মানছে কি না দেখুন — বাতিল কাগজ,',
            'দাখিল হয়নি এমন কাগজ, বা কোম্পানির স্কোপ।',
        ]));
    }

    /**
     * ঠিকানাটা সত্যিই খোলে, আর ছাঁকনিটা সত্যিই কাজ করে।
     *
     * ── কেন "খোলে" যথেষ্ট নয় ─────────────────────────────────────────
     * ছাঁকনির নামটা ভুল হলে (`customer` বদলে `customer_id`) পাতাটা
     * দিব্যি ২০০ ফেরত দিত — কেবল **সব** চালান দেখাত। উপরের পরীক্ষাটা
     * ওটা ধরে, কিন্তু কেবল তখনই যদি ডেমোতে একাধিক গ্রাহকের চালান
     * থাকে। এখানে সেটা সরাসরি দেখা হয়: ছাঁকনি দিয়ে আর ছাঁকনি ছাড়া
     * সংখ্যা দুইটা আলাদা হতেই হবে।
     */
    public function test_the_filter_actually_narrows_the_list(): void
    {
        $all = $this->rowsOn(route('sales.invoice.index'));

        $narrowed = null;

        foreach (Customer::query()->get() as $customer) {
            $rows = $this->rowsOn(route('sales.invoice.index', ['customer' => $customer->id]));

            if ($rows > 0 && $rows < $all) {
                $narrowed = $rows;
                break;
            }
        }

        $this->assertNotNull($narrowed, implode("\n", [
            'কোনো গ্রাহকের ছাঁকনিই তালিকাটা ছোট করল না।',
            "মোট চালান: {$all}।",
            '',
            'হয় ছাঁকনির নামটা ভুল (তখন সব সারিই ফেরত আসে), নয় ডেমোতে',
            'সব চালান একজন গ্রাহকের — দ্বিতীয়টা হলে ডেমোটাই বদলাতে হবে,',
            'নাহলে এই পাহারা কোনোদিন কিছু ধরবে না।',
        ]));
    }

    /**
     * ওডুতে ঘরগুলো রেকর্ডের মাথায়, বাকি রূপে তথ্যের সারিতে।
     *
     * ── কেন জায়গাটাও মাপা হয় ────────────────────────────────────────
     * তথ্যটা সব রূপেই থাকে; নকলটা চেনা যায় **জায়গা** দেখে। ঘরগুলো
     * নিচের তালিকায় বসিয়ে দিলে জিনিসটা কাজ করত, কিন্তু ওডু আর ওডু
     * থাকত না।
     */
    public function test_only_odoo_puts_them_at_the_head_of_the_record(): void
    {
        $customer = $this->busy;

        $wrong = [];

        foreach (Ui::keys() as $look) {
            $this->user->forceFill(['ui' => $look])->save();

            $body = (string) $this->get(route('customer.show', $customer))
                ->assertOk()
                ->getContent();

            $atHead = str_contains($body, 'data-smart-buttons');
            $wants = Ui::record($look) === 'smartbuttons';

            if ($atHead !== $wants) {
                $wrong[] = sprintf(
                    '%s — ঘরগুলো %s, অথচ %s',
                    $look,
                    $atHead ? 'মাথায় বসেছে' : 'মাথায় বসেনি',
                    $wants ? 'বসার কথা' : 'না বসার কথা',
                );
            }
        }

        $this->assertSame([], $wrong, implode("\n", [
            'রেকর্ডের ঘরগুলো ভুল জায়গায়:',
            ...$wrong,
        ]));
    }

    /**
     * নোঙর-পটি কেবল ফিওরিতে, আর কেবল রেকর্ডের পাতায়।
     *
     * ── কেন দুইটা শর্তই দেখা হয় ─────────────────────────────────────
     * পটিটা লেআউটে বসানো, অর্থাৎ প্রতিটা পাতাই তার পাশ দিয়ে যায়।
     * ফিওরি ছাড়া অন্য রূপে বসলে ওটা আর নকল থাকে না; আর তালিকার পাতায়
     * বসলে ওটা অর্থহীন — এক অংশের পাতায় নোঙর যেখানে আছি সেখানেই
     * নিয়ে যায়।
     *
     * দ্বিতীয় শর্তটা markup দিয়ে নয়, আচরণ দিয়ে সামলানো (`x-show`),
     * তাই এখানে দেখা হয় পটিটা **পৌঁছেছে** কি না; সে সত্যিই লুকায়
     * কি না সেটা ব্রাউজারে মাপা।
     */
    public function test_only_fiori_gets_the_anchor_strip(): void
    {
        $wrong = [];

        foreach (Ui::keys() as $look) {
            $this->user->forceFill(['ui' => $look])->save();

            $body = (string) $this->get(route('customer.show', $this->busy))
                ->assertOk()
                ->getContent();

            $has = str_contains($body, 'data-anchor-nav');
            $wants = Ui::sections($look) === 'anchors';

            if ($has !== $wants) {
                $wrong[] = sprintf(
                    '%s — পটিটা %s, অথচ %s',
                    $look,
                    $has ? 'আছে' : 'নেই',
                    $wants ? 'থাকার কথা' : 'না থাকার কথা',
                );
            }
        }

        $this->assertSame([], $wrong, implode("\n", [
            'নোঙর-পটি ভুল রূপে:',
            ...$wrong,
        ]));
    }

    /**
     * একটা তালিকার পাতায় কয়টা তথ্যের সারি।
     *
     * ── কেন `<tbody>` আলাদা করে বের করা হয় ──────────────────────────
     * পুরো পাতায় `<tr` গুনলে হেডারের সারিটাও গোনা হত, আর পাতায় একাধিক
     * ছক থাকলে (সারাংশের ছোট ছক, তারপর মূল তালিকা) সবগুলোই যোগ হত।
     * তখন সংখ্যাটা সবসময় বেশি আসত, আর পরীক্ষাটা ভুল কারণে লাল হত —
     * যেটা মিথ্যা সবুজের মতোই খারাপ।
     */
    private function rowsOn(string $url): int
    {
        $html = (string) $this->get($url)->assertOk()->getContent();

        if (preg_match('~<tbody\b[^>]*>(.*?)</tbody>~is', $html, $m) !== 1) {
            return 0;
        }

        return preg_match_all('~<tr\b~i', $m[1]);
    }
}

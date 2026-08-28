<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Core\Support\ProcessBand;
use App\Core\Support\Ui;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dynamics 365-এর তীরগুলো — আঁকা হয়, ঠিক জায়গায়, আর সংখ্যাটা সত্যি।
 *
 * ── কী বলা হয়েছিল, ২৯ আগস্ট ২০২৬ ─────────────────────────────────────
 * *"dynamic 365 er rup tik nokol hoyni ava dms e deke tik koro"* —
 * D365-এর রূপটা ঠিক নকল হয়নি, Ava ও DMS দেখে মেলাতে হবে।
 *
 * মিলিয়ে দেখা গেল রং ও কাঠামো বেশিরভাগই ঠিক ছিল, আর DMS-এর নিজের
 * প্যালেটে তার প্রমাণও লেখা: নেভি শেল `#0B2A4A`, সাইট ম্যাপ `#F5F5F5`
 * ("Fluent's own", ওদের মন্তব্য), নিচে পিন করা এরিয়া-সুইচার, ওয়াফল।
 *
 * **যেটা ছিল না** সেটা DMS-এর ঘোষণায় `dynamic`-এর signature বলে লেখা:
 *
 *   "A real chevron bar (clip-path, not borders) carrying a count and
 *    a total per stage"
 *
 * ── কেন এই পরীক্ষাটা আলাদা ফাইলে ─────────────────────────────────────
 * [[AClonePromisedAShapeAndDrewAnotherTest]]-এর signature তালিকা
 * **প্রতিটা পাতায়** চিহ্নটা খোঁজে, ড্যাশবোর্ড ধরে। ধাপের পটি কেবল
 * কাগজের তালিকায় বসে — ড্যাশবোর্ডে ধাপ নেই। ওখানে বসালে হয় দাবিটা
 * আলগা করতে হত, নয় ড্যাশবোর্ডে একটা খালি পটি আঁকতে হত।
 */
class TheArrowsWereMissingFromDynamicsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->customer = Customer::query()->firstOrFail();
    }

    private function makeInvoice(string $qty = '1', string $rate = '100'): SalesInvoice
    {
        return app(SalesInvoiceService::class)->create(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => Product::query()->firstOrFail()->id, 'qty' => $qty, 'rate' => $rate]],
        );
    }

    private function seeAs(string $look): string
    {
        $this->user->forceFill(['ui' => $look])->save();

        return (string) $this->actingAs($this->user)
            ->get(route('sales.invoice.index'))->getContent();
    }

    /**
     * তীরগুলো আঁকা হয়, আর `clip-path` দিয়েই আঁকা হয়।
     *
     * বর্ডার দিয়ে তীর DMS-এ আগে করা হয়েছিল; যেকোনো zoom-এ জোড়াটা
     * একটা তীর না হয়ে কোণ করে মেলা দুইটা লাইন হয়ে পড়ত। কেউ
     * `clip-path` তুলে বর্ডারে ফিরলে ঠিক সেই চেহারাটাই ফিরে আসত, আর
     * রঙের কোনো পরীক্ষা সেটা ধরত না।
     */
    public function test_dynamics_draws_a_real_chevron_bar(): void
    {
        $this->makeInvoice();

        $html = $this->seeAs('dynamic');

        $this->assertStringContainsString('data-process-band', $html,
            'D365-এর ধাপের পটিটাই নেই — DMS-এর ঘোষণায় ওটাই এই রূপের signature।');

        $this->assertStringContainsString('clip-path: polygon(', $html,
            'তীরগুলো clip-path দিয়ে কাটা হয়নি — বর্ডারের তীর zoom-এ ভেঙে যায়।');
    }

    /**
     * আর কোনো রূপে ওটা বসে না।
     *
     * D365-এর জিনিস Odoo বা Fiori-তে বসালে ওটা আর নকল থাকে না,
     * সাজসজ্জা হয়ে যায় — আর তখন দশটা রূপই ধীরে ধীরে এক হয়ে আসে।
     */
    public function test_no_other_look_borrows_the_arrows(): void
    {
        $this->makeInvoice();

        $wrong = [];

        foreach (Ui::keys() as $look) {
            if (Ui::band($look) === 'chevrons') {
                continue;
            }

            if (str_contains($this->seeAs($look), 'data-process-band')) {
                $wrong[] = $look;
            }
        }

        $this->assertSame([], $wrong,
            'D365-এর ধাপের পটি অন্য রূপেও বসেছে: '.implode(', ', $wrong));
    }

    /**
     * তীরের সংখ্যাটা সত্যি — আর তালিকার সাথেই মেলে।
     *
     * ── কেন এটাই আসল দাবি ────────────────────────────────────────────
     * একটা তীর আঁকা সহজ। ওর ভেতরের সংখ্যাটা **ঠিক** রাখা কঠিন, আর
     * ভুল হলে সেটা সবচেয়ে খারাপ ধরনের ভুল: পর্দায় বড় করে লেখা একটা
     * সংখ্যা যেটা কেউ মিলিয়ে দেখে না।
     */
    public function test_each_arrow_counts_what_its_own_list_shows(): void
    {
        $this->makeInvoice();
        $this->makeInvoice();

        $confirmed = $this->makeInvoice();
        app(SalesInvoiceService::class)->confirm($confirmed);

        $stages = ProcessBand::forStatuses(
            SalesInvoice::query(),
            [
                ['status' => DocumentStatus::DRAFT, 'label' => 'draft'],
                ['status' => DocumentStatus::CONFIRMED, 'label' => 'confirmed'],
            ],
            'sales.invoice.index',
            [],
            null,
        );

        $byLabel = collect($stages)->keyBy('label');

        $this->assertSame(2, $byLabel['draft']['count']);
        $this->assertSame(1, $byLabel['confirmed']['count']);

        /*
         * আর তীরে ক্লিক করলে সত্যিই ওই ধাপটাই আসে। লিংকটা কাজ না
         * করলে সংখ্যাটা একটা মৃত সংখ্যা — [[FigureLinksTest]]-এর
         * একই নিয়ম।
         */
        $body = (string) $this->actingAs($this->user)->get($byLabel['confirmed']['url'])->getContent();

        $this->assertStringContainsString($confirmed->document_no, $body);
    }

    /**
     * প্রথম তীরের বাঁ কিনারা সমান, শেষটার ডান কিনারা সমান।
     *
     * একটা সারির প্রথম তীরের বাঁয়ে খাঁজ থাকলে ওটা দেখায় যেন আগে
     * আরেকটা ধাপ ছিল যেটা কেটে ফেলা হয়েছে। শেষটার ডানে ডগা থাকলে
     * মনে হয় সারিটা এখানেই শেষ নয়।
     */
    public function test_the_first_and_last_arrow_are_flat_on_the_outside(): void
    {
        $first = ProcessBand::chevronPoints(0, 3);
        $middle = ProcessBand::chevronPoints(1, 3);
        $last = ProcessBand::chevronPoints(2, 3);

        $this->assertStringNotContainsString('14px 50%', $first,
            'প্রথম তীরের বাঁয়েও খাঁজ কাটা হয়েছে।');

        $this->assertStringContainsString('14px 50%', $middle);
        $this->assertStringContainsString('100% 50%', $middle);

        $this->assertStringNotContainsString('100% 50%', $last,
            'শেষ তীরের ডানেও ডগা রাখা হয়েছে।');
        $this->assertStringContainsString('14px 50%', $last);

        // একটাই ধাপ হলে দুই দিকই সমান — অর্ধেক তীর কিছু বোঝায় না
        $only = ProcessBand::chevronPoints(0, 1);
        $this->assertStringNotContainsString('50%', $only);
    }
}

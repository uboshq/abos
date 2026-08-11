<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Engines\Print\PaperSize;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockTransferService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * স্থানান্তরের কাগজ — মজুদের একমাত্র কাগজ যেটা সত্যিই বাইরে যায়।
 *
 * গণনা, সমন্বয়, খোলা মজুদ — সবগুলোর ফল খতিয়ানে বসে আর পর্দাতেই পড়া যায়।
 * স্থানান্তরে মাল একটা ট্রাকে ওঠে, রাস্তায় থাকে, অন্য গুদামে নামে — আর
 * ওই পথটুকুতে কাগজই একমাত্র প্রমাণ।
 */
class StockTransferPrintTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private Warehouse $from;

    private Warehouse $to;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);

        $this->from = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->to = Warehouse::query()->whereKeyNot($this->from->id)->first()
            ?? Warehouse::query()->create([
                'company_id' => $this->company->id,
                'code' => 'WH2',
                'name_en' => 'Second store',
                'name_bn' => 'দ্বিতীয় গুদাম',
                'is_default' => false,
            ]);

        $this->product = Product::query()->firstOrFail();
    }

    private function transfer(): StockTransfer
    {
        return app(StockTransferService::class)->create(
            [
                'from_warehouse_id' => $this->from->id,
                'to_warehouse_id' => $this->to->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => $this->product->id, 'qty' => '5']],
        );
    }

    // ── কাগজটা বেরোয় ─────────────────────────────────────────────

    public function test_a_transfer_prints(): void
    {
        $this->get(route('inventory.transfer.print', $this->transfer()))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_every_paper_size_works(): void
    {
        $transfer = $this->transfer();

        foreach (PaperSize::all() as $paper) {
            $this->get(route('inventory.transfer.print', $transfer).'?paper='.$paper)
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf');
        }
    }

    public function test_an_unknown_paper_size_falls_back(): void
    {
        $this->get(route('inventory.transfer.print', $this->transfer()).'?paper=a3')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    // ── কাগজে কী থাকে ────────────────────────────────────────────

    /**
     * দাম নেই।
     *
     * এক গুদাম থেকে আরেক গুদামে মাল গেলে প্রতিষ্ঠানের সম্পদ বদলায় না,
     * শুধু জায়গা বদলায়। কাগজে দাম বসালে ড্রাইভার আর পথের সবাই জেনে যেত
     * মালের দাম কত, অথচ ওটা তাঁদের কাজে লাগে না।
     */
    public function test_the_paper_carries_no_prices(): void
    {
        $seen = $this->render($this->transfer());

        $this->assertFalse($seen['doc']->showMoney,
            'স্থানান্তরের কাগজে দাম দেখানো হচ্ছে — ওখানে দামের কোনো কাজ নেই।');
        $this->assertSame([], $seen['doc']->totals);
    }

    /**
     * দুইটা সইয়ের ঘর — পাঠালেন কে, বুঝে নিলেন কে।
     *
     * এটাই কাগজটার পুরো কারণ। একটা সই থাকলে "পাঠিয়েছি, পৌঁছেছে" এক
     * জনেই লিখে দিতে পারতেন, আর মাল পথে সরে গেলেও কাগজে সব মিলত।
     */
    public function test_it_asks_for_two_signatures(): void
    {
        $seen = $this->render($this->transfer());

        $this->assertSame([
            'inventory::print.dispatched_by',
            'inventory::print.received_by',
        ], $seen['doc']->signatures);
    }

    /** চালু চালানে "বাতিল" ছাপ থাকে না। */
    public function test_a_live_transfer_carries_no_cancelled_stamp(): void
    {
        $this->assertNull($this->render($this->transfer())['doc']->notice);
    }

    // ── কে ছাপতে পারে ────────────────────────────────────────────

    public function test_someone_without_the_permission_gets_nothing(): void
    {
        $transfer = $this->transfer();

        $outsider = User::factory()->create();
        $outsider->companies()->attach($this->company, ['is_active' => true]);
        $outsider->forceFill(['current_company_id' => $this->company->id])->save();

        $this->actingAs($outsider)
            ->get(route('inventory.transfer.print', $transfer))
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $transfer = $this->transfer();

        auth()->logout();

        $this->get(route('inventory.transfer.print', $transfer))->assertRedirect(route('login'));
    }

    /**
     * কন্ট্রোলার টেমপ্লেটকে যা দিল।
     *
     * PDF-এর ভেতরে খোঁজা হয় না: mPDF বাংলা লেখা সাবসেট করা ফন্টে এনকোড
     * করে, তাই শব্দটা ওখানে না পাওয়া কিছুই প্রমাণ করে না।
     *
     * @return array<string, mixed>
     */
    private function render(StockTransfer $transfer): array
    {
        $seen = [];

        View::composer('print.document', function ($view) use (&$seen) {
            $seen = $view->getData();
        });

        $this->get(route('inventory.transfer.print', $transfer))->assertOk();

        return $seen;
    }
}

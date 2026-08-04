<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * নিয়ম ১ — প্রতিটা সংখ্যা থেকে তার উৎসে যাওয়া যাবে।
 *
 * এতদিন নিয়মটা অর্ধেক মানা হত। কোড ও ডকুমেন্ট নম্বর ক্লিকযোগ্য ছিল,
 * কিন্তু **টাকার অঙ্কগুলো নয়** — অথচ ব্যবহারকারী কোডে ক্লিক করেন না।
 * তিনি সংখ্যাটা দেখেন, অবাক হন, আর জানতে চান "এই ১,২৫,০০০ কোথা থেকে
 * এল"। ঠিক ওই জায়গাটাই লিংক ছিল না।
 *
 * কোনো টেস্ট ভাঙত না, কারণ পাতাটা ২০০ দিত আর সংখ্যাটাও দেখা যেত।
 */
class FigureLinksTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    public function test_the_payable_figure_on_the_supplier_list_is_a_link(): void
    {
        $supplier = app(SupplierService::class)->create([
            'name_en' => 'Linked Supplier',
            'credit_limit' => 0,
            'credit_days' => 0,
            'opening_balance' => '12500.0000',
            'opening_date' => '2026-07-01',
        ]);

        $html = $this->actingAs($this->user)->get(route('supplier.index'))->getContent();

        $this->assertFigureIsLinked($html, '12,500.00', route('supplier.show', $supplier));
    }

    public function test_the_outstanding_figure_on_the_customer_list_is_a_link(): void
    {
        $customer = app(CustomerService::class)->create([
            'name_en' => 'Linked Customer',
            'credit_limit' => 0,
            'credit_days' => 0,
            'opening_balance' => '9800.0000',
            'opening_date' => '2026-07-01',
        ]);

        $html = $this->actingAs($this->user)->get(route('customer.index'))->getContent();

        $this->assertFigureIsLinked($html, '9,800.00', route('customer.show', $customer));
    }

    /**
     * ছকের প্রতিটা ব্যালেন্স তার খাতের পাতায় নিয়ে যায়।
     *
     * ওই পাতাতেই সেই এন্ট্রিগুলো আছে যেগুলো যোগ হয়ে সংখ্যাটা হয়েছে —
     * অর্থাৎ "এই খাতে ৪৭,০০০ কেন" প্রশ্নের উত্তর এক ক্লিক দূরে।
     */
    public function test_every_balance_in_the_chart_leads_to_its_account(): void
    {
        app(StandardChart::class)->install();

        $account = Account::query()->postable()->firstOrFail();

        $html = $this->actingAs($this->user)->get(route('accounts.coa.index'))->getContent();

        $this->assertStringContainsString(
            route('accounts.coa.show', $account),
            $html,
            'ছকের তালিকায় খাতের পাতার লিংক নেই।',
        );
    }

    /**
     * একক পাতার বড় অঙ্কটা নিচের লেনদেনের টেবিলে নিয়ে যায়।
     *
     * অ্যাংকরটা সত্যিই থাকতে হবে — না থাকলে লিংকটা কোথাও নিয়ে যেত না,
     * আর সেটা আরেক ধরনের মৃত লিংক: দেখতে কাজের, ক্লিকে নিশ্চুপ।
     */
    public function test_the_big_figure_on_a_record_page_reaches_its_transactions(): void
    {
        $supplier = app(SupplierService::class)->create([
            'name_en' => 'Anchored',
            'credit_limit' => 0,
            'credit_days' => 0,
            'opening_balance' => '4200.0000',
            'opening_date' => '2026-07-01',
        ]);

        $html = $this->actingAs($this->user)->get(route('supplier.show', $supplier))->getContent();

        $this->assertStringContainsString('href="#transactions"', $html);
        $this->assertStringContainsString('id="transactions"', $html, 'লিংকটা যেখানে যায় সেই জায়গাটাই নেই।');
    }

    /**
     * শূন্য অঙ্ক লিংক হয় না।
     *
     * শূন্যের পেছনে যাওয়ার মতো কিছু নেই — ক্লিক করলে ব্যবহারকারী একটা
     * খালি টেবিল পেতেন আর ভাবতেন কিছু ভেঙেছে।
     */
    public function test_a_zero_figure_is_not_a_link(): void
    {
        $rendered = view('ui.amount-link', [
            'value' => '0.0000',
            'href' => '/somewhere',
            'blankOnZero' => true,
        ])->render();

        $this->assertStringNotContainsString('<a', $rendered);
    }

    public function test_a_figure_without_a_source_stays_plain_text(): void
    {
        $rendered = view('ui.amount-link', ['value' => '1500.0000'])->render();

        $this->assertStringNotContainsString('<a', $rendered);
        $this->assertStringContainsString('1,500.00', $rendered);
    }

    /**
     * সংখ্যাটা একটা <a>-এর ভেতরে আছে, আর সেই <a> ঠিক জায়গায় যায়।
     *
     * শুধু "পাতায় লিংকটা আছে" দেখলে যথেষ্ট হত না — লিংক থাকতে পারে
     * অন্য কোনো ঘরে (কোডের কলামে), আর সংখ্যাটা তখনো সাধারণ লেখা।
     */
    private function assertFigureIsLinked(string $html, string $figure, string $href): void
    {
        $pattern = '/<a[^>]*href="'.preg_quote($href, '/').'"[^>]*>\s*'.preg_quote($figure, '/').'\s*<\/a>/';

        $this->assertMatchesRegularExpression(
            $pattern,
            $html,
            "অঙ্কটা ({$figure}) লিংক নয় — নিয়ম ১ বলে প্রতিটা সংখ্যা থেকে উৎসে যাওয়া যাবে।",
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * টুলবারের রপ্তানি — সত্যিই একটা ফাইল বেরোয় কি না।
 *
 * ── কেন এই টেস্টটা আছে ──────────────────────────────────────────────
 * "Export CSV" লেখাটা টুলবারে ছিল, লিংকটাও ছিল (?export=csv), অথচ কেউ
 * ওই কোয়েরিটা পড়ত না — ক্লিক করলে একই HTML পাতা ফিরে আসত, ২০০ স্ট্যাটাস
 * সহ। প্রতিটা টেস্ট সবুজ থাকত, কারণ কোনো টেস্ট জিজ্ঞেস করেনি "যা নামল
 * সেটা কি সত্যিই একটা CSV?"
 *
 * এখানে সেটাই জিজ্ঞেস করা হয়।
 */
class ExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
    }

    /**
     * তালিকার পর্দা থেকে সত্যিকারের CSV নামে।
     */
    public function test_a_list_screen_exports_a_csv_file(): void
    {
        app(SupplierService::class)->create([
            'name_en' => 'Padma Traders',
            'credit_limit' => 0,
            'credit_days' => 0,
            'opening_balance' => '4500.00',
            'opening_date' => '2026-07-01',
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('supplier.index', ['export' => 'csv']))
            ->assertOk();

        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString(
            'attachment; filename="abos-supplier-index-',
            (string) $response->headers->get('content-disposition'),
        );

        $csv = $response->getContent();

        // Excel বাংলা ঠিকঠাক খোলে কেবল BOM থাকলে
        $this->assertStringStartsWith("\xEF\xBB\xBF", (string) $csv);

        // পর্দার সারিটাই ফাইলে
        $this->assertStringContainsString('Padma Traders', (string) $csv);

        // ঘরে বসানো লিংক বা ব্যাজ লেখা হয়ে বেরোয়, ট্যাগ হয়ে নয়
        $this->assertStringNotContainsString('<', (string) $csv);
    }

    /**
     * ফাইলের কলামগুলো পর্দার কলামগুলোই।
     *
     * ── কেন এটা আলাদা করে পরীক্ষা ──────────────────────────────────
     * রপ্তানি আলাদা করে লিখলে কলাম দুই জায়গায় থাকত, আর একদিন পর্দায়
     * নতুন কলাম যোগ হয়ে ফাইলে হত না। তখন দুইটা কাগজ দুই কথা বলত।
     * এখানে দুইটাই এক উৎস থেকে আসে, আর এই টেস্টটা সেটাই ধরে রাখে।
     */
    public function test_the_file_carries_the_same_columns_as_the_screen(): void
    {
        $csv = (string) $this->actingAs($this->user)
            ->get(route('supplier.index', ['export' => 'csv']))
            ->assertOk()
            ->getContent();

        $header = strtok(ltrim($csv, "\xEF\xBB\xBF"), "\r\n");

        $this->assertNotFalse($header);
        $this->assertStringContainsString(__('supplier::field.name'), (string) $header);
    }

    /**
     * সূত্র হিসেবে চলে যেতে পারে এমন নাম নিরীহ হয়ে বেরোয়।
     *
     * ── কেন ─────────────────────────────────────────────────────────
     * Excel = দিয়ে শুরু হওয়া ঘরকে সূত্র ধরে চালায়। গ্রাহকের নামে কেউ
     * একটা সূত্র লিখে রাখলে সেটা আমাদের ফাইল হয়ে অন্য কারো মেশিনে গিয়ে
     * চলত — ডাটাবেজে নিরীহ একটা নাম, স্প্রেডশিটে একটা প্রোগ্রাম।
     */
    public function test_a_name_that_looks_like_a_formula_cannot_run(): void
    {
        app(SupplierService::class)->create([
            'name_en' => '=1+1',
            'credit_limit' => 0,
            'credit_days' => 0,
            'opening_balance' => '0.00',
            'opening_date' => '2026-07-01',
        ]);

        $csv = (string) $this->actingAs($this->user)
            ->get(route('supplier.index', ['export' => 'csv']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("'=1+1", $csv);
    }

    /**
     * তালিকা নেই এমন পর্দায় রপ্তানি খালি ফাইল নামায় না।
     *
     * খালি একটা CSV পেলে ব্যবহারকারী ভাবতেন ডেটা নেই — অথচ ওই পর্দায়
     * রপ্তানি করার মতো তালিকাই নেই। তখন পাতাটাই ফেরে।
     */
    public function test_a_screen_without_a_list_still_shows_the_page(): void
    {
        $this->actingAs($this->user)
            ->get(route('supplier.create', ['export' => 'csv']))
            ->assertOk()
            ->assertHeader('content-type', 'text/html; charset=UTF-8');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Purchase\Dashboard\PurchaseWidgets;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * পুনঃনিরীক্ষার ছোট ফাঁকগুলো — ৯ অক্টোবর ২০২৬।
 *
 * ① রিপোর্টের উৎস-ছাঁকনিতে অ্যারে এলে নীরবে পার হত, আর মজুদের রিপোর্ট
 *    `(int) $f['warehouse_id']` লিখে সেটাকে গুদাম ১ বানাত — যাচাই ছাড়াই।
 * ② ক্রয়ের হোমের "এ মাসের মার্জিন" `DB::table()` দিয়ে পড়ত, আর মোছা বিলও গুনত।
 */
final class TheSmallLeaksOfTheReAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, null);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_an_array_in_a_report_filter_is_refused_not_turned_into_warehouse_one(): void
    {
        try {
            app(ReportEngine::class)->run('inventory.lot_trace', ['warehouse_id' => ['7']]);
            $this->fail('⛔ অ্যারে-গুদাম মেনে নেওয়া হলো — কোয়েরি ওটাকে গুদাম ১ পড়ত।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('warehouse_id', $e->errors());
        }
    }

    public function test_a_deleted_invoice_is_not_in_the_months_margin(): void
    {
        $margin = function (): ?string {
            $widget = (fn () => self::marginThisMonth())->call(new PurchaseWidgets);

            return $widget?->parts[__('supplier::field.sold')] ?? null;
        };

        $before = $margin();

        $customer = DB::table('customers')->where('company_id', CompanyContext::id())->value('id');
        $this->assertNotNull($customer, 'ডেমোতে কোনো গ্রাহক নেই — দাবি অন্ধ।');

        // ⓘ ঘরগুলো হাতে — `DB::table()` মডেলের হুক চালায় না, তাই মডেলের নিয়মের বাইরে একটা মোছা, পাকা বিল
        DB::table('sal_invoices')->insert([
            'company_id' => CompanyContext::id(),
            'public_id' => (string) Str::uuid7(),
            'document_no' => 'DELETED-MARGIN-1',
            'customer_id' => $customer,
            'trx_date' => now()->toDateString(),
            'status' => DocumentStatus::CONFIRMED,
            'total' => '987654.0000',
            'tax' => '0.0000',
            'cost_of_goods' => '1.0000',
            'deleted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ⓘ দাবিটা অন্ধ নয়: একই বিল মোছা না হলে সংখ্যাটা সত্যিই নড়ে
        DB::table('sal_invoices')->where('document_no', 'DELETED-MARGIN-1')->update(['deleted_at' => null]);
        $this->assertNotSame($before, $margin(), 'মোছা-না-হওয়া বিলেও মার্জিন নড়েনি — দাবি অন্ধ।');
        DB::table('sal_invoices')->where('document_no', 'DELETED-MARGIN-1')->update(['deleted_at' => now()]);

        $this->assertSame($before, $margin(), '⛔ মোছা বিলের বিক্রয় এ মাসের মার্জিনে গোনা হলো।');
    }
}

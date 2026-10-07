<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Finance\Services\CashForecast;
use App\Modules\Sales\Models\SalesInvoice;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * পূর্বাভাস প্রতিবার গোটা ইতিহাস পড়ত।
 *
 * ── ⓘ অডিট, ২০ সেপ্টেম্বর ২০২৬ ───────────────────────────────────────
 * নগদের পূর্বাভাস কোম্পানির **প্রতিটা** পোস্ট করা চালান ও বিল একসাথে
 * মেমরিতে তুলত, তারিখের কোনো সীমা ছাড়া। ⚠️ বছরে ৩০,০০০ চালানের ডিপোতে
 * দ্বিতীয় বছরে ৬০,০০০ সারি — আর এটা ধীরে ধীরে খারাপ হয় না, একদিন
 * চলে আর পরদিন সময় শেষ হয়ে থামে।
 *
 * ── ⭐ আর সারিগুলো কাজেও লাগত না ──────────────────────────────────────
 * পর্দার ঘর চারটা: এখন · ৩০ · ৬০ · ৯০ দিন। ⓘ তার পরের সবকিছু আগেও
 * ফেলে দেওয়া হত — কেবল ফেলার আগে ডাটাবেস থেকে তোলা হত।
 */
final class TheForecastReadEveryInvoiceEverWrittenTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->customer = Customer::query()->firstOrFail();
    }

    /**
     * ⭐ নব্বই দিনের বাইরের চালান ডাটাবেস থেকে তোলাই হয় না।
     */
    public function test_invoices_beyond_the_horizon_are_never_loaded(): void
    {
        $this->invoice('1000', now()->addDays(10));
        $this->invoice('9999', now()->addDays(200));

        $rows = $this->rowsRead();

        $this->assertSame(1, $rows,
            'দূরের চালানটাও তোলা হয়েছে — সারির সংখ্যা বাড়লে পাতাটা একদিন থামত।');
    }

    /**
     * ⭐ আর পর্দার সংখ্যা এক চুলও বদলায় না।
     *
     * ⚠️ এটাই আসল দাবি: ছাঁকনিটা গতি বাড়ানোর জন্য, হিসাব বদলানোর জন্য নয়।
     */
    public function test_the_numbers_on_the_screen_do_not_change(): void
    {
        $this->invoice('1000', now()->addDays(10));
        $this->invoice('9999', now()->addDays(200));

        $rows = collect(app(CashForecast::class)->build()['rows'])
            ->mapWithKeys(fn (array $r) => [$r['bucket'] => $r['receivables']]);

        $this->assertSame(0, bccomp($rows['d30'], '1000', 4));

        // ⓘ দূরের চালানটা কোনো ঘরেই নেই — আগেও ছিল না
        foreach (['now', 'd30', 'd60', 'd90'] as $bucket) {
            $this->assertSame(-1, bccomp($rows[$bucket], '9999', 4), "{$bucket} ঘরে দূরের চালানটা এসেছে।");
        }
    }

    /**
     * ⚠️ তারিখহীন চালান তবু আসে — ওটা "এখন" ঘরে পড়ে।
     *
     * ⛔ বাদ দিলে বকেয়া পাওনা নীরবে পর্দা থেকে মুছে যেত।
     */
    public function test_an_invoice_without_a_due_date_still_counts(): void
    {
        $this->invoice('700', null);

        $rows = collect(app(CashForecast::class)->build()['rows'])
            ->mapWithKeys(fn (array $r) => [$r['bucket'] => $r['receivables']]);

        $this->assertSame(0, bccomp($rows['now'], '700', 4));
    }

    /**
     * পূর্বাভাস বানানোর সময় কতগুলো চালানের সারি সত্যিই মেমরিতে উঠল।
     *
     * ⓘ কোয়েরি গোনা হয় না, **সারি** গোনা হয় — এই পাতার বিপদটা
     * কোয়েরির সংখ্যা নয়, এক কোয়েরিতে ষাট হাজার সারি তোলা।
     *
     * ⚠️ আর গোনাটা সেবার নিজের আচরণ ধরেই — পরীক্ষায় আলাদা করে
     * একই ছাঁকনি লিখলে সারাইটা তুলে নিলেও পরীক্ষা সবুজ থাকত।
     */
    private function rowsRead(): int
    {
        $seen = 0;

        Event::listen('eloquent.retrieved: '.SalesInvoice::class, function () use (&$seen) {
            $seen++;
        });

        app(CashForecast::class)->build();

        return $seen;
    }

    private function invoice(string $total, $dueOn): SalesInvoice
    {
        return SalesInvoice::query()->create([
            'company_id' => CompanyContext::id(),
            'branch_id' => CompanyContext::branchId(),
            'document_no' => 'INV-'.$total.'-'.($dueOn?->toDateString() ?? 'none'),
            'customer_id' => $this->customer->id,
            'trx_date' => now()->toDateString(),
            'due_on' => $dueOn?->toDateString(),
            'status' => DocumentStatus::CONFIRMED,
            'subtotal' => $total,
            'total' => $total,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Http\Controllers\SalesPrintController;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * বিলের ACCOUNT MOVEMENT — মালিক, ৩ অক্টোবর ২০২৬: *"ACCOUNT MOVEMENT e current month er transaction dibe with
 * narretion soho"*।
 *
 * ⓘ আগে কেবল তিন সারি: আগের জের, এই বিল, এই বিলের জমা। এখন বিলের মাসের আসল খাতা — শুরুর জের, তারপর মাসের প্রতিটা
 * দাখিলা নম্বর আর বিবরণসহ; শেষ জের গ্রাহকের আসল বকেয়ার সাথে মেলে।
 */
final class TheBillShowsTheMonthsMovementTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_movement_is_the_months_ledger_with_its_narration_and_ends_at_the_real_balance(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $customer = Customer::query()->firstOrFail();
        $service = app(SalesInvoiceService::class);
        $make = fn (string $rate) => $service->confirm($service->create(
            [
                'customer_id' => $customer->id,
                'warehouse_id' => Warehouse::query()->where('is_default', true)->firstOrFail()->id,
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => Product::query()->firstOrFail()->id, 'qty' => '1', 'rate' => $rate]],
        ));

        $first = $make('100');
        $second = $make('250');

        $facts = (new ReflectionMethod(SalesPrintController::class, 'classicFacts'))
            ->invoke(app(SalesPrintController::class), SalesInvoice::query()->findOrFail($second->id));
        $rows = $facts['movement'];

        /* শুরুর জের = মাসের আগের সব দাখিলার যোগ */
        $before = (string) LedgerEntry::query()->forParty('customer', $customer->id)
            ->where('trx_date', '<', now()->startOfMonth()->toDateString())
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as net')->value('net');
        $this->assertSame(0, bccomp($rows[0]['balance'], $before ?: '0', 4), '⛔ মাসের শুরুর জের ভুল।');

        /* ⭐ এই মাসের দুটো বিলই আছে, নম্বরসহ — কেবল ছাপা বিলটা নয় */
        $texts = implode("\n", array_column($rows, 'text'));
        $this->assertStringContainsString((string) $first->document_no, $texts, '⛔ মাসের আগের বিলটা চলাচলে নেই।');
        $this->assertStringContainsString((string) $second->document_no, $texts);

        /* ⭐ শেষ জের = গ্রাহকের আসল বকেয়া */
        $this->assertSame(0, bccomp(end($rows)['balance'], $customer->fresh()->outstanding(), 4),
            '⛔ চলাচলের শেষ জের বকেয়ার সাথে মেলে না।');

        /* ⭐ "koyta line print hobe" — ১ দিলে কেবল শেষ লেনদেন; বাদ পড়াটা শুরুর জেরে, শেষ জের একই */
        app(\App\Core\Services\SettingsService::class)->set('sales.print.movement_lines', 1);
        $one = (new ReflectionMethod(SalesPrintController::class, 'classicFacts'))
            ->invoke(app(SalesPrintController::class), SalesInvoice::query()->findOrFail($second->id))['movement'];
        $this->assertCount(2, $one, '⛔ সারির সীমা মানা হয়নি (শুরুর জের + ১)।');
        $this->assertSame(0, bccomp(end($one)['balance'], end($rows)['balance'], 4), '⛔ সারি কমাতে শেষ জের বদলে গেল।');
    }
}

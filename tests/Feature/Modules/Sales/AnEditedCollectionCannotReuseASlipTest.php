<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Services\CollectionService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ খসড়া আদায় সম্পাদনায় আরেকটা আদায়ের স্লিপ বসে না — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (বিক্রয় ৪; [[CollectionService::update()]])।
 *
 * ⓘ পাহারা ছিল কেবল নতুন আদায়ে। খসড়া খুলে আগের আদায়ের বিকাশ TrxID লিখলে আটকাত না — পাকা হলে গ্রাহকের পাওনা দুইবার কমত, টাকা এসেছে একবার।
 */
final class AnEditedCollectionCannotReuseASlipTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_edit_cannot_take_another_collections_slip_but_may_keep_its_own(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $customer = Customer::query()->orderBy('id')->firstOrFail();
        $service = app(CollectionService::class);

        $service->create(['customer_id' => $customer->id, 'trx_date' => now()->toDateString(), 'amount' => '5000', 'instrument_no' => 'BK-77219'], []);
        $draft = $service->create(['customer_id' => $customer->id, 'trx_date' => now()->toDateString(), 'amount' => '5000', 'instrument_no' => 'BK-88431'], []);

        try {
            $service->update($draft, ['trx_date' => now()->toDateString(), 'amount' => '5000', 'instrument_no' => 'BK-77219'], []);
            $this->fail('⛔ সম্পাদনায় আরেকটা আদায়ের স্লিপ বসে গেল — পাকা হলে পাওনা দুইবার কমত');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('instrument_no', $e->errors());
        }

        $this->assertSame('BK-88431', Collection::query()->findOrFail($draft->id)->instrument_no);

        // ⓘ নিজের স্লিপ রেখে অঙ্ক বদলানো — আগের মতোই চলে
        $this->assertSame('6000.0000', (string) $service->update($draft->fresh(), ['trx_date' => now()->toDateString(), 'amount' => '6000', 'instrument_no' => 'BK-88431'], [])->amount);
    }
}

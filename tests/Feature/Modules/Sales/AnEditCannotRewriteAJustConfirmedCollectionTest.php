<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
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
 * ⛔ এক ট্যাবে আদায় পাকা হলো, আরেক ট্যাবে পুরনো খসড়া সম্পাদনা — সম্পাদনাটা থামে (১১ অক্টোবর ২০২৬, PR #17 রিভিউ ⚠️১৪)।
 *
 * ⓘ [[CollectionService::update()]] "খসড়া কি না" দেখত হাতের কপি থেকে, ট্রানজ্যাকশনের বাইরে, তালা ছাড়া। নিশ্চিত করা
 * ([[CollectionService::confirm()]]) তালা নিয়ে তাজা পড়ে; সম্পাদনা পড়ত না — সদ্য খাতায় ওঠা আদায়ের অঙ্ক আর সারি বদলে যেত।
 */
final class AnEditCannotRewriteAJustConfirmedCollectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stale_draft_in_another_tab_cannot_rewrite_the_posted_collection(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $service = app(CollectionService::class);
        $draft = $service->create(['customer_id' => Customer::query()->orderBy('id')->value('id'), 'trx_date' => now()->toDateString(),
            'amount' => '500', 'instrument' => 'cash'], []);

        // ⓘ দ্বিতীয় ট্যাবের হাতের কপি — তখনও খসড়া
        $staleTab = Collection::query()->findOrFail($draft->id);
        $this->assertSame(DocumentStatus::DRAFT, $staleTab->status);

        $service->confirm(Collection::query()->findOrFail($draft->id));

        try {
            $service->update($staleTab, ['trx_date' => now()->toDateString(), 'amount' => '900', 'instrument' => 'cash'], []);
            $this->fail('⛔ পাকা আদায় পুরনো খসড়ার কপি দিয়ে বদলে গেল।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame(0, bccomp((string) Collection::query()->findOrFail($draft->id)->amount, '500', 4), '⛔ পাকা আদায়ের অঙ্ক বদলেছে।');
    }
}

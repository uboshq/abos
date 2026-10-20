<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Engines\Dashboard\Listing;
use App\Http\Controllers\Api\DashboardApiController;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ⭐ ড্যাশবোর্ডের তালিকার সর্বমোট ফোনেও — সার্ভারে হিসাব করা লেখাটাই, ফোন নিজে যোগ করে না (মালিক, ১৯ অক্টোবর ২০২৬: অ্যাপ ১০০%;
 * ফোনের ড্যাশবোর্ড `total` পেলে "সর্বমোট" সারি আঁকে)।
 *
 * দাবি: [[Listing::$total]] থাকলে দরজার তালিকায় `total` কলামের চাবি ধরে একই লেখা; না থাকলে চাবিটাই নেই (পুরনো অ্যাপ যেমন ছিল)।
 */
final class ThePhoneDashboardListCarriesItsTotalTest extends TestCase
{
    public function test_a_listing_total_reaches_the_phone_as_the_server_wrote_it(): void
    {
        $columns = [['key' => 'name', 'label' => 'নাম'], ['key' => 'amount', 'label' => 'টাকা']];
        $rows = collect([['name' => 'ক', 'amount' => '100.00'], ['name' => 'খ', 'amount' => '250.00']]);

        $with = $this->shape(new Listing('কমিশন', $columns, $rows, 'কিছু নেই', total: ['name' => 'সর্বমোট', 'amount' => '18,42,650.00']));
        $this->assertSame(['name' => 'সর্বমোট', 'amount' => '18,42,650.00'], $with['total'] ?? null,
            '⛔ সার্ভারের সর্বমোট ফোনের দরজায় পৌঁছায় না, বা বদলে যায় — ফোন তখন সারিগুলো যোগ করে মিথ্যা মোট দেখাত।');

        $without = $this->shape(new Listing('কমিশন', $columns, $rows, 'কিছু নেই'));
        $this->assertArrayNotHasKey('total', $without, '⛔ মোট না থাকা তালিকায় খালি `total` গেল — পুরনো অ্যাপ ভুল সারি আঁকত।');
    }

    /** @return array<string, mixed> */
    private function shape(Listing $listing): array
    {
        $method = new ReflectionMethod(DashboardApiController::class, 'listing');

        return $method->invoke(app(DashboardApiController::class), $listing);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Sales\Models\Collection;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ মুছে ফেলা গ্রাহকের নামে আদায় নয় — ১৩ অক্টোবর ২০২৬, Sales লাইন-ধরা নিরীক্ষা।
 *
 * ⓘ বিপজ্জনক ইনপুট: আদায়ের ফর্মে মুছে ফেলা ডিলারের id। `exists` নিয়ম কাঁচা কোয়েরি, তাই SoftDeletes
 * সেখানে চলত না — আদায়টা বসে যেত এমন এক পক্ষের নামে, যাকে কোনো তালিকা আর দেখায় না।
 */
class ACollectionForADeletedDealerIsRefusedTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    public function test_a_collection_for_a_deleted_dealer_is_refused(): void
    {
        $gone = app(CustomerService::class)->create([
            'name_en' => 'Gone Traders', 'credit_limit' => 0, 'credit_days' => 0,
        ]);
        $gone->delete();

        $this->actingAs($this->user)->post(route('sales.collection.store'), [
            'customer_id' => $gone->id,
            'account_id' => $this->cashAccountId(),
            'trx_date' => now()->toDateString(),
            'amount' => '100.00',
        ])->assertSessionHasErrors('customer_id');

        $this->assertSame(0, Collection::query()->where('customer_id', $gone->id)->count(),
            '⛔ মুছে ফেলা ডিলারের নামে আদায় বসে গেল।');
    }

    private function cashAccountId(): int
    {
        return (int) Account::query()->postable()->where('name_en', 'like', '%Cash%')->value('id');
    }
}

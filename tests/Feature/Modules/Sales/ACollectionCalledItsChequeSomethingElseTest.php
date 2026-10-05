<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Services\CollectionService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ম১১ — অফিসের আদায়ে চেককে অন্য নামে লিখলেও চেকই (Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে কেবল হুবহু "cheque" থামত; "Cheque 4471", "PDC", "চেক নং" লিখলে পাশের আগেই খাতায় নগদ ধরা হত আর বাকির সীমা খুলত।
 */
final class ACollectionCalledItsChequeSomethingElseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_every_way_of_writing_a_cheque_is_refused(): void
    {
        foreach (['Cheque 4471', 'PDC', 'চেক নং ১২', 'post-dated', 'CHQ#88', 'checks', 'Post Dated Cheque'] as $said) {
            try {
                $this->collect($said);
                $this->fail("⛔ \"{$said}\" লেখা আদায় চেকের খাতা এড়িয়ে নগদ হলো।");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('instrument', $e->errors(), $said);
            }
        }
    }

    /** ⓘ নগদ, বিকাশ, ব্যাংক — আগের মতোই চলে; "checkout"-এর মতো শব্দও চেক নয় */
    public function test_other_ways_are_as_before(): void
    {
        foreach (['cash', 'bKash', 'bank transfer', 'checkout counter', 'নগদ'] as $said) {
            $this->assertNotNull($this->collect($said)->id, $said);
        }
    }

    private function collect(string $instrument): \App\Modules\Sales\Models\Collection
    {
        return app(CollectionService::class)->create([
            'customer_id' => Customer::query()->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'amount' => '500',
            'instrument' => $instrument,
        ], []);
    }
}

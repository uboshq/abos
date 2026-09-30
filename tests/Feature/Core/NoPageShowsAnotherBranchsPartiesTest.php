<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * কোনো পাতায় অন্য শাখার গ্রাহক-সরবরাহকারী নেই — মালিক, ১ অক্টোবর ২০২৬:
 * *"প্রতিটা শাখা পুরো আলাদা — এক শাখার কিছুই আরেক শাখায় দেখা যাবে না; সব একসাথে কেবল মালিক, 'সব শাখা'-য়"*।
 *
 * ── ⛔ কী ফাঁক ছিল ─────────────────────────────────────────────────────
 * [[EachBranchSeesOnlyItsOwnPartiesTest]] ৩৪টা তালিকা-পিকার ছেঁকেছিল, কিন্তু লাইভে এক শাখা বেছে হাঁটতে গিয়ে আরও
 * পাতায় অন্য শাখার পক্ষ পাওয়া গেল — চেক, নোট, হাত-ঋণ, ভাড়ার পিকার ([[PartyRegistry::forPicker()]]) আর আরও কিছু।
 *
 * ── এখানে কী মাপা ──────────────────────────────────────────────────────
 * নেত্রকোনায় অদ্ভুত নামের একজন গ্রাহক আর একজন সরবরাহকারী। একই মানুষ ময়মনসিংহ বেছে প্রতিটা পাতা খোলেন — পাতার
 * কোথাও নাম দুইটা নেই; তারপর "সব শাখা" বেছে পিকারওয়ালা পাতায় নাম দুইটা আছে (নাহলে পাহারা কিছু দেখেইনি)।
 * ⓘ পাতার লেখা মাপা হয়, দৃশ্যের কোনো একটা চলক নয় — ফাঁসটা যেখান থেকেই আসুক, ধরা পড়ে।
 */
final class NoPageShowsAnotherBranchsPartiesTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMER = 'Zqx Netrakona Only Customer';

    private const SUPPLIER = 'Zqx Netrakona Only Supplier';

    /** পাতা → "সব শাখা"-য় কোন নাম দেখা যাওয়ার কথা (খালি = কেবল অনুপস্থিতি মাপা) */
    private const PAGES = [
        'accounts.cheque.create' => ['c', 's'],
        'accounts.note.create' => ['c'], // ⓘ খোলে ক্রেডিট নোটে — কেবল গ্রাহক
        'accounts.control.duplicates' => ['c'],
        'finance.hand_loan.create' => ['c', 's'],
        'finance.rental.create' => ['c', 's'],
        'sales.direct.create' => ['c'],
        'sales.order.create' => ['c'],
        'sales.challan.create' => ['c'],
        'sales.commission.create' => [],
        'sales.shipment.create' => [],
        'sales.collection.create' => ['c'],
        'sales.return.create' => ['c'],
        'sales.scheme.create' => [],
        'sales.invoice.index' => [],
        'sales.challan.index' => [],
        'customer.index' => ['c'],
        'purchase.direct.create' => ['s'],
        'purchase.requisition.create' => [],
        'purchase.contract.create' => ['s'],
        'purchase.rfq.create' => ['s'],
        'purchase.order.create' => ['s'],
        'purchase.receipt.create' => ['s'],
        'purchase.bill.create' => ['s'],
        'purchase.return.create' => ['s'],
        'inventory.product.create' => [],
        'inventory.label.index' => [],
        'master_data.brand.index' => [],
    ];

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        CompanyContext::set($this->company->id, $this->branch('MMS')->id);
        $this->actingAs($this->owner);

        // ⓘ ডেমোর দুইজনকে নেত্রকোনায় সরানো আর নাম বদলানো — আসল সারি, সব ঘর ভরা
        $ntk = $this->branch('NTK')->id;

        // ⓘ দুইজন করে একই নামে — তাহলে "একই পক্ষ দুইবার" পাতাতেও ওরা একটা দল হয়ে ওঠে
        foreach ([Customer::class => self::CUSTOMER, Supplier::class => self::SUPPLIER] as $model => $name) {
            $model::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
                ->where('is_active', true)->orderBy('id')->take(2)->get()
                ->each(fn ($row) => $row->forceFill(['branch_id' => $ntk, 'name_en' => $name, 'name_bn' => $name])->saveQuietly());
        }
    }

    public function test_one_branch_picked_no_page_shows_another_branchs_party_and_all_branches_does(): void
    {
        $leaks = [];
        $blind = [];

        $this->choose($this->branch('MMS')->id);

        foreach (array_keys(self::PAGES) as $page) {
            $html = $this->open($page);

            foreach (['c' => self::CUSTOMER, 's' => self::SUPPLIER] as $name) {
                if (str_contains($html, $name)) {
                    $leaks[] = "{$page} — {$name}";
                }
            }
        }

        $this->choose('all');

        foreach (self::PAGES as $page => $expected) {
            $html = $this->open($page);

            foreach ($expected as $who) {
                $name = $who === 'c' ? self::CUSTOMER : self::SUPPLIER;

                if (! str_contains($html, $name)) {
                    $blind[] = "{$page} — {$name}";
                }
            }
        }

        $this->assertSame([], $leaks, "⛔ ময়মনসিংহ বেছে এই পাতাগুলোয় নেত্রকোনার পক্ষ দেখা গেল:\n".implode("\n", $leaks));
        $this->assertSame([], $blind, "⛔ \"সব শাখা\"-য় পিকারে পক্ষটা নেই — পাহারা কিছু দেখছে না:\n".implode("\n", $blind));
    }

    private function open(string $page): string
    {
        $response = $this->actingAs($this->owner)->get(route($page));
        $this->assertSame(200, $response->getStatusCode(), "{$page} খুলল না ({$response->getStatusCode()})।");

        return (string) $response->getContent();
    }

    private function choose(int|string $branch): void
    {
        $this->actingAs($this->owner->fresh())
            ->post(route('branch.switch'), ['branch_id' => (string) $branch])
            ->assertRedirect();

        $this->owner = $this->owner->fresh();
        CompanyContext::set($this->company->id, $this->owner->current_branch_id);
        app(DataScope::class)->forget();
        $this->app->forgetScopedInstances();
        $this->actingAs($this->owner);
    }

    private function branch(string $code): Branch
    {
        return Branch::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }
}

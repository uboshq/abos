<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\CounterShift;
use App\Modules\Sales\Services\PosService;
use App\Modules\Sales\Services\ShiftService;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * শিফট — ড্রয়ারটার জন্য কেউ একজন দায়ী।
 *
 * ── প্রশ্নটা যা ─────────────────────────────────────────────────────
 * দিনশেষে ড্রয়ারে তিনশো টাকা কম। "কার কাছে জিজ্ঞেস করব?" শিফট ছাড়া
 * উত্তরটা "যে কেউ", আর যে প্রশ্নের উত্তর "যে কেউ" সেটা কেউ জিজ্ঞেস
 * করে না — ঘাটতিটা প্রতি সপ্তাহে ফিরে আসে।
 */
class CounterShiftTest extends TestCase
{
    use RefreshDatabase;

    private CashTill $till;

    private User $owner;

    private Product $product;

    private Warehouse $warehouse;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->till = app(CashTillService::class)->ensurePrimaryTill();
        $this->product = Product::query()->firstOrFail();
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->customer = Customer::query()->firstOrFail();
    }

    private function shifts(): ShiftService
    {
        return app(ShiftService::class);
    }

    private function sell(string $paid = '500'): void
    {
        app(PosService::class)->checkout(
            [
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'account_id' => $this->till->account_id,
                'paid' => $paid,
                'idempotency_key' => 'k'.uniqid(),
            ],
            [['product_id' => $this->product->id, 'qty' => '1', 'rate' => $paid]],
        );
    }

    // ── খোলা ─────────────────────────────────────────────────────

    public function test_opening_a_shift_records_who_and_how_much(): void
    {
        $shift = $this->shifts()->open($this->till, '1000');

        $this->assertSame(CounterShift::OPEN, $shift->status);
        $this->assertSame($this->owner->id, $shift->user_id);
        $this->assertSame(0, bccomp('1000', (string) $shift->opening_counted, 4));
    }

    /**
     * এক ড্রয়ারে দুইজন নয়।
     *
     * দুইজন দায়ী মানে কেউ দায়ী নয় — শিফট না থাকার সমান।
     */
    public function test_one_drawer_cannot_have_two_open_shifts(): void
    {
        $this->shifts()->open($this->till, '1000');

        $other = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->actingAs($other);

        $this->expectException(ValidationException::class);

        $this->shifts()->open($this->till, '500');
    }

    /**
     * ডাটাবেজই শেষ পাহারা — কোডের পরীক্ষা এড়িয়ে গেলেও।
     *
     * দুইজন একই মুহূর্তে চাপলে দুইজনেই দেখতেন কোনো খোলা শিফট নেই।
     * ইউনিক ইনডেক্স ছাড়া দুইটাই বসে যেত।
     */
    public function test_the_database_refuses_a_second_open_shift(): void
    {
        $this->shifts()->open($this->till, '1000');

        $this->expectException(QueryException::class);

        CounterShift::query()->create([
            'company_id' => CompanyContext::id(),
            'cash_till_id' => $this->till->id,
            'user_id' => $this->owner->id,
            'opened_at' => now(),
            'opening_counted' => '0',
            'status' => CounterShift::OPEN,
            'open_marker' => 1,
        ]);
    }

    /** একজন মানুষ একসাথে দুই ড্রয়ারে বসতে পারেন না। */
    public function test_a_person_cannot_hold_two_drawers(): void
    {
        $this->shifts()->open($this->till, '1000');

        $second = app(CashTillService::class)->create([
            'name_en' => 'Second drawer',
            'name_bn' => 'দ্বিতীয় ড্রয়ার',
        ]);

        $this->expectException(ValidationException::class);

        $this->shifts()->open($second, '500');
    }

    // ── বন্ধ ও Z-রিপোর্ট ─────────────────────────────────────────

    /**
     * খোলার টাকা + শিফটে ঢোকা টাকা = যা থাকার কথা।
     *
     * এটাই Z-রিপোর্টের পুরো অঙ্ক।
     */
    public function test_the_expected_drawer_is_opening_plus_what_came_in(): void
    {
        $shift = $this->shifts()->open($this->till, '1000');

        $this->sell('300');

        $figures = $this->shifts()->figures($shift->fresh());

        $this->assertSame(0, bccomp('300', $figures['cash_in'], 4), 'ঢোকা টাকা মেলেনি');
        $this->assertSame(0, bccomp('1300', $figures['expected'], 4), 'যা থাকার কথা মেলেনি');
    }

    /**
     * গোনা আর খাতার পার্থক্যই Z-রিপোর্টের আসল সংখ্যা।
     *
     * কম পড়লে ঋণাত্মক — লুকানো হয় না।
     */
    public function test_the_difference_is_counted_minus_expected(): void
    {
        $shift = $this->shifts()->open($this->till, '1000');
        $this->sell('300');

        $closed = $this->shifts()->close($shift->fresh(), '1250');
        $figures = $this->shifts()->figures($closed);

        $this->assertSame(0, bccomp('-50', $figures['difference'], 4));
    }

    /** মিলে গেলে পার্থক্য শূন্য — আর শূন্যই স্বাভাবিক অবস্থা। */
    public function test_a_drawer_that_matches_shows_no_difference(): void
    {
        $shift = $this->shifts()->open($this->till, '1000');
        $this->sell('300');

        $closed = $this->shifts()->close($shift->fresh(), '1300');

        $this->assertSame(0, bccomp('0', $this->shifts()->figures($closed)['difference'], 4));
    }

    /**
     * খোলা শিফটে গোনা সংখ্যাটা "অজানা", শূন্য নয়।
     *
     * শূন্য ধরলে চলমান প্রতিটা শিফট পুরো ড্রয়ারের সমান ঘাটতি দেখাত।
     */
    public function test_an_open_shift_has_no_counted_figure_yet(): void
    {
        $shift = $this->shifts()->open($this->till, '1000');

        $figures = $this->shifts()->figures($shift);

        $this->assertNull($figures['counted']);
        $this->assertNull($figures['difference']);
    }

    public function test_a_closed_shift_cannot_be_closed_again(): void
    {
        $shift = $this->shifts()->open($this->till, '1000');
        $this->shifts()->close($shift->fresh(), '1000');

        $this->expectException(ValidationException::class);

        $this->shifts()->close($shift->fresh(), '900');
    }

    /** বন্ধ করার পর ওই ড্রয়ারে আবার শিফট খোলা যায়। */
    public function test_closing_frees_the_drawer_for_the_next_person(): void
    {
        $first = $this->shifts()->open($this->till, '1000');
        $this->shifts()->close($first->fresh(), '1000');

        $next = $this->shifts()->open($this->till, '1000');

        $this->assertSame(CounterShift::OPEN, $next->status);
    }

    // ── সময়ের সীমানা ─────────────────────────────────────────────

    /**
     * আগের শিফটের বিক্রি এই শিফটে গোনা হয় না।
     *
     * ── কেন তারিখ নয়, সময় ─────────────────────────────────────────
     * খতিয়ানের `trx_date` একটা তারিখ, ঘড়ি নয়। তারিখ ধরে ভাগ করলে এক
     * দিনের দুই শিফট আলাদা করা যেত না, আর সকালের ঘাটতি বিকেলের লোকের
     * ঘাড়ে পড়ত।
     */
    public function test_an_earlier_shifts_sales_do_not_count_in_this_one(): void
    {
        $first = $this->shifts()->open($this->till, '1000');
        $this->sell('300');
        $this->shifts()->close($first->fresh(), '1300');

        $second = $this->shifts()->open($this->till, '1300');
        $this->sell('200');

        $figures = $this->shifts()->figures($second->fresh());

        $this->assertSame(0, bccomp('200', $figures['cash_in'], 4), 'আগের শিফটের বিক্রিও গোনা হয়েছে');
        $this->assertSame(0, bccomp('1500', $figures['expected'], 4));
    }

    /** খোলা শিফটটা খুঁজে পাওয়া যায় — পর্দার জন্য। */
    public function test_the_open_shift_can_be_found_for_the_screen(): void
    {
        $this->shifts()->open($this->till, '1000');

        $this->assertNotNull($this->shifts()->openFor($this->owner->id));
    }
}

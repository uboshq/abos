<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Sales\Models\CounterShift;
use App\Modules\Sales\Services\ShiftService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * শিফটের পর্দা — বোতাম চাপলে সত্যিই কিছু ঘটে কিনা।
 *
 * ShiftService-এর নিজের ১২টা টেস্ট আছে। এটা পাহারা দেয় *পথটা*: রুট,
 * অনুমতি, আর ফর্ম থেকে সার্ভিস পর্যন্ত যাওয়া। ইঞ্জিন ঠিক থেকেও রুট
 * না থাকলে সুবিধাটা লেখা থাকত অথচ ক্যাশিয়ার ব্যবহার করতে পারতেন না —
 * এই প্রকল্পে ঠিক সেটাই বারবার হয়েছে।
 */
class ShiftScreenTest extends TestCase
{
    use RefreshDatabase;

    private CashTill $till;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->till = app(CashTillService::class)->ensurePrimaryTill();
    }

    private function openShift(string $counted = '1000')
    {
        return $this->post(route('sales.shift.open'), [
            'cash_till_id' => $this->till->id,
            'opening_counted' => $counted,
        ]);
    }

    // ── খোলা ─────────────────────────────────────────────────────

    public function test_the_form_opens_a_shift(): void
    {
        $this->openShift()->assertRedirect(route('sales.shift.index'));

        $shift = CounterShift::query()->latest('id')->firstOrFail();

        $this->assertSame(CounterShift::OPEN, $shift->status);
        $this->assertSame($this->owner->id, $shift->user_id);
    }

    /** যে ড্রয়ারে কেউ বসে আছে সেটা বাছার তালিকাতেই আসে না। */
    public function test_a_busy_drawer_is_not_offered(): void
    {
        app(ShiftService::class)->open($this->till, '1000');

        $seen = [];

        View::composer('sales::shift.index', function ($view) use (&$seen) {
            $seen = $view->getData();
        });

        // অন্য একজন দেখলে তালিকাটা খালি — ড্রয়ারটা দখলে
        $other = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->actingAs($other)->get(route('sales.shift.index'))->assertOk();

        $this->assertFalse($seen['tills']->contains('id', $this->till->id));
    }

    // ── বন্ধ ─────────────────────────────────────────────────────

    public function test_the_form_closes_a_shift_and_shows_the_z_report(): void
    {
        $shift = app(ShiftService::class)->open($this->till, '1000');

        $this->post(route('sales.shift.close', ['shift' => $shift->id]), [
            'closing_counted' => '950',
        ])->assertRedirect(route('sales.shift.show', ['shift' => $shift->id]));

        $seen = [];

        View::composer('sales::shift.show', function ($view) use (&$seen) {
            $seen = $view->getData();
        });

        $this->get(route('sales.shift.show', ['shift' => $shift->id]))->assertOk();

        $this->assertSame(0, bccomp('-50', $seen['figures']['difference'], 4));
    }

    /**
     * অন্যের শিফট বন্ধ করা যায় না।
     *
     * পারলে গোনা সংখ্যাটা এমন কেউ বসাতেন যিনি টাকাটা গোনেনইনি, আর
     * দায়টা কার তা আবার ঘোলা হয়ে যেত।
     */
    public function test_nobody_closes_someone_elses_drawer(): void
    {
        $shift = app(ShiftService::class)->open($this->till, '1000');

        $other = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $this->actingAs($other)
            ->post(route('sales.shift.close', ['shift' => $shift->id]), ['closing_counted' => '0'])
            ->assertForbidden();
    }

    /** গোনা সংখ্যা ছাড়া বন্ধ করা যায় না — "এমনিই বন্ধ" বলে কিছু নেই। */
    public function test_closing_without_a_count_is_refused(): void
    {
        $shift = app(ShiftService::class)->open($this->till, '1000');

        $this->post(route('sales.shift.close', ['shift' => $shift->id]), [])
            ->assertSessionHasErrors('closing_counted');
    }

    // ── দেখা ও পাহারা ────────────────────────────────────────────

    /** নিজের Z-রিপোর্ট নিজে দেখা যায়। */
    public function test_a_cashier_sees_their_own_z_report(): void
    {
        $shift = app(ShiftService::class)->open($this->till, '1000');
        app(ShiftService::class)->close($shift->fresh(), '1000');

        $this->get(route('sales.shift.show', ['shift' => $shift->id]))->assertOk();
    }

    /**
     * অন্যের Z-রিপোর্ট দেখতে টিল দেখার অনুমতি লাগে।
     *
     * নাহলে এক ক্যাশিয়ার অন্যের ড্রয়ারের ঘাটতি দেখে ফেলতেন — যেটা
     * তাঁর জানার কথা নয়, আর কাউন্টারে ওই কথাটা ছড়ালে কাজের পরিবেশই
     * নষ্ট হয়।
     */
    public function test_another_cashier_cannot_read_it(): void
    {
        $shift = app(ShiftService::class)->open($this->till, '1000');
        app(ShiftService::class)->close($shift->fresh(), '1000');

        $other = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $this->actingAs($other)
            ->get(route('sales.shift.show', ['shift' => $shift->id]))
            ->assertForbidden();
    }

    /** কাউন্টারের অনুমতি ছাড়া পর্দাটাই খোলে না। */
    public function test_the_counter_permission_is_required(): void
    {
        $stranger = User::factory()->create();
        $stranger->companies()->attach(CompanyContext::id(), ['is_active' => true]);
        $stranger->forceFill(['current_company_id' => CompanyContext::id()])->save();

        $this->actingAs($stranger)->get(route('sales.shift.index'))->assertForbidden();
    }
}

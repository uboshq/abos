<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Sales\Models\CounterShift;
use App\Modules\Sales\Services\ShiftService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * একটা শিফট দুইবার বন্ধ হলে প্রথমজনের গোনা টাকাই থাকে — ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * [[ShiftService::close()]] "খোলা কি না" দেখত হাতে ধরা মডেলে, লেনদেনের বাইরে। দুইজন (বা একজন দুইবার চেপে) একই
 * শিফট বন্ধ করলে দুজনেই "খোলা" দেখতেন: দ্বিতীয়জনের গোনা টাকা প্রথমজনেরটা **মুছে** বসত, আর নিরীক্ষার খাতায়
 * "শিফট বন্ধ" দুইবার উঠত। ⚠️ ক্যাশের গরমিল ধরা হয় ঠিক এই গোনা থেকে — ভুল অঙ্ক মানে ভুল লোক দায়ী।
 *
 * ⓘ দুইবার চাপ = একই শিফটের দুইটা আলাদা মডেল, দুটোই খোলা অবস্থায় পড়া; সারাইয়ের পরে দ্বিতীয়টা লেনদেনের
 * ভেতরে তালা-পড়া সারি দেখে ফেরে।
 */
final class AShiftClosedTwiceKeepsTheFirstCountTest extends TestCase
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

    public function test_the_second_close_changes_nothing(): void
    {
        $till = app(CashTillService::class)->ensurePrimaryTill();
        $shift = app(ShiftService::class)->open($till, '1000');

        $first = $shift->fresh();
        $second = $shift->fresh();

        app(ShiftService::class)->close($first, '1000');

        try {
            app(ShiftService::class)->close($second, '900');
            $this->fail('দ্বিতীয় বন্ধ থামেনি — প্রথমজনের গোনা টাকা মুছে গেছে।');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('shift', $e->errors());
        }

        $this->assertSame(0, bccomp((string) $shift->fresh()->closing_counted, '1000', 4),
            '⛔ গোনা টাকা ১০০০ থাকার কথা, আছে '.$shift->fresh()->closing_counted.' — দ্বিতীয় বন্ধ ওটা বদলে দিয়েছে।');

        $closedRows = AuditTrail::query()
            ->where('auditable_type', $shift->getMorphClass())
            ->where('auditable_id', $shift->id)
            ->where('action', 'shift_closed')
            ->count();

        $this->assertSame(1, $closedRows, '⛔ "শিফট বন্ধ" নিরীক্ষায় '.$closedRows.' বার উঠেছে।');
        $this->assertSame(CounterShift::CLOSED, $shift->fresh()->status);
    }
}

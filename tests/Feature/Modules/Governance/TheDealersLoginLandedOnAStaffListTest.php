<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Governance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * "আমার সেশন" — লাইভ `SESSION_DRIVER=database`-এ।
 *
 * ⛔ যা ভাঙা ছিল: ফ্রেমওয়ার্ক `sessions.user_id`-এ ডিফল্ট গার্ডের id বসায়, আর পোর্টালে ডিফল্ট গার্ড গ্রাহকের — তাই ডিলারের
 * পোর্টাল-সেশনে বসত গ্রাহকের id; ঐ সংখ্যার কর্মী "আমার সেশন"-এ ডিলারের যন্ত্র আর আইপি দেখতেন, আর "অন্য সব জায়গা থেকে বের
 * করুন" চেপে ডিলারকে বের করে দিতেন। ⓘ লাইভে আগে থেকেই `database` — চলমান ছিদ্র (fe, ১৮ অক্টোবর ২০২৬)।
 * ⭐ মালিকের সিদ্ধান্ত ১৬; OWASP ASVS 4.0 V3.3.4। ec, ১৭–১৮ অক্টোবর ২০২৬ ([[StaffSessionHandler]])।
 */
final class TheDealersLoginLandedOnAStaffListTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    public function test_a_staff_request_writes_its_session_under_that_staff_member(): void
    {
        config(['session.driver' => 'database']);
        DB::table('sessions')->delete();

        $sales = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $this->actingAs($sales)->get(route('governance.session.index'))->assertOk();

        $written = DB::table('sessions')->get();
        $this->assertNotEmpty($written, 'database চালকে অনুরোধের সেশন-সারিই লেখা হয়নি।');
        $this->assertSame([(int) $sales->id], $written->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
            '⛔ সেশন-সারিতে ভুল মানুষের id।');
    }

    public function test_a_dealers_portal_session_is_never_written_under_a_staff_id(): void
    {
        config(['session.driver' => 'database']);

        $dealer = Customer::query()->where('name_en', 'Rahim Traders')->firstOrFail();
        $dealer->forceFill(['portal_password' => Hash::make('dealer-pass-16'), 'portal_enabled' => true])->save();
        DB::table('sessions')->delete();

        $this->actingAs($dealer->fresh(), 'portal')
            ->get(route('sales.portal.ledger', ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()]))
            ->assertOk();

        $rows = DB::table('sessions')->get();
        $this->assertNotEmpty($rows, 'পূর্বশর্ত: পোর্টালের অনুরোধ সেশন-সারি লেখেনি।');
        $this->assertSame([], $rows->pluck('user_id')->filter()->values()->all(),
            '⛔ ডিলারের পোর্টাল-সেশন একজন কর্মীর id-তে বসেছে (গ্রাহকের id = '.$dealer->id.')।');
    }
}

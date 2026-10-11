<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Executive;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\Company;
use App\Models\User;
use App\Modules\Executive\Services\Alerts;
use App\Modules\Executive\Services\Board;
use App\Modules\Executive\Services\CompanyLens;
use App\Modules\Executive\Services\Figures;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * "সইয়ের অপেক্ষায়" — মালিকের কেন্দ্র অনুমোদন পাতার চেয়ে বেশি দেখায় না (১১ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কী হতে পারত ───────────────────────────────────────────────────
 * ৯ অক্টোবরের পুনঃনিরীক্ষায় অনুমোদন পাতা বন্ধ করল: রিপোর্টের চাবি (`approval.report`) ছাড়া মানুষ কেবল
 * নিজের অনুরোধ আর নিজের সারি দেখেন। মালিকের কেন্দ্রের তালিকা তখনো নিজে `Approval::query()` পড়ত —
 * অর্থাৎ `executive.view` পাওয়া একজন ব্যবস্থাপক এই পাতা দিয়ে গোটা কোম্পানির আটকে-থাকা কাগজ দেখতেন।
 * ⭐ এখন দুইটাই [[ApprovalDashboard::pendingSeen()]] পড়ে: সংখ্যা আর তালিকা, পাতার হুবহু চোখে।
 */
final class TheWaitingListSeesWhatTheApprovalPageSeesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        config(['abos.dashboards_v2' => true]);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, null);
        Approval::query()->delete();
    }

    public function test_without_the_reporting_key_only_ones_own_papers_are_waiting(): void
    {
        $manager = $this->member(['executive.view', 'approval.view']);
        $other = $this->member(['approval.view']);

        $this->request($manager, 1, '100');
        $this->request($other, 2, '999999');
        $this->request($other, 3, '888888');

        [$waiting, $count] = $this->seenBy($manager);

        $this->assertSame(['100.0000'], $waiting, '⛔ মালিকের কেন্দ্র দিয়ে অন্যের অপেক্ষমাণ কাগজ দেখা গেল — অনুমোদন পাতা যা দেখায় না।');
        $this->assertSame('1', $count, '⛔ "সইয়ের অপেক্ষায়" সংখ্যা অনুমোদন পাতার সংখ্যা থেকে আলাদা।');
    }

    public function test_the_reporting_key_sees_the_whole_company_here_too(): void
    {
        $reporter = $this->member(['executive.view', 'approval.view', 'approval.report']);
        $other = $this->member(['approval.view']);

        $this->request($other, 1, '100');
        $this->request($other, 2, '200');

        [$waiting, $count] = $this->seenBy($reporter);

        $this->assertEqualsCanonicalizing(['100.0000', '200.0000'], $waiting, 'রিপোর্টের চাবি থাকলেও গোটা কোম্পানি নেই — দাবি অন্ধ।');
        $this->assertSame('2', $count);
    }

    /**
     * @return array{0: list<string>, 1: string}
     */
    private function seenBy(User $who): array
    {
        $who = $who->fresh();
        $this->actingAs($who);
        CompanyContext::set($this->company->id, null);
        app(DataScope::class)->forget();

        $companies = app(CompanyLens::class)->companies($who);
        $this->assertSame([(int) $this->company->id], array_column($companies, 'id'), 'প্রস্তুতিটাই ভুল — কোম্পানিটা তালিকায় নেই।');

        $waiting = array_column(app(Alerts::class)->waiting($who, $companies), 'amount');
        $board = app(Board::class)->build($who, Figures::TODAY);

        // ⓘ দলের যোগফল দশমিকে আসে (১.০০০০) — গোনা সংখ্যাটাই মেলানো হয়
        return [$waiting, bcadd((string) $board['total'][Figures::SIGNATURES], '0', 0)];
    }

    private function request(User $by, int $id, string $amount): Approval
    {
        return Approval::query()->create([
            'company_id' => $this->company->id, 'approvable_type' => 'test', 'approvable_id' => $id,
            'module' => 'sales', 'action' => 'discount', 'amount' => $amount, 'status' => Approval::PENDING,
            'current_level' => 1, 'requested_by' => $by->id, 'requested_at' => now(),
        ]);
    }

    /** @param list<string> $keys */
    private function member(array $keys): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id, 'current_branch_id' => null, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);

        CompanyContext::forCompany($this->company->id, function () use ($user, $keys): void {
            foreach ($keys as $key) {
                $user->givePermissionTo(Permission::findOrCreate($key, 'web'));
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}

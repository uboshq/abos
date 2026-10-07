<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Governance;

use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * নিয়ন্ত্রণ ও নিরীক্ষার ড্যাশবোর্ড ভুল চাবি দেখত, আর তিনটা তালিকা ভুল তারিখে ভাঙত।
 *
 * ── ⛔ কী দেখা গিয়েছিল, ৩০ সেপ্টেম্বর ২০২৬ (চূড়ান্ত অডিট) ───────────────
 * ১৮ সেপ্টেম্বরের নিরীক্ষায় একটা চাবি চারটায় ভাগ হয়েছিল — নিরীক্ষা, লগইন,
 * রপ্তানি, ত্রুটি। মেনু আর কন্ট্রোলার বদলেছিল, ড্যাশবোর্ড বদলায়নি:
 *
 * - ⛔ লগইন-ইতিহাস আর রপ্তানি-খাতার টাইল `governance.audit.view` চাইত। নিরীক্ষকের
 *   (Auditor) লগইনের চাবি নেই, অথচ টাইল দেখতেন, চাপলে ৪০৩।
 * - ⛔ রপ্তানির **সংখ্যাটা** কোনো চাবিই চাইত না — রপ্তানির চাবি ছাড়াও সবাই দেখতেন।
 *
 * ── ⛔ আর তারিখ ────────────────────────────────────────────────────────
 * তিনটা তালিকায় `?from=` কাঁচা `Carbon::parse()`-এ যেত। পুরনো বুকমার্ক বা হাতে
 * বদলানো ঠিকানায় (`?from=xyz`) পাতা ৫০০ দিত — আর সেই ৫০০ নিজেই গিয়ে বসত এই
 * মডিউলেরই ত্রুটির খাতায়।
 *
 * ── ⭐ কীভাবে মাপা ──────────────────────────────────────────────────────
 * একই মানুষ দুইবার: চাবি ছাড়া দরজা নেই, একই চাবি দিলে দরজা আসে। ⚠️ মালিক
 * (super_admin) সব দেখেন — এই সারাই যেন তাঁকে না আটকায়, সেটাও মাপা।
 */
final class TheGovernanceTilesAskedTheWrongKeyTest extends TestCase
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

    public function test_the_login_tile_asks_for_the_login_key(): void
    {
        $login = route('governance.login.index');

        /* ⓘ নিরীক্ষকের টেমপ্লেট: নিরীক্ষা + রপ্তানি, লগইন নয় */
        $auditor = $this->userWith(['governance.audit.view', 'governance.export.view']);

        $this->actingAs($auditor)->get($this->dashboard())->assertOk()
            ->assertDontSee('href="'.$login.'"', false);

        $auditor->givePermissionTo('governance.login.view');

        $this->actingAs($auditor->fresh())->get($this->dashboard())->assertOk()
            ->assertSee('href="'.$login.'"', false);
        $this->actingAs($auditor->fresh())->get($login)->assertOk();
    }

    public function test_the_export_tile_and_count_ask_for_the_export_key(): void
    {
        $exports = route('governance.export.index');

        $watcher = $this->userWith(['governance.audit.view', 'governance.login.view']);

        /*
         * ⛔ টাইল আর সংখ্যা — দুটোই রপ্তানির পাতায় যায়, তাই লিংকটাই মাপ।
         * ⓘ চাবি না থাকলে সংখ্যাটা মুছে যায় না, ঢাকা পড়ে ([[Stat::masked()]]) —
         * নাম থাকে, মান আর লিংক যায়। তাই নাম নয়, লিংক গোনা হয়।
         */
        $this->actingAs($watcher)->get($this->dashboard())->assertOk()
            ->assertDontSee('href="'.$exports.'"', false);

        $watcher->givePermissionTo('governance.export.view');

        $this->actingAs($watcher->fresh())->get($this->dashboard())->assertOk()
            ->assertSee('href="'.$exports.'"', false);
    }

    public function test_the_owner_still_sees_every_tile(): void
    {
        $this->actingAs($this->owner())->get($this->dashboard())->assertOk()
            ->assertSee('href="'.route('governance.audit.index').'"', false)
            ->assertSee('href="'.route('governance.login.index').'"', false)
            ->assertSee('href="'.route('governance.export.index').'"', false);
    }

    public function test_a_date_that_cannot_be_read_does_not_break_the_three_lists(): void
    {
        $owner = $this->owner();

        foreach (['governance.audit.index', 'governance.export.index', 'governance.login.index'] as $route) {
            foreach (['xyz', '31-31-2026'] as $bad) {
                $this->actingAs($owner)->get(route($route, ['from' => $bad, 'to' => $bad]))
                    ->assertOk();
            }

            /* ⚠️ অ্যারে পাঠালেও — `?from[]=x` */
            $this->actingAs($owner)->get(route($route).'?from[]=x&to[]=y')->assertOk();
        }
    }

    public function test_the_date_field_itself_survives_a_value_it_cannot_read(): void
    {
        /*
         * ⓘ তিনটা তালিকা এখন পড়া-যাওয়া তারিখই ঘরে ফেরত পাঠায়, তাই ওপরের দাবিটা
         * ঘরটার নিজের সুরক্ষা আর মাপে না — মিউট্যান্টটা বেঁচে গিয়েছিল। ⚠️ কিন্তু
         * ঘরটা আরও বহু পর্দায় কাঁচা মান পায় (`old()`, `request()`), তাই ঘরটাকে
         * সরাসরি মাপা হয়।
         */
        $html = Blade::render('<x-ui.date name="from" value="xyz" />');

        $this->assertStringContainsString('name="from"', $html);
        $this->assertStringNotContainsString('value="xyz"', $html);
    }

    public function test_a_readable_date_still_filters(): void
    {
        /*
         * ⚠️ ভুল তারিখ গিলে ফেলা সহজ — ছাঁকনিটাই মুছে দিলে পাতা আর ভাঙে না।
         * ⭐ তাই ঠিক তারিখে ছাঁকনি যে এখনো কাজ করে, সেটাও মাপা।
         */
        $owner = $this->owner();

        foreach (['2026-01-10' => 'PRB-OLD', '2026-03-10' => 'PRB-NEW'] as $day => $no) {
            $trail = AuditTrail::query()->create([
                'company_id' => $this->company->id,
                'branch_id' => $this->company->defaultBranch()?->id,
                'user_id' => $owner->id,
                'action' => 'updated',
                'auditable_type' => User::class,
                'auditable_id' => $owner->id,
                'document_no' => $no,
                'label' => 'Probe '.$no,
            ]);
            AuditTrail::query()->whereKey($trail->id)->toBase()->update(['created_at' => $day.' 12:00:00']);
        }

        $this->actingAs($owner)->get(route('governance.audit.index', ['from' => '2026-03-01', 'to' => '2026-03-31']))
            ->assertOk()
            ->assertSee('PRB-NEW')
            ->assertDontSee('PRB-OLD');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    private function dashboard(): string
    {
        return route('module.dashboard', ['module' => 'governance']);
    }

    private function owner(): User
    {
        return User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    /** @param  list<string>  $keys */
    private function userWith(array $keys): User
    {
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        $user->givePermissionTo($keys);

        return $user;
    }
}

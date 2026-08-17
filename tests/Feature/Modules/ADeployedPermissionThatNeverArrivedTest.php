<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Integrity\IntegrityFinding;
use App\Core\Integrity\IntegrityRegistry;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ডিপ্লয় হয়েছে, অথচ কেউ ব্যবহার করতে পারছেন না।
 *
 * ── কী ঘটেছিল ───────────────────────────────────────────────────────
 * শিপমেন্টের পর্দা চালু সাইটে ডিপ্লয় হলো, আর খুলতে গিয়ে "Forbidden"।
 * `abos:sync-permissions` চালানোর পর দেখা গেল ছয়টা অনুমতি অনুপস্থিত —
 * তার দুইটা কয়েক দিন আগের কাজের (`sales.cost.view`,
 * `sales.reprint.override`)। অর্থাৎ ওই দুইটা সুবিধা চালু সাইটে কখনো
 * কাজই করেনি, আর কোথাও কোনো লাল দাগ ছিল না।
 *
 * ── কেন সাধারণ টেস্টে ধরা পড়ে না ────────────────────────────────────
 * টেস্টে সিডার প্রতিবার সব অনুমতি বসিয়ে দেয়, তাই ওই অবস্থাটা তৈরিই
 * হয় না। এখানে তাই অবস্থাটা **হাতে বানানো** হয় — একটা অনুমতি তুলে
 * নিয়ে দেখা হয় যাচাইটা সত্যিই ধরে কি না। না ধরলে যাচাইটা কেবল একটা
 * সবুজ সারি, যা সবসময় সবুজ।
 */
class ADeployedPermissionThatNeverArrivedTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    /** @return list<IntegrityFinding> */
    private function findings(): array
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return app(IntegrityRegistry::class)->all()['governance.permissions_are_installed']->run();
    }

    /** সব বসানো থাকলে যাচাইটা চুপ থাকে। */
    public function test_a_fully_synced_database_is_silent(): void
    {
        $this->assertSame([], $this->findings(),
            'সব অনুমতি বসানো, তবু যাচাইটা কিছু একটা ধরেছে।');
    }

    /**
     * একটা অনুমতি না থাকলে যাচাইটা তার নাম বলে।
     *
     * নাম না বললে সারিটা পড়ে কেউ জানতেন না কোনটা চালাতে হবে, আর
     * "কিছু একটা ভাঙা" পড়ে কেউ কিছু করেন না।
     */
    public function test_a_missing_permission_is_named(): void
    {
        Permission::query()->where('name', 'sales.shipment.view')->delete();

        $findings = $this->findings();

        $this->assertNotEmpty($findings, 'একটা অনুমতি মুছে ফেলার পরেও যাচাইটা সবুজ — অর্থাৎ এটা কিছুই দেখে না।');

        $this->assertContains('sales.shipment.view', array_map(fn ($f) => $f->what, $findings));
    }

    /**
     * বসানো আছে অথচ মালিকের রোলে নেই — এটাও ধরা পড়ে।
     *
     * টেবিলে সারিটা থাকা মানে কেউ ওটা ব্যবহার করতে পারছেন তা নয়।
     */
    public function test_a_permission_the_owner_does_not_hold_is_caught(): void
    {
        $owner = Role::query()->where('name', PermissionSyncer::OWNER_ROLE)->firstOrFail();
        $owner->revokePermissionTo('sales.shipment.create');

        $findings = $this->findings();

        $this->assertContains('sales.shipment.create', array_map(fn ($f) => $f->what, $findings),
            'অনুমতিটা মালিকের রোলে নেই, তবু যাচাইটা কিছু বলেনি — পর্দাটা কার্যত বন্ধ।');
    }

    /**
     * সারানোর পর সবুজ।
     *
     * যে যাচাই সারানোর পরেও লাল থাকে, মানুষ দুই দিনে তার দিকে তাকানো
     * বন্ধ করে দেয়।
     */
    public function test_running_the_sync_makes_it_green_again(): void
    {
        Permission::query()->where('name', 'sales.shipment.view')->delete();

        $this->assertNotEmpty($this->findings());

        app(PermissionSyncer::class)->sync();

        $this->assertSame([], $this->findings(), 'সারানোর পরেও যাচাইটা লাল রয়ে গেছে।');
    }

    /** পর্দাটাও যাচাইটা দেখায় — বস্তুতে থেকে লাভ নেই। */
    public function test_the_screen_offers_the_check(): void
    {
        $this->get(route('accounts.integrity'))
            ->assertOk()
            ->assertSee(__('governance::integrity.permissions'));
    }
}

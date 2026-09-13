<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\CompanyProvisioner;
use App\Core\Services\Ownership;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * মালিক একজনই — আর একজনও বটে।
 *
 * ── কী ভাঙা ছিল, ১৩ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
 * ⛔ কোনো পাহারা ছিল না। একজন owner নিজের `is_active` চেকবক্সটা তুলে
 * দিলে, বা নিজের রোলটা বদলে ফেললে, **ভেতর থেকে আর কেউ কোনোদিন ঢুকতে
 * পারতেন না** — ফেরার একমাত্র পথ হত SSH আর `tinker`, যেটা মালিক
 * পারবেন না। একটা চেকবক্স, আর পুরো ব্যবস্থাটা বন্ধ।
 *
 * ⓘ উল্টো দিকে, দ্বিতীয় একজনকে owner বানানোও খোলা ছিল — আর ঐ রোলটা
 * প্রতিটা অনুমতি পায়: ভূমিকা বদলানো, ব্যবহারকারী নিষ্ক্রিয় করা, মাস
 * খোলা, সব।
 *
 * ── তিনটা নিয়ম একসাথে, আর কেন তিনটাই লাগে ───────────────────────────
 * ⓵ শেষ owner সরানো যায় না · ⓶ দ্বিতীয় owner বানানো যায় না ·
 * ⓷ owner **হস্তান্তর** করা যায়।
 *
 * ⚠️ প্রথম দুইটা একা লিখলে ওগুলো নিরাপত্তা নয়, কবর হত: owner চলে গেলে
 * বা অ্যাকাউন্ট হারালে নতুন কাউকে বসানোর কোনো পথই থাকত না। তাই
 * হস্তান্তরটা সুবিধা নয়, **তালাটার শর্ত**।
 */
class TheLastOwnerCouldLockEveryoneOutTest extends TestCase
{
    use RefreshDatabase;

    private Company $alpha;

    private User $owner;

    /** যিনি ব্যবহারকারী সামলাতে পারেন, কিন্তু owner নন। */
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->alpha = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->alpha->id, $this->alpha->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        /*
         * ── কেন একজন **আলাদা** প্রশাসক লাগে ──────────────────────────
         * owner নিজে নিজের সারি সম্পাদনা করলে আগে থেকেই বসানো
         * `assertNotLockingThemselvesOut()` আটকে দেয় — অর্থাৎ পরীক্ষাটা
         * তখন **নতুন পাহারাটা** মাপত না, পুরনোটা মাপত, আর সবুজ দেখে
         * আমরা ভুল জিনিসের উপর আস্থা রাখতাম।
         *
         * ⓘ তাই বাইরের একজন, যাঁর ব্যবহারকারী-ব্যবস্থাপনার অধিকার আছে
         * কিন্তু owner রোল নেই।
         */
        $this->admin = User::factory()->create([
            'email' => 'useradmin@abos.test',
            'is_active' => true,
        ]);

        $this->admin->companies()->attach($this->alpha->id, ['is_active' => true]);
        $this->admin->switchCompany($this->alpha->id);

        CompanyContext::forCompany($this->alpha->id, function () {
            Role::findOrCreate('user_admin')->givePermissionTo('system_admin.user.manage');
            $this->admin->assignRole('user_admin');
        });
    }

    private function ownership(): Ownership
    {
        return app(Ownership::class);
    }

    /**
     * ⭐ পাহারাটা আদৌ কাউকে দেখতে পায় তো?
     *
     * ── কেন এই পরীক্ষাটা সবার আগে ────────────────────────────────────
     * নিচের প্রায় প্রতিটা দাবি "এই কাজটা প্রত্যাখ্যাত হলো" মাপে। ⛔ আর
     * ঠিক ঐ ধরনের দাবি **গণনাটা ভুল হলেও** সবুজ থাকতে পারে: `owner`
     * রোলের নাম বদলে গেলে, `model_has_roles`-এর কলাম বদলালে, বা
     * কোম্পানির ছাঁকনি ভাঙলে গণনা শূন্য হত, আর তখন "শেষজনকে সরানো
     * যাবে না" নিয়মটা **প্রতিবার** চালু হয়ে দিব্যি লাল-সবুজ খেলত।
     *
     * ⚠️ এই রিপোতে এক দিনে পাঁচটা পাহারা পাওয়া গেছে যারা সবুজ ছিল আর
     * কিছুই দেখছিল না। তাই পাহারাকে আগে নিজের দৃষ্টি প্রমাণ করতে হয়।
     */
    public function test_the_guard_can_actually_see_the_owner(): void
    {
        $owners = $this->ownership()->activeOwnersIn($this->alpha->id);

        $this->assertCount(1, $owners,
            'এই কোম্পানিতে ঠিক একজন owner থাকার কথা — গণনাটাই ভেঙেছে কি না দেখুন।');

        $this->assertSame($this->owner->id, $owners->first()?->id);
        $this->assertTrue($this->ownership()->isOwnerIn($this->owner, $this->alpha->id));
        $this->assertFalse($this->ownership()->isOwnerIn($this->admin, $this->alpha->id));
    }

    /** ⓶ দ্বিতীয় owner — পর্দার পথ দিয়ে। */
    public function test_a_second_owner_is_refused_at_the_screen(): void
    {
        $this->actingAs($this->admin)
            ->post(route('system_admin.user.store'), [
                'name' => 'Karim Two',
                'email' => 'karim@abos.test',
                'password' => 'a-long-enough-secret-9',
                'locale' => 'bn',
                'is_active' => '1',
                'roles' => [PermissionSyncer::SUPER_ADMIN_ROLE],
                'companies' => [$this->alpha->id],
                'default_branch' => [$this->alpha->id => $this->alpha->defaultBranch()?->id],
            ])
            ->assertSessionHasErrors('roles');

        $this->assertNull(User::query()->where('email', 'karim@abos.test')->first(),
            'প্রত্যাখ্যাত হওয়ার পরেও মানুষটা তৈরি হয়ে গেছে।');

        $this->assertCount(1, $this->ownership()->activeOwnersIn($this->alpha->id));
    }

    /** ⓵ শেষ owner-কে নিষ্ক্রিয় করা। */
    public function test_the_last_owner_cannot_be_deactivated(): void
    {
        $this->actingAs($this->admin)
            ->put(route('system_admin.user.update', $this->owner), $this->ownerForm(['is_active' => '0']))
            ->assertSessionHasErrors('roles');

        $this->assertTrue($this->owner->fresh()?->is_active,
            'শেষ owner নিষ্ক্রিয় হয়ে গেছেন — তাহলে আর কেউ ঢুকতে পারবেন না।');
    }

    /** ⓵ শেষ owner-এর রোল কেড়ে নেওয়া। */
    public function test_the_last_owner_cannot_lose_the_role(): void
    {
        $this->actingAs($this->admin)
            ->put(route('system_admin.user.update', $this->owner), $this->ownerForm(['roles' => ['salesman']]))
            ->assertSessionHasErrors('roles');

        $this->assertTrue($this->ownership()->isOwnerIn($this->owner->fresh(), $this->alpha->id));
    }

    /**
     * ⓵ নিয়মটা "owner ছোঁয়া যাবে না" নয় — "**শেষজন** সরানো যাবে না"।
     *
     * ⓘ পার্থক্যটা না মাপলে একটা অতিরিক্ত-কড়া পাহারাও সবুজ থাকত, আর
     * হস্তান্তরের পরে পুরনো owner-কে নামানোই যেত না।
     */
    public function test_an_owner_can_step_down_when_another_one_remains(): void
    {
        $second = $this->secondOwnerPlantedDirectly();

        $this->assertCount(2, $this->ownership()->activeOwnersIn($this->alpha->id));

        $this->ownership()->assertCompanyKeepsAnOwner(
            $second, $this->alpha->id, keepsRole: false, staysActive: true,
        );

        $this->addToAssertionCount(1);
    }

    /**
     * ⓷ চাবিটা হাতবদল হয় — আর কখনো দুইজন হয় না।
     */
    public function test_transfer_moves_the_key_without_ever_making_two(): void
    {
        $this->ownership()->transfer($this->owner, $this->admin, $this->alpha->id);

        $owners = $this->ownership()->activeOwnersIn($this->alpha->id);

        $this->assertCount(1, $owners, 'হস্তান্তরের পর owner একজনই থাকার কথা।');
        $this->assertSame($this->admin->id, $owners->first()?->id);
        $this->assertFalse($this->ownership()->isOwnerIn($this->owner->fresh(), $this->alpha->id));

        /*
         * ⚠️ `withoutGlobalScopes()` — `AuditTrail` কোম্পানি ধরে ছাঁকা হয়,
         * আর দাবিটা "সারিটা আছে", "এখান থেকে দেখা যাচ্ছে" নয়।
         */
        $trail = AuditTrail::query()
            ->withoutGlobalScopes()
            ->where('auditable_type', User::class)
            ->where('auditable_id', $this->admin->id)
            ->where('action', 'ownership_transferred')
            ->first();

        $this->assertNotNull($trail, 'হস্তান্তরটা খাতায় ওঠেনি — নিরীক্ষার প্রথম প্রশ্নটারই উত্তর থাকত না।');
        $this->assertStringContainsString($this->owner->email, (string) $trail->reason,
            'কার কাছ থেকে গেল, সেটা কারণের লেখায় থাকা দরকার।');
    }

    /**
     * ⓷ ⛔ নিষ্ক্রিয় কারো হাতে চাবি যায় না।
     *
     * ── কেন এই দাবিটা আলাদা করে লাগে ─────────────────────────────────
     * `activeOwnersIn()` গণনা করে `users.is_active` ধরে। ⚠️ তাই নিষ্ক্রিয়
     * কাউকে হস্তান্তর করলে পুরনো মালিকের রোল চলে যেত আর নতুনজন গণনায়
     * আসতেন না — অর্থাৎ কোম্পানিতে **একজনও সক্রিয় মালিক থাকত না**, ঠিক
     * সেই তালাবদ্ধ অবস্থা যেটা ঠেকাতে এই পুরো শ্রেণিটা লেখা।
     *
     * ⓘ পাতাটা কেবল সক্রিয় প্রার্থী দেখায়, তাই ক্লিক করে ওখানে পৌঁছানো
     * যায় না। কিন্তু পথটা **সময়ের**: পাতা খোলা রেখে কেউ মাঝখানে
     * মানুষটিকে নিষ্ক্রিয় করলেই যথেষ্ট।
     *
     * ⭐ দাবিটা "ব্যতিক্রম এসেছে" নয় — **গুনে দেখা** যে মালিক এখনো একজন
     * আছেন, আর তিনি পুরনোজনই। নইলে একটা পাহারা যেটা প্রত্যাখ্যান করে
     * অথচ রোলটা ইতিমধ্যেই সরিয়ে ফেলেছে, সেটাও সবুজ থাকত।
     */
    public function test_the_key_is_not_handed_to_a_deactivated_account(): void
    {
        $this->admin->update(['is_active' => false]);

        try {
            $this->ownership()->transfer($this->owner, $this->admin->fresh(), $this->alpha->id);
            $this->fail('নিষ্ক্রিয় অ্যাকাউন্টে হস্তান্তর প্রত্যাখ্যাত হয়নি।');
        } catch (ValidationException) {
            // প্রত্যাশিত — আসল দাবি নিচে
        }

        $owners = $this->ownership()->activeOwnersIn($this->alpha->id);

        $this->assertCount(1, $owners,
            'হস্তান্তর প্রত্যাখ্যাত হয়েছে, তবু কোম্পানিতে সক্রিয় মালিকের সংখ্যা ১ নয় — '
            .'অর্থাৎ রোলটা সরে গেছে আর কেউ ঢুকতে পারবেন না।');

        $this->assertSame($this->owner->id, $owners->first()?->id,
            'চাবিটা পুরনো মালিকের হাতেই থাকার কথা।');
    }

    /** ⓷ নিজের কাছে হস্তান্তর অর্থহীন, তাই প্রত্যাখ্যাত। */
    public function test_transfer_to_self_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->ownership()->transfer($this->owner, $this->owner, $this->alpha->id);
    }

    /**
     * গণনাটা কোম্পানি ধরে — আর নতুন কোম্পানির প্রথম owner নির্বিঘ্নে বসেন।
     *
     * ⭐ দুইটা প্রশ্ন একসাথে: আলফায় একজন owner থাকা সত্ত্বেও **নতুন**
     * কোম্পানিতে আরেকজন বসতে পারেন (নইলে কেউ দ্বিতীয় কোম্পানিই খুলতে
     * পারতেন না), আর `CompanyProvisioner`-এর পথটা অক্ষত আছে — ওটাই
     * প্রথম সেটআপেরও পথ।
     */
    public function test_a_brand_new_company_may_have_its_own_first_owner(): void
    {
        $company = Company::query()->create([
            'code' => 'NEWCO',
            'name_en' => 'New Co',
            'name_bn' => 'নিউ কোং',
            'currency' => 'BDT',
            'locale' => 'bn',
        ]);

        app(CompanyProvisioner::class)->grantAccess($company, $this->admin);

        $this->assertTrue($this->ownership()->isOwnerIn($this->admin->fresh(), $company->id));
        $this->assertCount(1, $this->ownership()->activeOwnersIn($company->id));

        // আর আলফা যেমন ছিল তেমনই — এক কোম্পানির হিসাব অন্যটায় গোনা হয় না
        $this->assertCount(1, $this->ownership()->activeOwnersIn($this->alpha->id));
        $this->assertSame($this->owner->id,
            $this->ownership()->activeOwnersIn($this->alpha->id)->first()?->id);
    }

    /**
     * মোছার পথটা আজ নেই, তবু নিয়মটা বসানো।
     *
     * ⓘ প্রশ্নটা ইচ্ছাকৃতভাবে "চলতি কোম্পানিতে কী হবে" নয় — মোছা সব
     * কোম্পানি থেকে একসাথে সরায়। তাই প্রসঙ্গ মুছে দিয়েই পরীক্ষা করা
     * হয়, যেমনটা কনসোল বা সিডার থেকে হত।
     */
    public function test_the_last_owner_cannot_be_deleted_even_without_a_company_context(): void
    {
        CompanyContext::clear();

        $this->expectException(ValidationException::class);

        $this->owner->delete();
    }

    /**
     * পাহারাটা দ্বিতীয়জনকে **হাতে** বসিয়ে দেখা।
     *
     * ⓘ পর্দার পথ দিয়ে দ্বিতীয় owner বানানো এখন অসম্ভব (সেটাই তো নিয়ম),
     * তাই "শেষজন নন" অবস্থাটা বানাতে রোলটা সরাসরি বসাতে হয়।
     */
    private function secondOwnerPlantedDirectly(): User
    {
        CompanyContext::forCompany($this->alpha->id, function () {
            $this->admin->assignRole($this->ownership()->role());
        });

        return $this->admin->fresh();
    }

    /**
     * owner-এর সারিটা যেমন আছে তেমন, কেবল যেটুকু বদলাতে চাই সেটুকু বাদে।
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function ownerForm(array $overrides = []): array
    {
        return array_merge([
            'name' => $this->owner->name,
            'email' => $this->owner->email,
            'locale' => 'bn',
            'is_active' => '1',
            'roles' => [$this->ownership()->role()],
            'companies' => [$this->alpha->id],
            'default_branch' => [$this->alpha->id => $this->alpha->defaultBranch()?->id],
        ], $overrides);
    }
}

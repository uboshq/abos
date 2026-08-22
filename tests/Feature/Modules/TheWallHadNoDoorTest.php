<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\UserDataScope;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * দেয়ালটা তোলা ছিল, দরজাটা ছিল না।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * ভাগ চ-এর RLS পুরোটাই কাজ করত: `UserDataScope` সারি বসালে
 * `ScopedToUserBranch` ঠিকঠাক ছেঁকে দিত, আর টেস্টেও সেটা প্রমাণিত।
 *
 * কিন্তু সারিটা বসানোর কোনো পর্দা ছিল না — একটাও রুট নয়, একটাও ঘর নয়।
 * নেত্রকোনার প্রতিনিধিকে ঢাকার বিল থেকে আটকাতে হলে ডাটাবেজে হাতে
 * INSERT করা ছাড়া উপায় ছিল না।
 *
 * ফলে বাস্তবে প্রতিটা ব্যবহারকারী সবকিছু দেখতেন, আর ফিচারটার অস্তিত্ব
 * কেবল কোডে ছিল।
 *
 * ── এই ফাইলের সবচেয়ে জরুরি পরীক্ষা ──────────────────────────────────
 * শেষেরটা: কিছু না বেছে সেভ করলে সীমাটা **উঠে যায়**।
 *
 * সীমা বসানোর চেয়ে সীমা তোলা বেশি জরুরি। ভুল করে কাউকে একটা শাখায়
 * বেঁধে ফেললে তিনি নিজের অর্ধেক কাজ দেখতে পান না, আর ফেরার পথ না
 * থাকলে ঠিক করার একমাত্র উপায় আবার সেই ডাটাবেজ।
 */
class TheWallHadNoDoorTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $main;

    private Branch $netrokona;

    private User $owner;

    private User $salesman;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $this->main = $this->company->defaultBranch();
        $this->netrokona = Branch::query()
            ->where('company_id', $this->company->id)
            ->where('id', '!=', $this->main->id)
            ->firstOrFail();
    }

    /**
     * ব্যবহারকারীর পর্দায় শাখাগুলো বাছাই করার ঘর আছে।
     *
     * ঠিক এই পরীক্ষাটাই আগে ছিল না। ছাঁকনির টেস্টগুলো সব পাশ করত,
     * কারণ ওগুলো সারিটা নিজেই বসিয়ে নিত — অর্থাৎ সারিটা বসানোর পথ
     * আছে কি না, সে প্রশ্নটা কেউ কোনোদিন করেনি।
     */
    public function test_the_user_screen_offers_a_way_to_set_the_limit(): void
    {
        $this->actingAs($this->owner)
            ->get(route('system_admin.user.edit', $this->salesman))
            ->assertOk()
            ->assertSee('branch_scope['.$this->company->id.'][]', false)
            ->assertSee($this->netrokona->code);
    }

    /**
     * সীমা বসানো — আর সেটা সত্যিই খাটে।
     *
     * সারি গোনা যথেষ্ট নয়: `DataScope` ঠিক সারিটা না পড়লে বা ভুল
     * `scope_type` বসলে সারিটা ঠিকই থাকত আর ছাঁকনি কিছুই করত না।
     */
    public function test_saving_a_limit_actually_limits_what_they_see(): void
    {
        $this->assertFalse(app(DataScope::class)->isLimited($this->salesman, UserDataScope::BRANCH));

        $this->saveScopes([$this->netrokona->id]);

        app(DataScope::class)->forget();

        $this->assertSame(
            [$this->netrokona->id],
            app(DataScope::class)->idsFor($this->salesman, UserDataScope::BRANCH),
        );

        $this->assertTrue(app(DataScope::class)->allows(
            $this->salesman, UserDataScope::BRANCH, $this->netrokona->id));

        $this->assertFalse(app(DataScope::class)->allows(
            $this->salesman, UserDataScope::BRANCH, $this->main->id));
    }

    /** বসানো সীমা পর্দায় ফিরে টিক দেওয়া অবস্থায় দেখা যায়। */
    public function test_the_screen_shows_back_what_was_saved(): void
    {
        $this->saveScopes([$this->netrokona->id]);

        $html = $this->actingAs($this->owner)
            ->get(route('system_admin.user.edit', $this->salesman))
            ->assertOk()
            ->getContent();

        /*
         * টিকটা ঠিক ওই শাখার ঘরে বসেছে কি না, সেটাই প্রশ্ন।
         *
         * কেবল "checked" শব্দটা খুঁজলে পাতার অন্য যেকোনো টিক — রোল,
         * কোম্পানি, সক্রিয় — পরীক্ষাটাকে পাশ করিয়ে দিত।
         */
        $needle = 'value="'.$this->netrokona->id.'" class="size-4"';
        $at = strpos($html, 'name="branch_scope['.$this->company->id.'][]"');

        $this->assertNotFalse($at);
        $this->assertStringContainsString('checked', substr($html, $at, 4000));
        $this->assertStringContainsString($needle, $html);
    }

    /**
     * সীমা তোলা — কিছু না বেছে সেভ করলেই।
     *
     * এটাই এই ফাইলের সবচেয়ে জরুরি পরীক্ষা। ভুল করে কাউকে বেঁধে
     * ফেললে ফেরার পথ না থাকলে ঠিক করার একমাত্র উপায় হত ডাটাবেজে
     * হাতে DELETE — অর্থাৎ যে সমস্যাটা মেটাতে এই পর্দাটা বানানো,
     * সেটাই ফিরে আসত।
     */
    public function test_saving_with_nothing_ticked_lifts_the_limit_again(): void
    {
        $this->saveScopes([$this->netrokona->id]);
        app(DataScope::class)->forget();
        $this->assertTrue(app(DataScope::class)->isLimited($this->salesman, UserDataScope::BRANCH));

        $this->saveScopes([]);
        app(DataScope::class)->forget();

        $this->assertFalse(app(DataScope::class)->isLimited($this->salesman, UserDataScope::BRANCH));
        $this->assertSame(0, UserDataScope::query()->withoutGlobalScopes()
            ->where('user_id', $this->salesman->id)->count());
    }

    /** একই শাখা দুইবার বসে না — সেভ বারবার করলেও। */
    public function test_saving_twice_does_not_double_the_rows(): void
    {
        $this->saveScopes([$this->netrokona->id, $this->main->id]);
        $this->saveScopes([$this->netrokona->id, $this->main->id]);

        $this->assertSame(2, UserDataScope::query()->withoutGlobalScopes()
            ->where('user_id', $this->salesman->id)->count());
    }

    /**
     * সীমা বদল খাতায় ওঠে — কোড সহ, আইডি নয়।
     *
     * সারিগুলো ব্যবহারকারীর নিজের সারিতে নয়, আলাদা টেবিলে — রোলের
     * মতোই। তাই মডেলের অডিট এটা দেখে না, অথচ "কে কার দেখার সীমা তুলে
     * দিল" প্রশ্নটা রোল বদলের মতোই বড়।
     */
    public function test_the_change_is_written_down_in_words_not_numbers(): void
    {
        $this->saveScopes([$this->netrokona->id]);

        $trail = AuditTrail::query()
            ->where('auditable_type', User::class)
            ->where('auditable_id', $this->salesman->id)
            ->where('action', 'scopes_changed')
            ->latest('id')
            ->first();

        $this->assertNotNull($trail);
        $this->assertSame($this->owner->id, $trail->user_id);
        $this->assertStringContainsString($this->netrokona->code, (string) $trail->reason);
        $this->assertStringContainsString(__('system_admin::message.scope_none'), (string) $trail->reason);
    }

    /** কিছু না বদলালে খাতায় নতুন সারি বসে না। */
    public function test_saving_the_same_limit_again_writes_nothing_new(): void
    {
        $this->saveScopes([$this->netrokona->id]);

        $before = AuditTrail::query()
            ->where('auditable_id', $this->salesman->id)
            ->where('action', 'scopes_changed')->count();

        $this->saveScopes([$this->netrokona->id]);

        $this->assertSame($before, AuditTrail::query()
            ->where('auditable_id', $this->salesman->id)
            ->where('action', 'scopes_changed')->count());
    }

    /**
     * এই ব্যবহারকারীর সীমা বসাতে গিয়ে অন্যেরটা মুছে যায় না।
     *
     * মোছার কোয়েরিটা `user_id`-তে বাঁধা না থাকলে এক ব্যবহারকারীর
     * সীমা সেভ করলেই সবার সীমা উঠে যেত — আর কেউ টের পেতেন না, কারণ
     * ফলটা "বেশি দেখা", কোনো ভুল বার্তা নয়।
     */
    public function test_one_persons_limit_does_not_wipe_anothers(): void
    {
        UserDataScope::query()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'scope_type' => UserDataScope::BRANCH,
            'scope_id' => $this->main->id,
        ]);

        $this->saveScopes([$this->netrokona->id]);

        $this->assertSame(1, UserDataScope::query()->withoutGlobalScopes()
            ->where('user_id', $this->owner->id)->count());
    }

    /**
     * সেভ করা ব্যবহারকারীর ফর্মটা যেমন ছিল তেমনই যায়, শুধু সীমা যোগ।
     *
     * @param  list<int>  $branchIds
     */
    private function saveScopes(array $branchIds): void
    {
        $user = $this->salesman->fresh(['roles', 'companies']);

        $this->actingAs($this->owner)
            ->put(route('system_admin.user.update', $user), [
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale ?? 'bn',
                'is_active' => '1',
                'roles' => $user->roles->pluck('name')->all(),
                'companies' => $user->companies->pluck('id')->all(),
                'branch_scope' => [$this->company->id => $branchIds],
            ])
            ->assertRedirect(route('system_admin.user.index'));
    }
}

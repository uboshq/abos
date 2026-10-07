<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Core\Support\RoleLabel;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ব্যবহারকারীর তালিকা এক নজরে বলে দেয় ইনি কে, কোথায় আর কতটা সুরক্ষিত।
 *
 * ── ⭐ মালিকের নমুনা, ২১ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"nexus er user management-y zevabe eivabe koro"* — ছবিসহ নাম, চিপে
 * ভূমিকা, যোগাযোগ, শাখা, দুই ধাপ, আর অবস্থা।
 *
 * ── ⚠️ কলাম দুইটা কেন নেই, আর সেটা এই ফাইলেই লেখা থাকা দরকার ────────
 * নমুনার *"Drives as"* আর *"Must change password"* বসানো হয়নি, কারণ
 * `users` সারণিতে ঘর দুইটা **নেই**। ⛔ খালি কলাম বসালে সেটা একটা মিথ্যা
 * প্রতিশ্রুতি হত — দেখতে কাজ করছে, আসলে কিছুই বলে না। ⓘ নিচের শেষ
 * দাবিটা সেটা সারণি থেকে যাচাই করে, যাতে ঘর দুইটা যেদিন সত্যিই আসে
 * সেদিন এই পরীক্ষাটাই মনে করিয়ে দেয়।
 *
 * ── ⓘ সবচেয়ে দামি দাবিটা দেখতে সবচেয়ে ছোট ──────────────────────────
 * "শাখার নাম দেখা যায়" নয় — **শাখাটা আগে থেকেই লোড হয়ে আসে**। ⚠️ শাখার
 * কলামটা সম্পর্কের ভেতর দিয়ে যায়, আর eager load ছাড়া প্রতিটা সারিতে
 * একটা করে কোয়েরি হত। ⛔ পঞ্চাশজন ব্যবহারকারীতে সেটা নীরবে পঞ্চাশটা,
 * আর পর্দাটা ধীরে ধীরে মরে যেত — কোথাও লাল না হয়ে।
 */
final class TheUserListSaysWhoEachPersonIsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->branch = $this->company->defaultBranch() ?? Branch::query()
            ->where('company_id', $this->company->id)->firstOrFail();

        CompanyContext::set($this->company->id, $this->branch->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_every_column_the_owner_asked_for_is_there(): void
    {
        $html = $this->list();

        /*
         * ⓘ শিরোনামগুলোই মাপা হয়, শ্রেণির নাম নয়: শ্রেণি বদলায়, আর
         * ⚠️ শ্রেণি ধরে লেখা পাহারা পরের থিমেই মিথ্যা লাল দেয়।
         */
        foreach ([
            __('system_admin::field.user_name'),
            __('system_admin::field.roles'),
            __('core.profile.contact'),
            __('core.company.branch'),
            __('system_admin::field.two_step'),
            __('core.table.status'),
        ] as $label) {
            $this->assertStringContainsString($label, $html,
                "⛔ তালিকায় «{$label}» কলামটা নেই।");
        }
    }

    /**
     * ⭐ ভূমিকা এখন চিপ, কমা দিয়ে জোড়া একটা লাইন নয়।
     *
     * ⓘ দুইটা ভূমিকা দেওয়া হয় ইচ্ছে করেই — একটাতে পুরনো `implode(', ')`
     * আর নতুন চিপ দেখতে প্রায় একরকম, আর দাবিটা কিছুই ধরত না।
     */
    public function test_roles_come_as_separate_chips(): void
    {
        $user = $this->member('chipped@abos.test');
        $user->assignRole(Role::findOrCreate('store_keeper', 'web'));
        $user->assignRole(Role::findOrCreate('accountant', 'web'));

        $html = $this->list();

        $first = RoleLabel::for('store_keeper');
        $second = RoleLabel::for('accountant');

        $this->assertStringContainsString($first, $html);
        $this->assertStringContainsString($second, $html);

        /*
         * ⚠️ দুই ক্রমেই দেখা হয়, আর এই লাইনটা দুঃখ করে শেখা।
         *
         * ⓘ প্রথম চালে কেবল একটা ক্রম মাপা হয়েছিল। ⛔ পুরনো `implode`
         * ফিরিয়ে দিয়েও দাবিটা **সবুজই রয়ে গেল** — `roles` সম্পর্কটা
         * আইডির ক্রমে আসে, যে ক্রমে ভূমিকা দেওয়া হয়েছিল সেই ক্রমে নয়।
         * ⚠️ অর্থাৎ পাহারাটা তাকাচ্ছিলই না।
         */
        foreach ([[$first, $second], [$second, $first]] as [$a, $b]) {
            $this->assertStringNotContainsString("{$a}, {$b}", $html, implode("\n", [
                '⛔ ভূমিকাগুলো এখনো কমা দিয়ে জোড়া একটা লাইন।',
                '',
                '⚠️ তিনটা ভূমিকা থাকলে লাইনটা একটাই লম্বা নামের মতো দেখায়,',
                'আর কোথায় একটা শেষ হয়ে পরেরটা শুরু সেটা পড়ে বার করতে হয়।',
            ]));
        }
    }

    /**
     * ⭐ দুই ধাপের ঘরটা **মিলিয়ে দেখা** তারিখটা পড়ে, চাবিটা নয়।
     *
     * ⛔ কেবল `mfa_secret` দেখলে অর্ধেক-সাজানো অ্যাকাউন্টও "চালু" দেখাত —
     * চাবি বসানো, অথচ লগইনে কোনো কোড চাওয়া হয় না। ⚠️ তখন কলামটা
     * নিরাপত্তার মিথ্যা খবর দিত, আর সেটা কলামটা না থাকার চেয়েও খারাপ।
     */
    public function test_two_step_reads_the_confirmation_not_the_key(): void
    {
        $halfway = $this->member('halfway@abos.test');
        $halfway->forceFill(['mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => null])->save();

        $done = $this->member('guarded@abos.test');
        $done->forceFill(['mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()])->save();

        $on = __('system_admin::field.two_step_on');

        /*
         * ⚠️ ঘরটা আলাদা করে আঁকা হয়, গোটা পাতায় লেখাটা গুনে নয়।
         *
         * ⓘ প্রথম চালে পাতায় "চালু" কয়বার আছে সেটা গোনা হত। ⛔ ইংরেজিতে
         * লেখাটা "On", আর সেটা পাতার অন্য অনেক শব্দের ভেতরেই বসে থাকে —
         * অর্থাৎ ভাষা বদলালেই দাবিটা মিথ্যা লাল বা মিথ্যা সবুজ দিত।
         */
        $this->assertStringContainsString($on, $this->securityCell($done),
            '⛔ যিনি কোড মিলিয়ে দেখিয়েছেন, তাঁর ঘরে "চালু" নেই।');

        $this->assertStringNotContainsString($on, $this->securityCell($halfway), implode("\n", [
            '⛔ অর্ধেক-সাজানো অ্যাকাউন্টও "চালু" দেখাচ্ছে।',
            '',
            '⚠️ চাবিটা বসানো, কিন্তু কোড কখনো মিলিয়ে দেখা হয়নি — লগইনে',
            'কিছুই চাওয়া হয় না। ⓘ কলামটা তখন নিরাপত্তার মিথ্যা খবর দেয়,',
            'আর সেটা কলামটা না থাকার চেয়েও খারাপ।',
        ]));
    }

    /** ⭐ শাখার নাম সারিতেই — আর সেটা সত্যিই ঐ শাখার নাম। */
    public function test_the_branch_name_is_on_the_row(): void
    {
        $this->member('posted@abos.test')
            ->forceFill(['current_branch_id' => $this->branch->id])->save();

        $this->assertStringContainsString($this->branch->name(), $this->list(),
            '⛔ সারিতে শাখার নাম নেই — কে কোথা থেকে কাজ করেন তা তালিকা বলে না।');
    }

    /**
     * ⭐ এই ফাইলের সবচেয়ে দামি দাবি: শাখাটা আগে থেকেই লোড হয়ে আসে।
     *
     * ⓘ শাখার কলামটা সম্পর্কের ভেতর দিয়ে যায়। ⚠️ eager load ছাড়া প্রতিটা
     * সারিতে একটা করে বাড়তি কোয়েরি হত — পর্দা ধীরে ধীরে মরত, কোথাও
     * লাল না হয়ে। ⛔ আর ঠিক এই ধরনের নীরব ফাঁকই ABOS-এর সবচেয়ে সাধারণ
     * রোগ: কাজটা হয়েছে, জোড়াটা লাগানো হয়নি।
     */
    public function test_the_branch_comes_loaded_not_fetched_per_row(): void
    {
        foreach (range(1, 6) as $n) {
            $this->member("many{$n}@abos.test");
        }

        /*
         * ⚠️ দুইটা সহজ পথ আগে চেষ্টা করা হয়েছে, আর **দুইটাই অন্ধ ছিল** —
         * এই মন্তব্যটা তাই সতর্কবার্তা, ইতিহাস নয়:
         *
         *   ⛔ কোয়েরি গুনে তুলনা — eager load সরিয়েও সংখ্যা বাড়েনি।
         *   ⛔ `relationLoaded()` — ব্লেড নিজেই ঘরটা ছুঁয়ে ফেলে, তাই
         *     রেন্ডারের **পরে** সম্পর্কটা সবসময়ই "লোড হয়ে আছে"।
         *
         * ⭐ তাই প্রশ্নটা উল্টো দিক থেকে করা হয়: অলস লোডিং **নিষিদ্ধ**
         * করে দেওয়া হয়, আর তখন সারি ধরে ধরে আনার চেষ্টাটাই ব্যতিক্রম
         * ছুঁড়ে দেয়। ⓘ এটাই একমাত্র রূপ যেটা সাবোতাজে সত্যিই লাল হয়।
         */
        Model::preventLazyLoading(true);

        /*
         * ⚠️ এই লাইনটা ছাড়া দাবিটা লাল হয় ঠিকই, কিন্তু **ভুল কথা বলে**:
         * ল্যারাভেলের হ্যান্ডলার ব্যতিক্রমটা গিলে ৫০০ ফেরায়, আর পড়া যায়
         * শুধু *"200 expected, got 500"*। ⓘ যিনি লাল দেখবেন তাঁর দরকার
         * কারণটা, সংখ্যাটা নয়।
         *
         * ⓘ হাতে লেখা কোনো বার্তা এখানে বসানো হয়নি, কারণ ভাঙলে ব্লেড
         * নিজেই বলে দেয় **কোন সম্পর্ক আর কোন পাতায়** —
         * *"Attempted to lazy load [currentBranch] … (View: …
         * partials/branch.blade.php)"* — আর সেটা আমার লেখা যেকোনো
         * বাক্যের চেয়ে বেশি কাজের।
         */
        $this->withoutExceptionHandling();

        try {
            $this->list();
        } finally {
            Model::preventLazyLoading(false);
        }

        $this->addToAssertionCount(1);
    }

    /**
     * ⛔ নমুনার দুইটা কলাম বসানো যায় না, আর কারণটা মাপা হলো।
     *
     * ⓘ এই দাবিটা **ব্যর্থ হওয়ার জন্যই লেখা** — যেদিন ঘর দুইটা সারণিতে
     * আসবে সেদিন এটা লাল হয়ে মনে করাবে কলাম দুইটা এখন বসানো যায়।
     * ⚠️ নাহলে মালিকের চাওয়া দুইটা জিনিস চিরকাল একটা কমিট-বার্তায় চাপা
     * পড়ে থাকত।
     */
    public function test_the_two_missing_columns_really_have_no_field(): void
    {
        $columns = Schema::getColumnListing('users');

        foreach (['must_change_password', 'vehicle_id'] as $field) {
            $this->assertNotContains($field, $columns, implode("\n", [
                "⭐ `users.{$field}` এখন সত্যিই আছে।",
                '',
                'ⓘ অর্থাৎ নমুনার ঐ কলামটা এখন সৎভাবে বসানো যায় — বসিয়ে',
                'এই দাবিটা হালনাগাদ করুন।',
            ]));
        }
    }

    /**
     * পাহারাটা সত্যিই তাকায়।
     *
     * ⓘ উপরের প্রতিটা দাবি সবুজ থাকত যদি তালিকাটা **খালি** ফিরত — কোনো
     * সারি নেই মানে কোনো ভুলও নেই। ⚠️ আজ রাতে ঠিক এই ফাঁদে দুইবার পড়া
     * হয়েছে, তাই সারি সত্যিই এসেছে কি না সেটাই আলাদা করে মাপা।
     */
    public function test_the_list_is_not_simply_empty(): void
    {
        $this->member('present@abos.test');

        $this->assertGreaterThan(0, $this->rows()->count(),
            'তালিকায় একটা সারিও নেই — উপরের দাবিগুলো তখন কিছুই প্রমাণ করে না।');
    }

    /** চলতি কোম্পানির একজন সত্যিকারের ব্যবহারকারী। */
    private function member(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'current_company_id' => $this->company->id,
            'current_branch_id' => $this->branch->id,
        ]);

        $user->companies()->attach($this->company->id, ['is_active' => true]);

        return $user;
    }

    /** দুই ধাপের ঘরটা একা — পাতার বাকি লেখা ছাড়া। */
    private function securityCell(User $user): string
    {
        return view('system_admin::user.partials.security', ['user' => $user->fresh()])->render();
    }

    private function list(): string
    {
        return (string) $this->get(route('system_admin.user.index'))->assertOk()->getContent();
    }

    private function rows(): Collection
    {
        $response = $this->get(route('system_admin.user.index'));

        $response->assertOk();

        return collect($response->viewData('users')->items());
    }
}

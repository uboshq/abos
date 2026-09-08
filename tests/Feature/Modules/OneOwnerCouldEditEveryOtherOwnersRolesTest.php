<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * এক মালিক অন্য সব মালিকের রোল বদলাতে পারতেন।
 *
 * ── ⛔ কী ভাঙা ছিল, ৭ সেপ্টেম্বর ২০২৬-এর আগে ─────────────────────────
 * রোল ছিল **বিশ্বজনীন** — একটাই `owner`, একটাই `salesman`, সব কোম্পানির
 * জন্য। ⓘ দুইটা ফল, দুইটাই নীরব:
 *
 *   ⛔ ব্যাকআপ নামানো — এক ক্রেতার মালিক **সবার ডাটাবেস** নামাতে
 *      পারতেন, কারণ অনুমতিটা তাঁর রোলে, আর রোলটা সবার
 *   ⛔ রোল সম্পাদনা — কেউ "বিক্রয়কর্মী"-র একটা ক্ষমতা তুলে দিলে
 *      **প্রতিটা কোম্পানির** বিক্রয়কর্মী সেটা হারাতেন
 *
 * ⚠️ দ্বিতীয়টা প্রথমটার চেয়েও নীরব: কোথাও কিছু ভাঙে না, কেবল অন্য এক
 * প্রতিষ্ঠানের একজন কর্মী একদিন সকালে দেখেন তাঁর একটা বোতাম নেই।
 *
 * ── ⚠️ কেন এই ফাইলটা এত সাবধানে ─────────────────────────────────────
 * teams চালু করার আসল বিপদ মাইগ্রেশন নয় — **টিম-প্রসঙ্গ বসাতে ভুলে
 * যাওয়া**। ⓘ একটা পথে ভুলে গেলে পারমিশন নীরবে ভুল উত্তর দেয়: হয় সবাই
 * তালাবন্ধ, নয় সবাই খোলা, আর **কোনো ত্রুটিবার্তা আসে না**।
 *
 * ⛔ তাই এখানে কেবল "কাজ করে" মাপা হয় না — যে পথগুলো সহজে বাদ পড়ে
 * (কনসোল · প্রসঙ্গহীন অবস্থা · কোম্পানি সুইচ) সেগুলো নাম ধরে মাপা।
 */
class OneOwnerCouldEditEveryOtherOwnersRolesTest extends TestCase
{
    use RefreshDatabase;

    private Company $alpha;

    private Company $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->alpha = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->beta = Company::query()->where('code', 'FMART')->firstOrFail();

        /*
         * ── ⚠️ খ-এ "বিক্রয়কর্মী" রোলটা হাতে বসানো ─────────────────────
         *
         * ⓘ সিডার accountant ও salesman কেবল **আলফাতে** বানায় (খ শুধু
         * টেমপ্লেট-রোলগুলো পায়)। ⛔ প্রথম খসড়ায় সেটা না দেখে দুই কোম্পানির
         * "একই নামের" রোল তুলনা করেছিলাম, আর দাবিটা এমন কিছু মাপছিল যার
         * দ্বিতীয় দিকটাই ছিল না।
         *
         * ⭐ তাই এখানে সত্যিকারের অবস্থাটা বানিয়ে নেওয়া হয়: **দুই কোম্পানিতে
         * একই নামের রোল**। ⚠️ ওটাই তো teams-এর আসল প্রতিশ্রুতি — আর
         * teams ছাড়া এই দুইটা সারি একসাথে থাকতেই পারত না (ইউনিক কী
         * আটকাত)।
         */
        CompanyContext::forCompany($this->beta->id, function () {
            $role = Role::findOrCreate('salesman');

            $role->givePermissionTo(
                $this->roleIn($this->alpha, 'salesman')->permissions->pluck('name')->all()
            );
        });
    }

    /**
     * একটা কোম্পানির রোল — **কলাম ধরে**, প্রসঙ্গের ভরসায় নয়।
     *
     * ── ⚠️ প্রথম খসড়ায় এটাই ভুল ছিল, ৭ সেপ্টেম্বর ২০২৬ ────────────────
     * লিখেছিলাম `Role::query()->where('name', $name)->first()` আর ভেবেছিলাম
     * প্রসঙ্গটাই ছেঁকে দেবে। ⛔ দেয় না: spatie টিমটা দেখে **বরাদ্দ ও
     * যাচাইয়ের সময়**, সাধারণ কোয়েরিতে কোনো global scope বসায় না।
     *
     * ⓘ ফলে দুই কোম্পানির জন্যই **একই সারি** ফিরত, আর দাবিটা "খ-ও
     * হারিয়েছে" বলে লাল হত — অথচ কোডটা ঠিক ছিল।
     *
     * ⭐ এটা ঠিক সেই ফাঁদ যা আজ সকালে `RoleController`-এও ছিল, আর একই
     * কারণে: **ছাঁকনিটা হাতে বসাতে হয়**।
     */
    private function roleIn(Company $company, string $name): ?Role
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return Role::query()
            ->where('name', $name)
            ->where('company_id', $company->id)
            ->first();
    }

    /**
     * ⓘ ভিত্তি — teams সত্যিই চালু আর কলামটা সত্যিই আছে।
     *
     * ⚠️ এটা না মাপলে নিচের সব দাবি সবুজ থাকত এমনকি teams বন্ধ থাকলেও,
     * কারণ এক কোম্পানির টেস্টে বিশ্বজনীন রোলও "ঠিক" দেখায়।
     */
    public function test_roles_are_actually_scoped_to_a_company(): void
    {
        $this->assertTrue(
            config('permission.teams'),
            'spatie-র teams বন্ধ — তাহলে রোল এখনো বিশ্বজনীন, আর নিচের দাবিগুলো কিছুই মাপে না।',
        );

        $this->assertSame(
            'company_id',
            config('permission.column_names.team_foreign_key'),
            'টিমের চাবিটা `company_id` নয় — বাকি ৯০টা টেবিলের সাথে মেলে না।',
        );

        $this->assertNotNull(
            $this->roleIn($this->alpha, 'salesman'),
            'প্রথম কোম্পানিতেই "বিক্রয়কর্মী" রোলটা নেই — ব্যাক-ফিল চলেনি।',
        );

        $this->assertNotNull(
            $this->roleIn($this->beta, 'salesman'),
            "দ্বিতীয় কোম্পানিতে রোলটা নেই।\n"
            .'ব্যাক-ফিল প্রতিটা কোম্পানিকে নিজের কপি দেওয়ার কথা — নাহলে ওখানে কেউ কিছু পারবেন না।',
        );
    }

    /**
     * ⛔ **এই ফাইলের আসল দাবি।**
     *
     * ক-এর "বিক্রয়কর্মী" থেকে একটা ক্ষমতা তুলে নিলে খ-এর বিক্রয়কর্মী
     * অক্ষত থাকেন। ⓘ ৭ সেপ্টেম্বরের আগে এখানেই ভাঙত।
     */
    public function test_taking_a_power_from_one_company_leaves_the_other_untouched(): void
    {
        $permission = 'sales.invoice.create';

        $alphaRole = $this->roleIn($this->alpha, 'salesman');
        $betaRole = $this->roleIn($this->beta, 'salesman');

        $this->assertTrue($alphaRole->hasPermissionTo($permission), 'শুরুতেই ক-এর রোলে অনুমতিটা নেই।');
        $this->assertTrue($betaRole->hasPermissionTo($permission), 'শুরুতেই খ-এর রোলে অনুমতিটা নেই।');

        // ক-এর মালিক নিজের বিক্রয়কর্মীর কাছ থেকে ক্ষমতাটা তুলে নিলেন
        CompanyContext::forCompany($this->alpha->id, function () use ($alphaRole, $permission) {
            $alphaRole->revokePermissionTo($permission);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse(
            $this->roleIn($this->alpha, 'salesman')->hasPermissionTo($permission),
            'ক-এর নিজের রোলেই বদলটা বসেনি — তাহলে সম্পাদনাই কাজ করছে না।',
        );

        $this->assertTrue(
            $this->roleIn($this->beta, 'salesman')->hasPermissionTo($permission),
            "⛔ খ-এর বিক্রয়কর্মীও ক্ষমতাটা হারিয়েছেন।\n"
            ."এক ক্রেতার সিদ্ধান্ত আরেক ক্রেতার কর্মীর বোতাম কেড়ে নিয়েছে,\n"
            .'আর কোথাও কিছু ভাঙেনি — কেবল একজন একদিন সকালে দেখবেন বোতামটা নেই।',
        );
    }

    /**
     * ⛔ ব্যাকআপ নামানোর ফাঁকটাও এখানেই বন্ধ হয়।
     *
     * ⓘ যে অনুমতি ক-এর মালিকের আছে, সেটা তাঁর **ক-এর রোলে**। খ-এ দাঁড়ালে
     * সেই রোলটা তাঁর নয় — যদি তিনি খ-এর সদস্যই না হন।
     */
    public function test_an_owner_of_one_company_is_nobody_in_another(): void
    {
        $accountant = User::query()->where('email', 'accounts@abos.test')->firstOrFail();

        $this->assertTrue(
            CompanyContext::forCompany($this->alpha->id, fn () => $accountant->fresh()->can('accounts.view')),
            'নিজের কোম্পানিতেই হিসাবরক্ষক কিছু পারছেন না — ছাঁকনি সবাইকে বাদ দিয়েছে।',
        );

        $this->assertFalse(
            CompanyContext::forCompany($this->beta->id, fn () => $accountant->fresh()->can('accounts.view')),
            "⛔ হিসাবরক্ষক অন্য কোম্পানিতেও একই ক্ষমতা নিয়ে দাঁড়িয়ে আছেন।\n"
            ."তিনি ওই কোম্পানির সদস্যই নন। ⚠️ এই ফাঁক দিয়েই এক ক্রেতার লোক\n"
            .'আরেক ক্রেতার ব্যাকআপ নামাতে পারতেন।',
        );
    }

    /**
     * ⚠️ কনসোলে চালানো — যে পথটা সবচেয়ে সহজে বাদ পড়ে।
     *
     * ── কেন এটা আলাদা করে মাপা ──────────────────────────────────────
     * `abos:sync-permissions` চলে **প্রতিটা ডেপ্লয়ে**, কনসোলে, যেখানে
     * কোনো কোম্পানি-প্রসঙ্গ নেই। ⛔ প্রসঙ্গ ছাড়া `Role::create()` করলে
     * `company_id` থাকত `null` — রোলটা **কারও নয়**।
     *
     * ⓘ তখন লগইন হত, মেনু খালি থাকত, আর কোথাও কোনো ত্রুটি দেখা যেত না।
     * ⚠️ এই ব্যর্থতাটা ডেপ্লয়ের রাতে ধরা পড়ে না — পরদিন সকালে ধরা পড়ে,
     * ব্যবহারকারীর মুখে।
     */
    public function test_the_deploy_time_sync_gives_every_company_its_roles(): void
    {
        CompanyContext::clear();

        app(PermissionSyncer::class)->sync();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $orphans = Role::query()->whereNull('company_id')->pluck('name')->all();

        $this->assertSame(
            [],
            $orphans,
            "এই রোলগুলোর কোনো কোম্পানি নেই — কনসোলে প্রসঙ্গ ছাড়া তৈরি হয়েছে।\n"
            ."ওগুলো কেউ কোনোদিন পাবে না: লগইন হবে, মেনু খালি থাকবে,\n"
            .'আর কোথাও কোনো ত্রুটি দেখা যাবে না।',
        );

        /*
         * ⚠️ কোন রোলটা মাপা হবে — এটা ভুল করেছিলাম, ৭ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ প্রথমে `accountant` মেপেছিলাম, আর দাবিটা লাল হয়েছিল। ⛔ কিন্তু
         * কোডটা ঠিক ছিল: `accountant` কোনো **টেমপ্লেট-রোল নয়** — সিডার
         * ওটা কেবল প্রথম কোম্পানিতে বানায়।
         *
         * ⭐ sync-এর দায়িত্ব দুইটা, আর সেই দুইটাই এখানে মাপা:
         *     `owner`      — প্রতিটা কোম্পানিতে থাকতেই হবে
         *     টেমপ্লেটগুলো  — Manager · HR · Field Sales · Warehouse
         *
         * ⚠️ যে রোল কেউ হাতে বানিয়েছেন, সেটা অন্য কোম্পানিতে বসানো sync-এর
         * কাজ নয় — বসালে সেটা **অন্যের সিদ্ধান্ত নকল করা** হত।
         */
        foreach ([$this->alpha, $this->beta] as $company) {
            $this->assertNotNull(
                $this->roleIn($company, 'owner'),
                $company->code.'-এ ডেপ্লয়ের পর মালিকের রোলটাই নেই — ওখানে কেউ কিছু পারবেন না।',
            );

            foreach (array_keys(app(\App\Core\Services\RoleTemplateRegistry::class)->all()) as $template) {
                $this->assertNotNull(
                    $this->roleIn($company, $template),
                    $company->code.'-এ টেমপ্লেট-রোল "'.$template."\" বসেনি।\n"
                    .'নতুন কোম্পানি খুললে শুরুর রোলগুলো নিজে থেকেই থাকার কথা।',
                );
            }
        }
    }
}

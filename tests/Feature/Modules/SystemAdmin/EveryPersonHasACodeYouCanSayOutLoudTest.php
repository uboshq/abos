<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * প্রতিটা মানুষের একটা সংকেত, আর সেটা মুখে বলা যায়।
 *
 * ── ⭐ মালিকের নমুনা, ২২ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * *"ব্যবহারকারী talika eirokom cleen ekta list koro"* — নমুনার প্রতিটা
 * সারিতে `USR0001`। ⓘ কাজে লাগে যখন একই নামের দুইজন থাকেন, বা কাগজে
 * একজনকে নাম ধরে ডাকতে হয়।
 *
 * ── ⚠️ এই ফাইলের আসল কাজ: জোড়াটা দেখা ─────────────────────────────
 * মাইগ্রেশন পুরনো সবাইকে সংকেত দিয়ে যায়। ⛔ কিন্তু **নতুন ব্যবহারকারীর
 * ঘরটা খালি থাকত** যদি তৈরির পথে লাইনটা না বসত — আর তালিকায় কিছু
 * সারিতে সংকেত, কিছুতে ড্যাশ বসত, কেউ বুঝত না কেন।
 *
 * ⓘ ABOS-এর সবচেয়ে সাধারণ রোগ ঠিক এটাই: কাজটা হয়েছে, জোড়াটা নয়।
 */
final class EveryPersonHasACodeYouCanSayOutLoudTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    /** ⭐ যাঁরা আগে থেকেই ছিলেন, তাঁদের সবার সংকেত বসানো। */
    public function test_everyone_who_was_already_here_got_a_code(): void
    {
        $without = User::query()->whereNull('code')->count();

        $this->assertSame(0, $without,
            "⛔ {$without} জন ব্যবহারকারীর সংকেত নেই — মাইগ্রেশনের ভরাটটা সবাইকে পায়নি।");
    }

    /**
     * ⭐ আর নতুন একজন তৈরি করলেও সংকেত বসে — এটাই আসল দাবি।
     *
     * ⛔ এই লাইনটা ছাড়া জিনিসটা আধখানা: পুরনোরা পেতেন, নতুনরা নয়।
     */
    public function test_a_new_person_gets_one_too(): void
    {
        $this->post(route('system_admin.user.store'), $this->paperwork('notun@abos.test'))
            ->assertRedirect();

        $fresh = User::query()->where('email', 'notun@abos.test')->first();

        $this->assertNotNull($fresh, 'ব্যবহারকারীই তৈরি হয়নি — দাবিটা তখন কিছুই প্রমাণ করে না।');

        $this->assertNotNull($fresh->code, implode("\n", [
            '⛔ নতুন ব্যবহারকারীর সংকেতের ঘরটা খালি।',
            '',
            '⚠️ তালিকায় তখন কিছু সারিতে সংকেত, কিছুতে ড্যাশ — আর কেউ',
            'বুঝত না কেন। ⓘ মাইগ্রেশন পুরনোদের দেয়, তৈরির পথ নতুনদের।',
        ]));
    }

    /**
     * ⭐ আর দুইজনের একই সংকেত হয় না — সবচেয়ে দামি দাবি।
     *
     * ⛔ `count() + 1` লিখলে একজন মুছে গেলে (বা অন্য কোম্পানিতে বসলে)
     * পরেরজন **আগের কারো সংকেতটাই** পেতেন। ⚠️ তখন *"USR0007 মাল বুঝে
     * নিয়েছেন"* কথাটা দুইজনকে বোঝাত, আর কাগজটা কিছুই প্রমাণ করত না।
     */
    public function test_two_people_never_share_a_code(): void
    {
        foreach (['ek@abos.test', 'dui@abos.test', 'tin@abos.test'] as $email) {
            $this->post(route('system_admin.user.store'), $this->paperwork($email))->assertRedirect();
        }

        $codes = User::query()->whereNotNull('code')->pluck('code');

        $this->assertSame($codes->count(), $codes->unique()->count(), implode("\n", [
            '⛔ দুইজনের সংকেত এক হয়ে গেছে।',
            '',
            '⚠️ সংকেতটা তখন আর কাউকে চেনায় না — আর ওটাই তার একমাত্র কাজ।',
        ]));
    }

    /**
     * ⭐ একজন মুছে গেলেও পরেরজন তাঁর সংকেত পান না।
     *
     * ⓘ এটাই উপরের দাবিটার আসল ধার: গোনা দিয়ে বসালে তিনজনের মধ্যে
     * একজন গেলে চতুর্থজন **তিন নম্বরটাই** পেতেন, আর উপরের দাবিটা
     * তখনো সবুজ থাকত — কারণ ঐ মুহূর্তে সংখ্যাগুলো আলাদাই।
     */
    public function test_a_gap_is_never_filled_again(): void
    {
        $this->post(route('system_admin.user.store'), $this->paperwork('age@abos.test'))->assertRedirect();

        $taken = (string) User::query()->where('email', 'age@abos.test')->value('code');

        /*
         * ⭐ মোছা হয় যেভাবে ABOS-এ সত্যি হয় — নরম-মোছা।
         *
         * ⛔ প্রথম চালে `DB::table()->delete()` লেখা ছিল — শক্ত-মোছা — আর
         * দাবিটা লাল হয়েছে। ⓘ কিন্তু ভুলটা কোডে নয়, দাবিতে:
         * [[UserController]]-এর নিজের টীকায় লেখা — *"একজন ব্যবহারকারীর
         * নাম প্রতিটা বিলে, প্রতিটা অডিট সারিতে বসে আছে। মুছে ফেললে ওই
         * সব কাগজে 'কে করেছিল' প্রশ্নের উত্তর হারায়।"*
         *
         * ⭐ অর্থাৎ শক্ত-মোছা **ঘটেই না**, আর তার জন্য পাহারা বসানো মানে
         * একটা কাল্পনিক বিপদের পাহারা। ⓘ নরম-মোছায় সারিটা থাকে, তাই
         * `withTrashed()` সংকেতটা ধরে রাখে — আর এটাই মাপার জিনিস।
         */
        User::query()->where('email', 'age@abos.test')->firstOrFail()->delete();

        $this->post(route('system_admin.user.store'), $this->paperwork('pore@abos.test'))->assertRedirect();

        $this->assertNotSame($taken, (string) User::query()->where('email', 'pore@abos.test')->value('code'),
            "⛔ মুছে যাওয়া একজনের সংকেত ({$taken}) আবার কাউকে দেওয়া হয়েছে।");
    }

    /** @return array<string, mixed> */
    private function paperwork(string $email): array
    {
        return [
            'name' => 'পরীক্ষার মানুষ',
            'email' => $email,
            'password' => 'Abos!2026Strong',
            'password_confirmation' => 'Abos!2026Strong',
            'locale' => 'bn',
            'is_active' => '1',
            'companies' => [$this->company->id],

            /*
             * ⚠️ ভূমিকা বাধ্যতামূলক — প্রথম চালে এটা ছিল না, আর
             * ব্যবহারকারী তৈরিই হয়নি। ℹ "তৈরি হয়নি" দাবিটা লাল হয়ে
             * সেটা ধরিয়ে দিয়েছে — নাহলে "সংকেত বসেনি" বলে আমি
             * কোড খুঁজতে যেতাম, আর ভুলটা ছিল কাগজে।
             */
            'roles' => [Role::query()->value('name')],
        ];
    }
}

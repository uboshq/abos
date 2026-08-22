<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\AuditFieldChange;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerPortalService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * পোর্টালটা কাজ করত, কিন্তু কেউ ওটা খুলতে পারত না।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * ডিলারের পাতা, লগইন, দাবির ফর্ম — সবই তৈরি ছিল, আর টেস্টেও পাশ করত।
 * কিন্তু `portal_enabled` চালু করার কোনো পর্দা ছিল না। মালিক ABOS-এর
 * ভেতরে যেখানেই খুঁজুন, "পোর্টাল" শব্দটাই কোথাও ছিল না।
 *
 * অর্থাৎ ফিচারটা লেখা হয়েছিল, পরীক্ষা হয়েছিল, ডিপ্লয়ও হয়েছিল — আর
 * একজন ডিলারও কোনোদিন ঢুকতে পারতেন না। চালু করার একমাত্র পথ ছিল
 * `php artisan tinker`।
 *
 * ── এই ফাইলের সবচেয়ে জরুরি পরীক্ষা ──────────────────────────────────
 * দুইটা।
 *
 * এক: বিক্রয়কর্মী চাবি দিতে পারেন না। যিনি চাবি দিতে পারেন তিনি
 * যেকোনো ডিলারের পাসওয়ার্ড বসাতে পারেন — অর্থাৎ নিজের জানা একটা
 * পাসওয়ার্ড বসিয়ে সেই ডিলার সেজে ঢুকতে পারেন।
 *
 * দুই: hash কোনোদিন নিরীক্ষার খাতায় বসে না। বসলে যিনি অডিটের পর্দা
 * দেখতে পান তিনি প্রতিটা ডিলারের hash হাতে পেতেন।
 */
class NobodyCouldOpenTheDealersDoorTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $dealer;

    private User $owner;

    private User $salesman;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->salesman = User::query()->whereHas('roles',
            fn ($q) => $q->where('name', 'salesman'))->firstOrFail();

        $this->dealer = Customer::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'code' => 'DOOR-1',
            'name_en' => 'Door Dealer',
            'status' => DocumentStatus::CONFIRMED,
            'is_active' => true,
        ]);
    }

    /* ── দরজাটা আছে ─────────────────────────────────────────────── */

    /**
     * পর্দাটা সত্যিই আছে — আর "পোর্টাল" শব্দটা তাতে লেখা।
     *
     * ঠিক এই পরীক্ষাটাই আগে ছিল না। ফিচারের প্রতিটা টুকরো কাজ করত,
     * অথচ চালু করার পথ না থাকায় কেউ কোনোদিন ব্যবহার করতে পারতেন না।
     */
    public function test_the_customer_page_offers_the_portal_key(): void
    {
        $this->actingAs($this->owner)
            ->get(route('customer.show', $this->dealer))
            ->assertOk()
            ->assertSee('data-portal', false)
            ->assertSee(__('customer::action.portal_enable'));
    }

    public function test_the_owner_opens_the_portal_and_the_dealer_gets_in(): void
    {
        $this->actingAs($this->owner)
            ->post(route('customer.portal.store', $this->dealer), [
                'password' => 'shop-pass-1',
                'password_confirmation' => 'shop-pass-1',
            ])
            ->assertRedirect(route('customer.show', $this->dealer));

        $this->assertTrue((bool) $this->dealer->fresh()->portal_enabled);

        /*
         * সত্যিকারের লগইন দিয়ে যাচাই, কেবল কলামটা দেখে নয়।
         *
         * কলামটা true হওয়া আর ডিলারের ঢুকতে পারা এক জিনিস নয়: hash
         * ভুল ঘরে বসলে বা `getAuthPasswordName()` কাজ না করলে কলামটা
         * ঠিকই true দেখাত আর লগইন তবু ব্যর্থ হত।
         */
        auth()->guard('web')->logout();

        $this->post(route('sales.portal.login.attempt'), [
            'code' => 'DOOR-1',
            'password' => 'shop-pass-1',
        ])->assertRedirect(route('sales.portal.home'));
    }

    public function test_a_new_password_replaces_the_old_one(): void
    {
        $this->enable('first-pass-1');

        $this->actingAs($this->owner)
            ->post(route('customer.portal.store', $this->dealer), [
                'password' => 'second-pass-1',
                'password_confirmation' => 'second-pass-1',
            ])->assertRedirect(route('customer.show', $this->dealer));

        auth()->guard('web')->logout();

        $this->post(route('sales.portal.login.attempt'), [
            'code' => 'DOOR-1', 'password' => 'first-pass-1',
        ])->assertSessionHasErrors('code');

        $this->post(route('sales.portal.login.attempt'), [
            'code' => 'DOOR-1', 'password' => 'second-pass-1',
        ])->assertRedirect(route('sales.portal.home'));
    }

    public function test_a_mistyped_confirmation_sets_nothing(): void
    {
        $this->actingAs($this->owner)
            ->post(route('customer.portal.store', $this->dealer), [
                'password' => 'shop-pass-1',
                'password_confirmation' => 'shop-pass-2',
            ])->assertSessionHasErrors('password');

        $this->assertFalse((bool) $this->dealer->fresh()->portal_enabled);
    }

    public function test_a_short_password_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post(route('customer.portal.store', $this->dealer), [
                'password' => 'abc', 'password_confirmation' => 'abc',
            ])->assertSessionHasErrors('password');

        $this->assertFalse((bool) $this->dealer->fresh()->portal_enabled);
    }

    /* ── দরজাটা বন্ধও হয় ────────────────────────────────────────── */

    public function test_closing_the_portal_stops_the_next_sign_in(): void
    {
        $this->enable();

        $this->actingAs($this->owner)
            ->delete(route('customer.portal.destroy', $this->dealer))
            ->assertRedirect(route('customer.show', $this->dealer));

        auth()->guard('web')->logout();

        $this->post(route('sales.portal.login.attempt'), [
            'code' => 'DOOR-1', 'password' => 'shop-pass-1',
        ])->assertSessionHasErrors('code');
    }

    /**
     * বন্ধ করলে যিনি আগে থেকেই ঢুকে আছেন তিনিও বেরিয়ে যান।
     *
     * ── কেন এটা আলাদা করে পরীক্ষা করতে হয় ──────────────────────────
     * `auth:portal` একটাই প্রশ্ন করে — "ইনি কি ঢুকেছিলেন?" — আর
     * উত্তরটা আসে সেশন থেকে, ডাটাবেজ থেকে নয়। ফলে বন্ধ করার বোতামটা
     * চলতি সেশনে কিছুই করত না।
     *
     * ঠিক যে মুহূর্তে বন্ধ করাটা সবচেয়ে জরুরি — পাসওয়ার্ড ফাঁস, বা
     * ডিলারের সাথে সম্পর্ক ছিন্ন — সেই মুহূর্তেই বোতামটা অকেজো হত,
     * অথচ পর্দা বলত কাজ হয়ে গেছে।
     */
    public function test_closing_the_portal_throws_out_whoever_is_already_inside(): void
    {
        $this->enable();

        $this->post(route('sales.portal.login.attempt'), [
            'code' => 'DOOR-1', 'password' => 'shop-pass-1',
        ])->assertRedirect(route('sales.portal.home'));

        $this->get(route('sales.portal.home'))->assertOk();

        $this->actingAs($this->owner)
            ->delete(route('customer.portal.destroy', $this->dealer));

        auth()->guard('web')->logout();

        $this->get(route('sales.portal.home'))
            ->assertRedirect(route('sales.portal.login'));
    }

    public function test_reopening_needs_no_new_password_to_be_told_to_the_dealer(): void
    {
        $this->enable();

        $this->actingAs($this->owner)->delete(route('customer.portal.destroy', $this->dealer));

        // পুরনো hash রয়ে গেছে, তাই একই পাসওয়ার্ড দিয়ে আবার চালু করা যায়
        $this->actingAs($this->owner)->post(route('customer.portal.store', $this->dealer), [
            'password' => 'shop-pass-1', 'password_confirmation' => 'shop-pass-1',
        ]);

        auth()->guard('web')->logout();

        $this->post(route('sales.portal.login.attempt'), [
            'code' => 'DOOR-1', 'password' => 'shop-pass-1',
        ])->assertRedirect(route('sales.portal.home'));
    }

    /* ── কে চাবি দিতে পারেন ─────────────────────────────────────── */

    /**
     * বিক্রয়কর্মী চাবি দিতে পারেন না।
     *
     * সিডারে `customer.%` ঢালাওভাবে বিক্রয়কর্মীকে দেওয়া হয়, তাই
     * `customer.portal` ঘোষণামাত্র সেটাও চলে যেত — কোনো ভুল বার্তা
     * ছাড়াই। ঠিক এই ফাঁদটা এই প্রকল্পে আগে চারবার ঘটেছে।
     */
    public function test_a_salesman_cannot_hand_out_a_portal_key(): void
    {
        $this->assertFalse($this->salesman->can('customer.portal'));

        $this->actingAs($this->salesman)
            ->post(route('customer.portal.store', $this->dealer), [
                'password' => 'shop-pass-1', 'password_confirmation' => 'shop-pass-1',
            ])->assertForbidden();

        $this->assertFalse((bool) $this->dealer->fresh()->portal_enabled);
    }

    public function test_a_salesman_does_not_even_see_the_section(): void
    {
        $this->actingAs($this->salesman)
            ->get(route('customer.show', $this->dealer))
            ->assertOk()
            ->assertDontSee('data-portal', false);
    }

    public function test_a_salesman_cannot_close_a_portal_either(): void
    {
        $this->enable();

        $this->actingAs($this->salesman)
            ->delete(route('customer.portal.destroy', $this->dealer))
            ->assertForbidden();

        $this->assertTrue((bool) $this->dealer->fresh()->portal_enabled);
    }

    /* ── খাতায় কী ওঠে, আর কী ওঠে না ─────────────────────────────── */

    /**
     * পাসওয়ার্ডের hash কোনোদিন নিরীক্ষার খাতায় বসে না।
     *
     * bcrypt hash পড়ে পাসওয়ার্ড বলা যায় না, তাই প্রথমে মনে হয় ক্ষতি
     * নেই। কিন্তু তখন খাতাটাই প্রতিটা ডিলারের hash-এর একটা তালিকা হয়ে
     * যেত, আর অফলাইনে hash ভাঙা যায় — সময় নিয়ে, কেউ না জেনে।
     */
    public function test_the_password_hash_never_reaches_the_audit_book(): void
    {
        $this->enable();

        $this->assertSame(0, AuditFieldChange::query()
            ->where('field', 'portal_password')->count());

        $this->assertSame(0, AuditFieldChange::query()
            ->where('old_value', 'like', '$2y$%')
            ->orWhere('new_value', 'like', '$2y$%')
            ->count());
    }

    /**
     * অথচ ঘটনাটা হারায় না — কে, কখন, কী করলেন।
     *
     * "ডিলারের পাসওয়ার্ড কে বদলেছিল" — টাকার হিসাব নিয়ে ঝগড়ার দিন
     * এটাই প্রথম প্রশ্ন। মান বাদ দিতে গিয়ে ঘটনাটাও বাদ পড়লে বাদ
     * দেওয়াটাই একটা নতুন ফাঁক হত।
     */
    public function test_the_act_itself_is_written_down_with_who_did_it(): void
    {
        $this->enable();

        $trail = AuditTrail::query()
            ->where('auditable_type', Customer::class)
            ->where('auditable_id', $this->dealer->id)
            ->where('action', 'portal_enabled')
            ->first();

        $this->assertNotNull($trail);
        $this->assertSame($this->owner->id, $trail->user_id);

        $this->actingAs($this->owner)->post(route('customer.portal.store', $this->dealer), [
            'password' => 'another-pass-1', 'password_confirmation' => 'another-pass-1',
        ]);

        $this->assertSame(1, AuditTrail::query()
            ->where('auditable_id', $this->dealer->id)
            ->where('action', 'portal_password_set')->count());

        $this->actingAs($this->owner)->delete(route('customer.portal.destroy', $this->dealer));

        $this->assertSame(1, AuditTrail::query()
            ->where('auditable_id', $this->dealer->id)
            ->where('action', 'portal_disabled')->count());
    }

    /**
     * নিরীক্ষার ছাঁকনিতে কাজগুলো খুঁজে পাওয়া যায়।
     *
     * তালিকায় সারিটা দেখানো আর ছেঁকে বের করতে পারা এক জিনিস নয়।
     * ছাঁকনির তালিকাটা আগে একটা স্থির ধ্রুবক ছিল, তাই মডিউলের নিজের
     * কাজগুলো — `roles_changed`, `password_set`, আর এখন এই তিনটা —
     * কোনোদিন ছেঁকে বের করা যেত না।
     */
    public function test_the_audit_filter_can_find_the_portal_actions(): void
    {
        $this->enable();

        $this->actingAs($this->owner)
            ->get(route('governance.audit.index', ['action' => 'portal_enabled']))
            ->assertOk()
            ->assertSee(__('governance::action.portal_enabled'));
    }

    /* ── অতিথির অনুরোধে, প্রসঙ্গ ছাড়া ───────────────────────────── */

    /**
     * ডিলার লগইন করতে পারেন যখন কোনো কোম্পানি বসানো **নেই**।
     *
     * ── কেন এই পরীক্ষাটা এই ফাইলের সবচেয়ে দামি ─────────────────────
     * ডিলারের লগইন একটা অতিথির অনুরোধ — তখনো কেউ ঢোকেননি, তাই
     * `ResolveCompanyContext` কোনো কোম্পানি বসাতে পারে না।
     *
     * পুরনো কোড `Auth::guard('portal')->attempt()` ডাকত, আর ওটা ভেতরে
     * `Customer::query()` চালায় — গ্লোবাল স্কোপসহ। প্রসঙ্গ ছাড়া
     * `BelongsToCompany` ব্যতিক্রম ছুঁড়ত, অর্থাৎ **প্রতিটা** ডিলার
     * লগইনে ৫০০ আসত।
     *
     * অথচ পোর্টালের সব টেস্ট পাশ করত। কারণ `setUp()`-এ
     * `CompanyContext::set()` ডাকা হয়, আর ওটা স্ট্যাটিক — তাই টেস্টের
     * ভেতরের অনুরোধেও প্রসঙ্গটা বসানোই থেকে যেত। আসল ব্রাউজারে কখনো
     * থাকে না।
     *
     * তাই এখানে প্রসঙ্গটা ইচ্ছাকৃতভাবে মুছে ফেলা হয়। এটাই একমাত্র
     * পরীক্ষা যা আসল অনুরোধটার মতো করে দেখে।
     *
     * ধরা পড়েছে ব্রাউজারে, লাইভে দেওয়ার আগে — টেস্টে নয়।
     */
    public function test_a_dealer_signs_in_on_a_request_that_has_no_company_yet(): void
    {
        $this->enable();

        CompanyContext::clear();

        $this->post(route('sales.portal.login.attempt'), [
            'code' => 'DOOR-1', 'password' => 'shop-pass-1',
        ])->assertRedirect(route('sales.portal.home'));

        CompanyContext::clear();

        $this->get(route('sales.portal.home'))->assertOk();
    }

    /**
     * দুই কোম্পানিতে একই কোড — যে যার নিজের পাতায় ঢোকেন।
     *
     * গ্রাহকের কোড কোম্পানির **ভেতরে** অনন্য, সবার মধ্যে নয়। পুরনো
     * কোড `first()` দিয়ে যেকোনো একটা সারি তুলত, তাই দ্বিতীয়
     * কোম্পানির ডিলার সঠিক পাসওয়ার্ড দিয়েও ঢুকতে পারতেন না — যাচাইটা
     * হত অন্য কারো hash-এর সাথে।
     */
    public function test_two_companies_may_share_a_code_without_shadowing_each_other(): void
    {
        $this->enable();

        $beta = Company::query()->where('code', '!=', 'TDEPOT')->firstOrFail();

        /*
         * বিটার ডিলার ও তার চাবি — বিটার নিজের প্রসঙ্গের ভেতরে।
         *
         * HTTP দিয়ে করা যেত না, আর সেটাই ঠিক: মালিক এখন TDEPOT-এ বসা,
         * তাই রুট-মডেল বাইন্ডিং বিটার গ্রাহককে খুঁজেই পেত না (৪০৪)।
         * অন্য কোম্পানির গ্রাহকে হাত দিতে হলে আগে কোম্পানি বদলাতে হয়
         * — এটা বাধা নয়, টেন্যান্ট আলাদা থাকার প্রমাণ।
         */
        $other = CompanyContext::forCompany($beta->id, function () use ($beta) {
            $dealer = Customer::query()->create([
                'company_id' => $beta->id,
                'branch_id' => $beta->defaultBranch()?->id,
                'code' => 'DOOR-1',
                'name_en' => 'Same Code Dealer',
                'status' => DocumentStatus::CONFIRMED,
                'is_active' => true,
            ]);

            app(CustomerPortalService::class)->enable($dealer, 'other-pass-1');

            return $dealer;
        });

        CompanyContext::clear();

        $this->post(route('sales.portal.login.attempt'), [
            'code' => 'DOOR-1', 'password' => 'other-pass-1',
        ])->assertRedirect(route('sales.portal.home'));

        $this->assertSame($other->id, auth()->guard('portal')->id());
    }

    /**
     * বাইরের দরজায় তালা আছে।
     *
     * লগইনের নামটা গোপন নয় — কোডটা প্রতিটা বিলের উপরে ছাপা। তাই
     * CUS-0001 থেকে ধরে ধরে চেষ্টা করা আন্দাজ নয়, তালিকা মিলিয়ে
     * দেখা। সীমা ছাড়া একটা স্ক্রিপ্ট রাতভর চললে দুর্বল পাসওয়ার্ডওয়ালা
     * ডিলারের খাতা খুলে যেত, আর কোনো চিহ্নও থাকত না।
     */
    public function test_guessing_in_a_loop_gets_shut_out(): void
    {
        $this->enable();
        CompanyContext::clear();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('sales.portal.login.attempt'), [
                'code' => 'DOOR-1', 'password' => 'guess-'.$i,
            ])->assertSessionHasErrors('code');
        }

        $this->post(route('sales.portal.login.attempt'), [
            'code' => 'DOOR-1', 'password' => 'guess-6',
        ])->assertStatus(429);
    }

    private function enable(string $password = 'shop-pass-1'): void
    {
        $this->actingAs($this->owner)->post(route('customer.portal.store', $this->dealer), [
            'password' => $password,
            'password_confirmation' => $password,
        ]);

        $this->assertTrue((bool) $this->dealer->fresh()->portal_enabled);

        // পরের অনুরোধগুলো যেন ডিলার হিসেবে যায়, মালিক হিসেবে নয়
        auth()->guard('web')->logout();
    }
}

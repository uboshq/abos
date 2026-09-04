<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * প্রদেয়ের **যেকোনো** ঘরে বাকিতে খরচ লিখলে পক্ষের নাম লাগবে।
 *
 * ── কেন এই পাহারাটা লাগল ────────────────────────────────────────────
 * নিয়মটা `VoucherRequest`-এ লেখা: প্রদেয়ে ক্রেডিট করলে কার কাছে দেনা
 * সেটা বলতেই হবে। ⓘ নাহলে খতিয়ানে **মালিকহীন টাকা** বসে — কাউকে দিতে
 * হবে, কিন্তু কাকে তা কোথাও লেখা নেই।
 *
 * ⚠️ ── আর এই নিয়মটা দুইবার নিভেছে ───────────────────────────────────
 * **প্রথমবার:** যাচাইটা বাবার id-র সাথে মেলাত, অথচ ব্যবহারকারী বাছেন
 * সন্তানদের একটা — তাই নিয়মটা **কোনোদিন চলতই না**। ⓘ ধরা পড়েছিল
 * ব্রাউজারে সেভ করে, **টেস্টে নয়**।
 *
 * **দ্বিতীয়বার:** প্রদেয় চার ঘরে ভাগ হওয়ার দিন `StandardChart::PAYABLE`
 * নেমে গেল `2111`-এ, আর যাচাইটা সেখান থেকে বংশধর খুঁজত — `2111`-এর
 * সন্তান নেই। ফলে পরিবহন ও হাম্মালির দেনায় নিয়মটা চলা বন্ধ করল, নীরবে।
 *
 * ⛔ **দুইবারই কোনো টেস্ট ধরেনি। এটা তৃতীয়বার ঠেকানোর জন্য।**
 *
 * ── ⭐ কেন কোডের তালিকা ধরে নয়, পরিবার ধরে ─────────────────────────
 * চারটা কোড হার্ডকোড করলে এটা **আজকের ছবির** পাহারা হত, নিয়মের নয় —
 * পঞ্চম ঘর যোগ হলে সে চুপচাপ বাদ পড়ত। তাই পরীক্ষাটা ছক থেকেই পরিবারটা
 * তুলে নেয়, আর **প্রতিটা সন্তানের উপর** নিয়মটা চালিয়ে দেখে।
 */
class CreditOnAnyPayableHeadNeedsAPartyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'PY', 'name_en' => 'Payable Co']);
        CompanyContext::set($this->company->id);
        app(StandardChart::class)->install();

        /*
         * অর্থবছর ছাড়া কোনো ভাউচার বসে না।
         *
         * ⓘ এটা প্রথম চেষ্টায় ধরা পড়েনি, কারণ assert-টা ছিল
         * `assertSessionDoesntHaveErrors('party')` — সে কেবল **ওই একটা
         * ঘর** দেখত। ভাউচার বসেনি *"তারিখটা কোনো অর্থবছরের মধ্যে পড়ে
         * না"* বলে, আর সেই বার্তাটা পরীক্ষার চোখের বাইরে ছিল।
         *
         * ⭐ **সরু assert ভুল কারণ ঢেকে দেয়** — `assertSessionHasNoErrors()`
         * বলল কী ভাঙছে, প্রথম চেষ্টাতেই।
         */
        FinancialYear::create([
            'name' => '2026-2027',
            'starts_on' => now()->startOfYear()->toDateString(),
            'ends_on' => now()->endOfYear()->toDateString(),
            'is_current' => true,
        ]);

        foreach (['accounts.voucher.create', 'accounts.report'] as $name) {
            // লাইভে `PermissionSyncer` বসায়; ফেলনা ডাটাবেসে সে চলে না
            Permission::findOrCreate($name, 'web');
        }

        $this->clerk = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->clerk->companies()->attach($this->company->id, ['is_active' => true]);
        $this->clerk->givePermissionTo(['accounts.voucher.create', 'accounts.report']);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    /**
     * প্রদেয়ের ঘরগুলো — ছক থেকে, হাতে লেখা তালিকা থেকে নয়।
     *
     * @return list<Account>
     */
    private function payableHeads(): array
    {
        $group = StandardChart::find(StandardChart::PAYABLE_GROUP);

        $this->assertNotNull($group, 'প্রদেয়ের দলটাই নেই');

        /*
         * ⚠️ ── `selfAndDescendants()` কেবল `id` ও `parent_id` আনে ──────
         *
         * বাকি প্রতিটা ঘর **null**: `code`, `name_bn`, `is_group`। তাই
         * সরাসরি ওটার উপর `->where('is_group', false)` চালানো কিছুই ছাঁকে
         * না, আর `pluck('code')` চারটা null ফেরায়।
         *
         * ⛔ **আর ওখানেই এই পাহারাটা অন্ধ হয়ে যেত:** প্রথমবার লিখে
         * চালানোর সময় তুলনাটা দাঁড়িয়েছিল **চারটা null-এর সাথে চারটা
         * null** — সংখ্যা মিলত, মান বলে কিছু থাকত না। ⓘ আমার
         * "সংখ্যাটা শূন্য নয়" পরীক্ষাটা **গণনা** ধরেছিল, **মান** নয়।
         * ⭐ পরের জন `assertNotEmpty` লিখে সন্তুষ্ট হওয়ার আগে এই লাইনটা
         * পড়ুন।
         *
         * ⓘ ফাঁদটা নতুন নয় — `AccountsFacts`-এর কমেন্টে আগেই লেখা আছে।
         * **তবু ওটা পড়ার পরেও এখানে পড়া হয়েছে**, তাই দ্বিতীয়বার লেখা।
         *
         * সমাধান: আইডিগুলো ওখান থেকে নাও, আর পুরো সারিগুলো আলাদা করে
         * তুলে আনো।
         */
        $ids = $group->selfAndDescendants()->pluck('id')->all();

        return Account::query()
            ->whereIn('id', $ids)
            ->where('is_group', false)
            ->orderBy('code')
            ->get()
            ->all();
    }

    private function expenseAccount(): Account
    {
        return StandardChart::find(StandardChart::HAMMALI)
            ?? StandardChart::find('5200');
    }

    /** @param array<string, mixed> $extra */
    private function save(Account $credit, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->clerk)->post(route('accounts.voucher.store', ['type' => 'expense']), [
            'type' => 'expense',
            'trx_date' => now()->toDateString(),
            'amount' => '1500',
            'to_account_id' => $this->expenseAccount()->id,
            'from_account_id' => $credit->id,
            'narration' => 'হাম্মালির বিল',
            ...$extra,
        ]);
    }

    /**
     * ⚠️ শূন্য সংগ্রহে চালানো পরীক্ষা সবসময় সবুজ।
     *
     * নিচের দুইটা পরীক্ষা প্রতিটা ঘরের উপর ঘোরে। ছকটা বদলে গেলে বা
     * খোঁজাটা কিছু না পেলে ওরা **নীরবে পাস** করত, আর পাহারাটা অলংকার
     * হয়ে যেত।
     */
    public function test_there_are_several_payable_heads_to_guard(): void
    {
        $heads = $this->payableHeads();

        $this->assertGreaterThanOrEqual(4, count($heads),
            'প্রদেয়ের নিচে চারটার কম ঘর — ছকটা কি বদলে গেছে?');

        foreach ($heads as $head) {
            $this->assertFalse((bool) $head->is_group, "{$head->code} একটা দল, পোস্টিং ঘর নয়");
        }
    }

    /** ⭐ পক্ষ ছাড়া — প্রতিটা ঘরেই ফেরত যায়। */
    public function test_without_a_party_no_payable_head_accepts_it(): void
    {
        foreach ($this->payableHeads() as $head) {
            $where = "{$head->code} ({$head->name_bn})";

            /*
             * ⚠️ লেবেলটা ছাড়া লাল বার্তাটা অসম্পূর্ণ থাকত।
             *
             * ভাঙা-পরীক্ষায় এই লাইনটাই লাল হয়েছিল, আর বার্তা ছিল কেবল
             * *"Session is missing expected key [errors]"* — **কোন ঘরে
             * ব্যর্থ হলো তা বলেনি।** ⓘ অথচ ঠিক ওই কথাটাই প্রমাণ:
             * ২১১১-তে নিয়মটা তখনো চলত, ২১১৬ · ২১১৭ · ২১১৮-তে নয়।
             *
             * ⭐ ছয় মাস পরে যিনি এই লাল দেখবেন তাঁর কাছে কেবল বার্তাটাই
             * থাকবে — এই কথোপকথনটা নয়। **আংশিক লাল হওয়াটাই আসল খবর,
             * তাই সেটা বার্তার ভেতরেই থাকতে হবে।**
             */
            $this->save($head);

            /*
             * ⚠️ ── যাচাইটা ফ্রেমওয়ার্কের, লেবেলটা আমার ──────────────────
             *
             * `assertInvalid()` নিজের বার্তা নেয় না, আর সেশনের ত্রুটি
             * হাতে পড়তে গিয়ে **দুইবার ভুল হয়েছে**: একবার
             * `session()->get('errors')` কাঁচা array দিল (`getBag()`
             * ভাঙল), আরেকবার `(array) session('errors')` চাবিটাই দিল না।
             *
             * ⛔ **আর দুইবারই লাল এসেছিল সেই ঘরে যেখানে নিয়মটা ঠিকই
             * চলছিল** — অর্থাৎ পাহারার নিজের ত্রুটি পাহারা-দেওয়া
             * জিনিসের ত্রুটির ছদ্মবেশে এসেছিল। **একই রঙ, দুই জগৎ।**
             *
             * তাই যাচাইটা ফ্রেমওয়ার্কের হাতেই ছেড়ে দেওয়া, আর লেবেলটা
             * বাইরে থেকে বসানো। ⓘ লেবেল ছাড়া ভাঙা-পরীক্ষার আসল খবরটা —
             * **কোন ঘরে নিয়মটা নিভেছে** — বার্তা থেকে পড়াই যেত না।
             */
            try {
                $this->save($head)->assertInvalid(['party']);
            } catch (AssertionFailedError) {
                $this->fail("{$where} — পক্ষ ছাড়াই ফর্মটা মেনে নিয়েছে; যাচাইটা এই ঘরে চলছে না");
            }

            $this->assertSame(0, DB::table('vouchers')->count(),
                "{$where} — পক্ষ ছাড়াই ভাউচার বসে গেছে");
        }
    }

    /** আর পক্ষ দিলে প্রতিটা ঘরেই বসে — নাহলে পাহারাটা কাজ থামাত। */
    public function test_with_a_party_every_payable_head_accepts_it(): void
    {
        $supplier = DB::table('suppliers')->insertGetId([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'company_id' => $this->company->id,
            'code' => 'SUP-0001',
            'name_en' => 'Karim Traders',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($this->payableHeads() as $head) {
            /*
             * ⚠️ `assertSessionDoesntHaveErrors('party')` যথেষ্ট নয়।
             *
             * ওটা কেবল ওই একটা ঘর দেখে — অন্য কোনো ঘরে ত্রুটি থাকলেও
             * পাস করত, আর ভাউচার না বসার আসল কারণটা লুকিয়ে থাকত।
             * ⓘ প্রথমবার ঠিক তা-ই হয়েছিল: টেস্ট বলল "পক্ষে ত্রুটি নেই",
             * অথচ একটাও ভাউচার বসেনি।
             */
            $this->save($head, ['party' => 'supplier:'.$supplier])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(count($this->payableHeads()), DB::table('vouchers')->count(),
            'পক্ষ দেওয়ার পরেও কোনো একটা ঘরে ভাউচার বসেনি');
    }

    /**
     * ⛔ আর ফর্মের তালিকাটাও পুরো পরিবার দেখায়।
     *
     * ⓘ নিয়মটা মানা গেল অথচ ঘরটা বাছাই করাই গেল না — তাহলে পাহারাটা
     * সত্যি হলেও পর্দাটা মিথ্যা। **এটাই সেই "চার থেকে এক" হয়ে যাওয়া।**
     */
    public function test_the_form_offers_every_payable_head(): void
    {
        /*
         * ⚠️ আগে স্ট্যাটাস, তারপর ভিউ।
         *
         * সরাসরি `viewData()` ডাকলে ব্যর্থতার বার্তা হয় *"The response is
         * not a view"* — যা সত্যি, কিন্তু **কেন** তা বলে না: ৪০৩? ৩০২?
         * ৫০০? ⓘ আজ এই শিক্ষাটা তিনবার এসেছে — **সরু assert ভুল কারণ
         * ঢেকে দেয়**, আর তখন মানুষ নিজের কোডে কারণ খোঁজে।
         */
        $page = $this->actingAs($this->clerk)
            ->get(route('accounts.voucher.create', ['type' => 'expense']));

        $page->assertOk();

        $shown = $page->viewData('creditAccounts');

        $this->assertSame(
            collect($this->payableHeads())->pluck('code')->sort()->values()->all(),
            collect($shown)->pluck('code')->sort()->values()->all(),
            'ফর্মের তালিকা আর ছকের ঘরগুলো এক নয়'
        );
    }
}

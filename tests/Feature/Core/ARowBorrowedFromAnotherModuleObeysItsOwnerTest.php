<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ধার করা সারিটা নিজের মালিকের কথা শোনে।
 *
 * ── ⭐ কেন একটা সারি ধার করা হয় ──────────────────────────────────────
 * মালিকের সীমানার টেবিল (২৪ সেপ্টেম্বর ২০২৬) বলে *"মাল গ্রহণ"*
 * Inventory-র কাজ, অথচ কাগজ-সেবা-রুট সবই আজ Purchase-এ। ⓘ তাই প্রথম
 * ধাপে **দরজাটা** সরানো হয়েছে: মজুদের তালিকায় একটা সারি, যা ক্রয়েরই
 * পর্দায় নিয়ে যায়।
 *
 * ── ⛔ আর তাতেই কোরের একটা ফাঁক দেখা গেল ────────────────────────────
 * মেনু আঁকার সময় দেখা হত **সারিটা যে মডিউলের তালিকায় আছে** তার সুইচ,
 * আর দরজায় দেখা হয় **রুটের নাম যে মডিউলের** তার সুইচ।
 *
 * ⚠️ একই মডিউলের সারিতে দুইটা সবসময় এক, তাই ফাঁকটা এতদিন অদৃশ্য ছিল।
 * ⛔ ধার করা সারিতে দুইটা আলাদা হয়ে যেত: ক্রয় বন্ধ করলে সারিটা মজুদের
 * তালিকায় **দেখা যেত**, আর ক্লিকে ৪০৪।
 *
 * ⓘ এটা সেই পুরনো রোগেরই উল্টো রূপ — *"সুইচটা ছিল আড়াল, বাধা নয়"*।
 * এবার আড়াল নয়, কেবল বাধা; আর দুইটাই একই ভুল, কারণ দুই ক্ষেত্রেই
 * ব্যবহারকারী যা দেখেন আর যা ঘটে, দুইটা আলাদা।
 */
final class ARowBorrowedFromAnotherModuleObeysItsOwnerTest extends TestCase
{
    use RefreshDatabase;

    /** ধার করা সারিটা — মজুদের তালিকায়, ক্রয়ের পর্দায়। */
    private const BORROWED = 'purchase.receipt.index';

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

    // ── ১ · সারিটা সত্যিই আছে ────────────────────────────────────────

    public function test_the_goods_receipt_row_stands_in_the_inventory_menu(): void
    {
        /*
         * ⚠️ এটা না দেখলে নিচের পাহারাগুলো উল্টো দিকেও সবুজ থাকত:
         * সারিটা যদি কোথাও না থাকত, *"বন্ধ করলে দেখা যায় না"* দাবিটা
         * এমনিতেই সত্যি হত — আর সবচেয়ে সবুজ পাহারা সেটাই, যেটা কিছুই
         * দেখে না।
         */
        $this->assertTrue($this->rowShowsIn('inventory'),
            'মালিকের সীমানার টেবিল বলে মাল গ্রহণ Inventory-র, অথচ মজুদের '
            .'তালিকায় সারিটাই নেই।');
    }

    // ── ২ · মালিক মডিউল বন্ধ হলে সারিটাও যায় ─────────────────────────

    public function test_switching_off_the_owning_module_takes_the_row_away(): void
    {
        /*
         * ⛔ এটাই আসল পাহারা। ⚠️ সারিটা থেকে গেলে ব্যবহারকারী ক্লিক
         * করতেন আর ৪০৪ পেতেন — আর ৪০৪ মানে *"এমন পাতা নেই"*, যেটা
         * তাঁর কাছে মিথ্যা: পাতাটা আছে, কেবল তাঁর প্রতিষ্ঠানে ক্রয়
         * মডিউলটা বন্ধ।
         */
        app(SettingsService::class)->set('purchase.enabled', false);

        $this->assertFalse($this->rowShowsIn('inventory'),
            'ক্রয় মডিউল বন্ধ, তবু মজুদের তালিকায় ক্রয়ের পর্দার সারিটা '
            .'দেখা যাচ্ছে — ক্লিকে ওটা ৪০৪ দেবে।');
    }

    public function test_the_door_and_the_menu_now_give_the_same_answer(): void
    {
        /*
         * ⓘ দুইটা উত্তর মেলানোই গোটা সংশোধনটার কারণ — একটা মিলিয়ে
         * অন্যটা না দেখলে পরের জন ভাবতেন কাজটা হয়ে গেছে।
         */
        app(SettingsService::class)->set('purchase.enabled', false);

        $this->get(route(self::BORROWED))->assertNotFound();
        $this->assertFalse($this->rowShowsIn('inventory'));
    }

    // ── ৩ · দরজা একটাই, আর পর্দার সুইচ ওটাকে শাসন করে ────────────────

    /**
     * ⭐ আগে এই দাবির নাম ছিল `…governs_both_lists_at_once`, আর সে
     * **দুইটা** সারি চাইত — ২৫ সেপ্টেম্বর ২০২৬-এ মালিকের সিদ্ধান্তে
     * ক্রয়ের সারিটা তুলে দেওয়া হয়েছে: *"মাল বুঝে নেওয়া purches theke
     * tule dite hobe"*।
     *
     * ⓘ তাঁর যুক্তি: *"ক্রয় চালান toiri hole inventory ta buje nibe"* —
     * মাল নামানো গুদামের কাজ, ক্রেতার নয়।
     *
     * ── ⛔ কেন দাবিটা মুছে না দিয়ে উল্টে দেওয়া হলো ──────────────────
     * ⚠️ পুরনো দাবিটা কেবল বাদ দিলে এই ফাইলে আর কেউ **ক্রয়ের তালিকা
     * দেখতই না**, আর কেউ একদিন সারিটা ফেরত বসালে কোনো পাহারা টের
     * পেত না। ⓘ তাই দাবিটা এখন উল্টো দিকে তাকায়: সারিটা মজুদে
     * **আছে**, আর ক্রয়ে **নেই**।
     */
    public function test_the_row_stands_in_one_list_only_and_the_screen_switch_governs_it(): void
    {
        $this->assertTrue($this->rowShowsIn('inventory'),
            'মাল গ্রহণের সারিটা মজুদের তালিকায় থাকার কথা।');

        /*
         * ⛔ এটাই মালিকের সিদ্ধান্তের পাহারা। ⚠️ দুইটা দরজা থাকলে
         * দুইটা অভ্যাস তৈরি হয়, আর নাম দুই জায়গায় দুই রকম হয়ে
         * যাওয়ার ঝুঁকিটাও ফিরে আসে — ঠিক যেটা এতদিন হয়েছিল
         * ("মাল বুঝে নেওয়া" বনাম "মাল গ্রহণ")।
         */
        $this->assertFalse($this->rowShowsIn('purchase'),
            'মাল গ্রহণের সারিটা ক্রয়ের তালিকা থেকে তুলে দেওয়া হয়েছে '
            .'(মালিকের সিদ্ধান্ত, ২৫ সেপ্টেম্বর ২০২৬), তবু ওটা ফিরে এসেছে।');

        /*
         * ⓘ সুইচটা ক্রয়েরই (`purchase.screen_receipts`) — সারিটা
         * মজুদে বসলেও। ⛔ মজুদের নিজের একটা সুইচ বানালে দুইটা সুইচ
         * হত, আর একটা বন্ধ করে মানুষ অবাক হতেন যে পর্দাটা তবু খোলে।
         */
        app(SettingsService::class)->set('purchase.screen_receipts', false);

        $this->assertFalse($this->rowShowsIn('inventory'),
            'পর্দার সুইচটা বন্ধ, তবু মজুদের তালিকায় সারিটা রয়ে গেছে।');
    }

    // ── ৪ · উল্টো দিকে কিছু ভাঙেনি ───────────────────────────────────

    public function test_a_modules_own_rows_are_untouched(): void
    {
        /*
         * ⚠️ নতুন শর্তটা প্রতিটা সারিতে চলে। ⛔ ভুল করে লিখলে নিজের
         * মডিউলের সারিগুলোও হারিয়ে যেত, আর গোটা মেনুটাই খালি হত।
         */
        app(SettingsService::class)->set('purchase.enabled', false);

        $this->assertTrue($this->rowShowsIn('inventory', 'inventory.product.index'),
            'ক্রয় বন্ধ করায় মজুদের **নিজের** সারিগুলোও উধাও হয়ে গেছে।');
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    /** ঐ মডিউলের তালিকায় রুটটার সারি দেখা যায় কি না। */
    private function rowShowsIn(string $moduleCode, string $route = self::BORROWED): bool
    {
        foreach (app(MenuBuilder::class)->forUser($this->owner->fresh()) as $module) {
            if (($module['code'] ?? null) !== $moduleCode) {
                continue;
            }

            foreach ($module['groups'] as $rows) {
                foreach ($rows as $row) {
                    if (($row['route'] ?? null) === $route) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}

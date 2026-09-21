<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchase;

use App\Models\Company;
use App\Models\User;
use App\Modules\Purchase\Dashboard\PurchaseDashboard;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * এক পর্দায় দরজা বন্ধ, পাশের পর্দায় খোলা।
 *
 * ── ⛔ ১৯ সেপ্টেম্বরের সিদ্ধান্ত, আর ২১ সেপ্টেম্বরের ছবি ─────────────
 * ১৯ তারিখে মালিকের নির্দেশে ক্রয় বিলের **তালিকা** থেকে "নতুন বিল"
 * তুলে দেওয়া হয়। কারণটা ঐ ফাইলেই লেখা: *বিল জন্মায় মাল গ্রহণে আর
 * সরাসরি ক্রয়ে, আপনা থেকে*।
 *
 * ⚠️ কিন্তু ড্যাশবোর্ডের টালিটা রয়ে গিয়েছিল। ২১ তারিখে মালিক ছবিতে
 * ঠিক ঐ বোতামটা দাগিয়ে লিখলেন: *"নতুন বিল bad diye সরাসরি ক্রয়
 * botam daw"*।
 *
 * ⓘ ফল রোজকার ভাষায়: ঐ দরজা দিয়ে ঢুকলে একটা **খালি** বিল খুলত, যার
 * পেছনে কোনো মাল গ্রহণ নেই — আর তখন মজুদ আর খাতা দুইটা আলাদা গল্প
 * বলত।
 *
 * ── ⭐ কেন এটা মনে রাখা যথেষ্ট নয় ──────────────────────────────────
 * সিদ্ধান্তটা নেওয়া হয়েছিল, লেখাও হয়েছিল — কেবল **সব দরজায়** পৌঁছায়নি।
 * ⛔ এটাই এই রিপোর চেনা ধরন: কাজটা হয়েছে, জোড়াটা বাদ পড়েছে, আর
 * কিছুই লাল হয়নি।
 */
final class TheDoorClosedOnOneScreenStayedOpenOnTheDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->switchCompany($company->id);
    }

    /**
     * ⭐ ড্যাশবোর্ডের **টালি**টা সরাসরি ক্রয়ে নিয়ে যায়।
     *
     * ── ⛔ পাতার HTML ধরে মাপা যায় না, ২১ সেপ্টেম্বর ২০২৬ ─────
     * প্রথম দফায় এই দাবিটা পাতায় `href="…direct/create"` আছে কি না
     * দেখত। ⚠️ কিন্তু বাঁ পাশের মেনুতেও ওই লিংকটা আছে
     * (`module.php`-এর transactions অংশ), তাই টালিটা **পুরো তুলে
     * দেওয়ার পরেও** মিউটেশনে দাবিটা সবুজ থেকেছিল।
     *
     * ⓘ হুবহু একই ভুল আজকে আরও একবার হয়েছে — মেনুর আইকনের
     * পাহারায়ও ভুল ঘরটা মাপা হচ্ছিল। ⭐ তাই এখন লেখা পড়া হয় না:
     * ড্যাশবোর্ডের সংজ্ঞাটাই পড়ে টালি ধরে ধরে দেখা হয়।
     */
    public function test_the_dashboard_tile_leads_to_direct_purchase(): void
    {
        $tiles = PurchaseDashboard::dashboard()->tiles;

        // ⚠️ খালি তালিকায় নিচের দাবি দুইটাই অর্থহীন হত
        $this->assertGreaterThan(2, count($tiles),
            'ড্যাশবোর্ডে টালিই নেই — দাবিটা তাহলে কিছুই মাপছে না।');

        $hrefs = array_map(fn ($t) => $t->href, $tiles);

        $this->assertContains(route('purchase.direct.create'), $hrefs, implode("\n", [
            'ড্যাশবোর্ডে "সরাসরি ক্রয়" টালিটা নেই।',
            '',
            'ⓘ মালিকের কথা: *"নতুন বিল bad diye সরাসরি ক্রয় botam daw"*।',
            '⚠️ মেনুতে লিংকটা থাকাই যথেষ্ট নয় — বোতামটা এখানেই চাইতেন।',
        ]));

        $this->assertNotContains(route('purchase.bill.create'), $hrefs,
            'ড্যাশবোর্ডের টালিতে এখনো খালি বিলের দরজা আছে।');
    }

    /**
     * ⛔ খালি বিলের দরজাটা আর কোথাও খোলা নেই।
     *
     * ── ⚠️ কেন এটা আলাদা দাবি ───────────────────────────────────────
     * উপরেরটা কেবল বলে নতুন বোতামটা **আছে**। ⓘ পুরনোটা পাশে রেখে
     * দিলেও ওটা সবুজ থাকত — আর তখন দুইটা দরজা, একটা ঠিক আর একটা
     * ঠিক সেই ভুলটা যেটা ১৯ তারিখে বন্ধ করা হয়েছিল।
     */
    public function test_the_blank_bill_door_is_not_offered_anywhere_on_it(): void
    {
        $this->assertStringNotContainsString(
            'href="'.route('purchase.bill.create').'"',
            $this->screen(),
            implode("\n", [
                'ড্যাশবোর্ড এখনো খালি বিলের দরজা খুলে দিচ্ছে।',
                '',
                '⛔ ১৯ সেপ্টেম্বরের সিদ্ধান্ত: বিল জন্মায় মাল গ্রহণে আর',
                '   সরাসরি ক্রয়ে — হাতে খোলা খালি বিলে নয়।',
                'ⓘ পেছনে মাল গ্রহণ না থাকলে মজুদ আর খাতা আলাদা গল্প বলে।',
            ]),
        );
    }

    /**
     * ⭐ পর্দাটা সত্যিই বোতাম এঁকেছে।
     *
     * ── ⛔ নিজের দাবির উপরেই অবিশ্বাস ───────────────────────────────
     * উপরের "নেই" দাবিটা একটা **খালি পাতাতেও** সবুজ থাকত — পর্দা
     * ভেঙে গেলে, অনুমতি আটকালে, বা টালিগুলো আদৌ না আঁকা হলে।
     * ⚠️ তাই আগে প্রমাণ করা হয় যে অন্য টালিগুলো ঠিকই আছে।
     */
    public function test_the_dashboard_really_drew_its_buttons(): void
    {
        $html = $this->screen();

        foreach ([
            'purchase.order.create' => 'নতুন আদেশ',
            'purchase.payment.index' => 'পরিশোধ',
            'purchase.bill.index' => 'ক্রয় বিল',
        ] as $route => $what) {
            $this->assertStringContainsString('href="'.route($route).'"', $html, implode("\n", [
                'ড্যাশবোর্ডে "'.$what.'" টালিটাও নেই।',
                '',
                '⛔ মানে পর্দাটা টালি আঁকেইনি — আর তখন "খালি বিলের দরজা নেই"',
                '   দাবিটা সত্য হত কেবল কিছুই না থাকায়।',
            ]));
        }
    }

    private function screen(): string
    {
        $response = $this->actingAs($this->owner)
            ->get(route('module.dashboard', ['module' => 'purchase']));

        $response->assertOk();

        return (string) $response->getContent();
    }
}

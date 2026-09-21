<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Posting\PostingException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * হিসাবে বসানোর "না" পর্দায় পড়া যায় — ৫০০ হয় না।
 *
 * ── ⛔ মালিক যা দেখেছেন, ২১ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * PBL-0002 নিশ্চিত করতে গিয়ে **"Something broke on our side"** আর একটা
 * ৫০০। ⓘ ভিতরে কিছুই ভাঙেনি: [[PostingEngine]] নিয়ম মেনে না বলেছিল —
 * *"অর্থবছর ২০২৬-২০২৭ বন্ধ"*।
 *
 * ⚠️ [[PostingException]]-এর কোনো `render()` ছিল না, তাই প্রতিটা বৈধ
 * অস্বীকার ৫০০ হয়ে বেরোত। ⛔ ব্যবহারকারী কারণটা জানতেন না, আর ভুলের
 * খাতায় এমন "ত্রুটি" জমত যা আসলে ব্যবস্থার ঠিক কাজ করার প্রমাণ।
 *
 * ── ⭐ কেন পাহারাটা এখানে, কোনো মডিউলে নয় ──────────────────────────
 * জোড়াটা `bootstrap/app.php`-এ, আর সেটা **কোরের**। ⓘ একই ইঞ্জিন দিয়ে
 * ক্রয়, বিক্রয়, ভাউচার — সবই বসে, তাই একটা মডিউলের পরীক্ষায় রাখলে
 * সেটা ঐ মডিউলের কথা বলত, আর নিয়মটা সবার।
 */
final class ARefusalToPostIsAnAnswerNotACrashTest extends TestCase
{
    /**
     * ⓘ রুটটা পরীক্ষা নিজেই বানায়, আসল কোনো পর্দা ডাকে না।
     *
     * ⚠️ ক্রয় বিলের পথ ধরে গেলে দাবিটা ক্রয় মডিউলের অর্ধেক নিয়মের
     * উপরও দাঁড়াত (বিল থাকতে হবে, অনুমতি লাগবে, বছর বন্ধ থাকতে হবে),
     * আর একদিন লাল হলে বোঝা যেত না ভুলটা কোথায়। ⛔ যা মাপা দরকার তা
     * একটাই: ব্যতিক্রমটা ছুঁড়লে কী বেরোয়।
     */
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/__posting-refusal', function (): never {
            throw new PostingException('অর্থবছর ২০২৬-২০২৭ বন্ধ।');
        });
    }

    public function test_the_screen_gets_the_reason_instead_of_a_five_hundred(): void
    {
        $response = $this->from('/__where-i-was')->get('/__posting-refusal');

        $response->assertStatus(302);
        $response->assertRedirect('/__where-i-was');
        $response->assertSessionHasErrors(['posting' => 'অর্থবছর ২০২৬-২০২৭ বন্ধ।']);
    }

    /**
     * ⭐ আর কারণটা সত্যিই বহন করা হয়, ঢাকা পড়ে না।
     *
     * ⛔ একটা সাধারণ *"বসানো যায়নি"* লিখে দেওয়া যেত, আর সেটা হত এই
     * ভুলেরই আরেক রূপ: ব্যবহারকারী জানতেন কিছু হয়নি, জানতেন না **কেন**
     * আর কী করলে হবে। ⓘ ইঞ্জিনের বার্তাগুলো ইচ্ছা করেই করণীয়সহ লেখা
     * ("ওটা খোলা একটা অনুমোদিত কাজ")।
     */
    public function test_the_engines_own_words_reach_the_user(): void
    {
        /*
         * ⓘ অন্য একটা বার্তা — আর এটাই এই দাবির পুরো কাজ। উপরেরটা
         * দেখায় একটা বার্তা পৌঁছায়; এটা দেখায় **যেটা ছোঁড়া হয়েছে
         * সেটাই** পৌঁছায়। ⛔ হ্যান্ডলারে একটা সাধারণ "বসানো যায়নি"
         * লিখে দিলে উপরেরটা লাল হত, কিন্তু কেউ হয়তো ঐ একটা স্ট্রিংই
         * দাবিতে বসিয়ে সবুজ করে ফেলত। দুইটা আলাদা বার্তা সেটা আটকায়।
         */
        Route::middleware('web')->get('/__other-refusal', function (): never {
            throw new PostingException('ডেবিট আর ক্রেডিট সমান নয়।');
        });

        $this->from('/__where-i-was')
            ->get('/__other-refusal')
            ->assertSessionHasErrors(['posting' => 'ডেবিট আর ক্রেডিট সমান নয়।']);
    }

    /**
     * ⓘ API-র পথে JSON, আর কোডটা ৪২২ — "তোমার অনুরোধে সমস্যা", ৫০০ নয়।
     *
     * ⚠️ মোবাইল অ্যাপ ৫০০ দেখলে "সার্ভার ডাউন" বলে দেখায় আর পরে আবার
     * চেষ্টা করে। ⛔ বছর বন্ধ থাকলে হাজারবার চেষ্টাতেও কিছু হবে না;
     * ব্যবহারকারীকে কারণটা পড়তে দেওয়াই একমাত্র কাজের উত্তর।
     */
    public function test_an_api_caller_gets_a_readable_refusal_too(): void
    {
        Route::middleware('api')->get('/api/__posting-refusal', function (): never {
            throw new PostingException('অর্থবছর ২০২৬-২০২৭ বন্ধ।');
        });

        $this->getJson('/api/__posting-refusal')
            ->assertStatus(422)
            ->assertJson(['message' => 'অর্থবছর ২০২৬-২০২৭ বন্ধ।']);
    }

    /**
     * পাহারাটা সত্যিই তাকায়।
     *
     * ⓘ উপরের দাবিগুলো সবুজ থাকত যদি Laravel **যেকোনো** ব্যতিক্রমকেই
     * ফিরিয়ে দিত। ⚠️ তাই একটা সাধারণ ব্যতিক্রম ছুঁড়ে দেখা হয় সে
     * এখনো ৫০০-ই — অর্থাৎ ছাড়টা [[PostingException]]-এর জন্যই, সবার
     * জন্য নয়।
     */
    public function test_an_ordinary_failure_is_still_a_five_hundred(): void
    {
        Route::middleware('web')->get('/__ordinary-break', function (): never {
            throw new \RuntimeException('সত্যিকারের ভাঙা');
        });

        $this->get('/__ordinary-break')->assertStatus(500);
    }
}

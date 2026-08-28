<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * অনেকগুলো বাছাইয়ের পর্দায় বোতামটা যেন হাতের কাছে থাকে।
 *
 * ── কোথা থেকে এল, ২৯ আগস্ট ২০২৬ ──────────────────────────────────────
 * মালিক তিন দিন ধরে বলছিলেন চেহারার পাতায় থিম বদলায় না। আসল কারণ ছিল
 * দুই ধাপ: কার্ডে ক্লিক, তারপর নিচে নেমে সংরক্ষণ খোঁজা — আর যিনি
 * বোতামটা পাননি, তাঁর কাছে বাছাইটা কাজই করত না।
 *
 * ওটা সারানোর পর প্রশ্নটা হলো: **একই রোগ আর কোথায়?** ১২৭টা পর্দা মেপে
 * দুইটা পাওয়া গেল —
 *
 *   • কন্ট্রোল প্যানেল — ৫৩টা সুইচ, শেষ সুইচ থেকে বোতাম ৫৫৯px নিচে
 *   • লেবেল ছাপা — ৬১টা পণ্যের ঘর, বোতামটা তালিকার বাইরে
 *
 * দুইটাতেই এখন একটা সাঁটা পটি: কয়টা বদলেছে বা কয়টা বাছা হয়েছে সেটা
 * লেখা, আর কাজের বোতামটা সেখানেই।
 *
 * ── কেন এখানে ক্লিকে-সংরক্ষণ নয় ──────────────────────────────────────
 * চেহারার পাতায় প্রতিটা বাছাই একটা সিদ্ধান্ত, তাই ক্লিকেই বসে।
 * সেটিংস দলবেঁধে চলে — "ভ্যাট চালু" আর "ভ্যাটের হার" একসাথে বদলাতে
 * হয়, আর মাঝপথে সেভ হলে কিছুক্ষণের জন্য কোম্পানির হিসাব ভুল থাকত।
 * তাই ওখানে বদল জমে, আর পটিটা গুনে বলে কয়টা জমেছে।
 */
class TheSaveButtonWasOffTheScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);
    }

    /**
     * যে পর্দায় গোনার মতো অনেক ঘর, সেখানে একটা সাঁটা পটি আছে।
     *
     * ── কেন `fixed` শব্দটাই দাবি ─────────────────────────────────────
     * সাধারণত ক্লাসের নাম পরীক্ষা করা বাজে অভ্যাস। এখানে নয়: "পটিটা
     * পর্দার সাথে থাকে" কথাটার মানেই `position: fixed`, আর ওটা সরালে
     * পটিটা আবার পাতার নিচে চলে যায় — অর্থাৎ ঠিক আগের সমস্যা, শুধু
     * নতুন markup দিয়ে।
     */
    public function test_a_screen_full_of_choices_keeps_its_action_within_reach(): void
    {
        $missing = [];

        $screens = [
            'কন্ট্রোল প্যানেল' => route('system_admin.control-panel'),
            'লেবেল ছাপা' => route('inventory.label.index'),
        ];

        foreach ($screens as $what => $url) {
            $html = (string) $this->actingAs($this->user)->get($url)->getContent();

            if (! str_contains($html, 'fixed inset-x-0 bottom-')) {
                $missing[] = "{$what} — সাঁটা পটিটা নেই";

                continue;
            }

            /*
             * পটিটা কেবল তখনই দেখা যায় যখন সত্যিই কিছু জমেছে। সবসময়
             * ভেসে থাকা একটা "সংরক্ষণ" পটি আসবাব হয়ে যায়, আর তখন
             * কেউ ওটা আর পড়ে না — যেটা না থাকার সমানই।
             */
            if (! str_contains($html, 'x-show="count > 0"') && ! str_contains($html, 'x-show="chosen > 0"')) {
                $missing[] = "{$what} — পটিটা সবসময় ভেসে থাকে, শর্ত ছাড়া";
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'অনেক বাছাইয়ের পর্দায় কাজের বোতামটা আবার হাতের বাইরে গেছে:',
            ...$missing,
            '',
            'বাছাই করতে করতে বোতামটা পর্দা থেকে হারিয়ে গেলে মানুষ ধরে',
            'নেন কিছুই হয়নি — তিন দিনের অভিযোগটা ঠিক এখান থেকেই এসেছিল।',
        ]));
    }

    /**
     * আর জমা বদলের সংখ্যাটা যেন সত্যি হয়।
     *
     * সংখ্যাটা ভুল হলে পটিটা মিথ্যা বলে — "৩টা বদল" দেখে সংরক্ষণ টিপে
     * দুইটা পাওয়া গেলে ওটা না থাকার চেয়েও খারাপ। তাই প্রতিটা ঘরের
     * পাশে তার **আগের মানটা** লেখা থাকে, আর গোনাটা ওটার সাথে মিলিয়েই
     * হয় — "একবার ছুঁয়েছি" গুনে নয়, নাহলে টিক দিয়ে আবার তুলে নিলেও
     * একটা বদল দেখাত।
     */
    public function test_the_count_compares_against_what_was_there_before(): void
    {
        $html = (string) $this->actingAs($this->user)
            ->get(route('system_admin.control-panel'))->getContent();

        $this->assertStringContainsString('data-was=', $html, implode("\n", [
            'সুইচগুলোর পাশে আগের মানটা আর লেখা নেই।',
            '',
            'ওটা ছাড়া গোনাটা "কয়টা ছোঁয়া হয়েছে" হয়ে যায় — টিক দিয়ে',
            'আবার তুলে নিলেও পটিটা একটা বদল দেখাত।',
        ]));

        $this->assertStringContainsString('el.dataset.was', $html,
            'গোনাটা আর আগের মানের সাথে মেলানো হচ্ছে না।');
    }
}

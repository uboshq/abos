<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Dashboard\DashboardEngine;
use ReflectionMethod;
use Tests\TestCase;

/**
 * "০ ফুরিয়ে আসছে" একটা সুখবর, করণীয় নয়।
 *
 * ── কেন এটার নিজের পরীক্ষা লাগে ──────────────────────────────────────
 * ড্যাশবোর্ডের "করণীয়" ঘরটা মডিউলের উইজেট থেকেই আসে, আর ইঞ্জিন শূন্য
 * সারিগুলো ছেঁকে ফেলে — নাহলে প্রতিদিন কয়েকটা শূন্য বসে থাকত, আর
 * যেদিন সত্যিই কিছু আটকাত সেদিন সেটা ওই শূন্যগুলোর ভিড়ে হারাত।
 *
 * নিয়মটা প্রথমে ছিল `!== '0'`, আর সেটা **তিনবার ভুল করেছে**:
 *
 *   `0.00`    টাকার উইজেট মান সাজিয়ে পাঠায় → শূন্য তিনটা সারি বসে ছিল
 *   `০.০০`    বাংলা অঙ্কে একই কথা
 *   `0 / 0`   এইচআরের "আজ উপস্থিত" — কর্মীই নেই, তবু করণীয় হিসেবে বসত
 *
 * আর ঠিক ততটাই জরুরি হলো **যা বাদ পড়া উচিত নয়**: `0 / 5` মানে পাঁচজন
 * আছেন অথচ একজনেরও হাজিরা বসেনি — ওটাই দিনের সবচেয়ে জরুরি করণীয়।
 * শুধু "শূন্য দেখলেই বাদ" লিখলে ওটাও হারাত।
 *
 * ── কেন reflection ───────────────────────────────────────────────────
 * নিয়মটা ইঞ্জিনের ভেতরের, আর সেটাই ঠিক জায়গা — বাইরে থেকে কেউ এটা
 * ডাকে না। পুরো ইঞ্জিন দিয়ে পরীক্ষা করতে হলে একটা মডিউল, তার উইজেট
 * আর একজন ব্যবহারকারী বানাতে হত, আর তখন পরীক্ষাটা **নিয়মটার নয়,
 * সাজানোর** পরীক্ষা হয়ে যেত — ভাঙলে বোঝা যেত না কোথায় ভাঙল।
 */
class AZeroIsNotSomethingToDoTest extends TestCase
{
    /**
     * মান => শূন্য হিসেবে বাদ পড়া উচিত কি না।
     *
     * ডেটা-প্রোভাইডার নয়, একটা তালিকা: এই রিপোর প্রায় সব পরীক্ষা
     * **সবগুলো ভুল একসাথে** দেখায় (`assertSame([], $problems)`), আর
     * প্রোভাইডার ব্যবহার করলে প্রথম ভুলটাতেই থেমে যেত।
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    private static function values(): array
    {
        return [
            'plain zero' => ['0', true],
            'money zero' => ['0.00', true],
            'bangla money zero' => ['০.০০', true],
            'nobody out of nobody' => ['0 / 0', true],
            'bangla nobody out of nobody' => ['০ / ০', true],
            'negative zero' => ['-0.0000', true],
            'zero with a word' => ['0 items', true],
            'nothing at all' => ['', true],

            /* ── এই সারিগুলো বাদ পড়লে চলবে না ────────────────────── */
            'nobody out of five' => ['0 / 5', false],
            'a real amount' => ['1,200', false],
            'a real amount with paisa' => ['1,000.00', false],
            'bangla amount' => ['১,২০০', false],
            'words, not a number' => ['Never', false],
            'bangla words' => ['কখনো নয়', false],
        ];
    }

    public function test_a_reminder_is_dropped_only_when_every_number_in_it_is_zero(): void
    {
        $reads = new ReflectionMethod(DashboardEngine::class, 'readsAsZero');
        $reads->setAccessible(true);

        $wrong = [];

        foreach (self::values() as $case => [$value, $expected]) {
            $got = $reads->invoke(null, $value);

            if ($got !== $expected) {
                $wrong[] = $expected
                    ? "{$case}: \"{$value}\" শূন্য, তবু করণীয় হিসেবে বসে থাকবে"
                    : "{$case}: \"{$value}\" শূন্য নয়, অথচ বাদ পড়ে যাবে";
            }
        }

        $this->assertSame([], $wrong, implode("\n", array_merge(
            ['করণীয়ের শূন্য-নিয়মটা এই মানগুলোয় ভুল উত্তর দিচ্ছে:'],
            $wrong,
        )));
    }
}

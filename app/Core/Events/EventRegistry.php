<?php

declare(strict_types=1);

namespace App\Core\Events;

use App\Core\Module\ModuleRegistry;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * কোন মডিউল কী ঘোষণা করে, আর কে কার কথা শোনে — প্ল্যান WP-0.3।
 *
 * ── কেন একটা রেজিস্ট্রি, সরাসরি Laravel-এর ইভেন্ট নয় ─────────────────
 * Laravel-এর ইভেন্ট ব্যবস্থাই চলছে নিচে; এই ক্লাসটা তার বদলে নয়, তার
 * উপরে। যোগ করে দুইটা জিনিস, আর দুইটাই শৃঙ্খলার:
 *
 * **এক — প্রকাশের তালিকা।** মডিউল বলে দেয় সে কোন ঘটনাগুলো ঘোষণা করে।
 * এটা একটা চুক্তি: অন্য মডিউল ওই তালিকা দেখে ঠিক করে কার কথা শুনবে।
 * তালিকা না থাকলে জানার একমাত্র উপায় হত পুরো কোডবেসে `event(` খোঁজা,
 * আর তখন কোনটা ইচ্ছাকৃত চুক্তি আর কোনটা ভেতরের খুঁটিনাটি তা বোঝা যেত না।
 *
 * **দুই — শোনার নিবন্ধন, কোরে মডিউলের নাম না লিখে।** শ্রোতাগুলো
 * EventServiceProvider-এ লিখলে কোর প্রতিটা মডিউলের ক্লাসের নাম জানত
 * (§১৯.৭ ভাঙত), আর নতুন মডিউল যোগ করতে কোর ফাইল খুলতে হত।
 *
 * ── এখনো কিউ নেই, ইচ্ছাকৃতভাবে ───────────────────────────────────────
 * শ্রোতা একই অনুরোধে, একই প্রক্রিয়ায় চলে। কিউ যোগ করার মানে হত আরেকটা
 * চলমান অংশ (worker, supervisor, ব্যর্থ কাজের টেবিল) — অফিসের একটা
 * মেশিনে যেটা একদিন চুপচাপ বন্ধ হয়ে থাকত, আর কেউ টের পেত না।
 *
 * যেদিন সত্যিই লাগবে (SMS, ইমেইল, বড় রিপোর্ট), শ্রোতাটা ShouldQueue
 * বাস্তবায়ন করলেই হবে — ঘোষণার জায়গা বদলাবে না।
 */
class EventRegistry
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly Dispatcher $dispatcher,
    ) {}

    /**
     * প্রতিটা মডিউলের ঘোষিত শ্রোতাদের বসিয়ে দেওয়া।
     *
     * বুট-টাইমে একবার। ক্রমটা নির্ভরতার — যে মডিউল আগে দাঁড়ায় তার
     * শ্রোতা আগে চলে, আর সেটাই আশা করা যায়।
     */
    public function register(): void
    {
        foreach ($this->modules->all() as $module) {
            foreach ($module->listeners as $event => $listeners) {
                foreach ($listeners as $listener) {
                    $this->dispatcher->listen($event, $listener);
                }
            }
        }
    }

    /**
     * ব্যবস্থার সব ঘোষিত ঘটনা — মডিউল ধরে।
     *
     * Governance-এর পর্দায় ও ডকুমেন্টেশনে এটাই উৎস। হাতে লেখা কোনো
     * তালিকা নেই, তাই তালিকাটা কোনোদিন পুরনো হয় না।
     *
     * @return array<string, list<class-string<DomainEvent>>>
     */
    public function published(): array
    {
        $published = [];

        foreach ($this->modules->all() as $module) {
            if ($module->events !== []) {
                $published[$module->code] = $module->events;
            }
        }

        return $published;
    }

    /**
     * এই ঘটনাটা কে কে শোনে।
     *
     * @return list<class-string>
     */
    public function listenersFor(string $event): array
    {
        $listeners = [];

        foreach ($this->modules->all() as $module) {
            foreach ($module->listeners[$event] ?? [] as $listener) {
                $listeners[] = $listener;
            }
        }

        return $listeners;
    }
}

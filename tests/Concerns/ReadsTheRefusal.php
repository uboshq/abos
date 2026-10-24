<?php

declare(strict_types=1);

namespace Tests\Concerns;

/**
 * ফেরানো অনুরোধের কারণ — সেশনের ভুলের ব্যাগ থেকে, দুই আকারেই।
 *
 * ── কেন লাগল, ২৩ অক্টোবর ২০২৬ ───────────────────────────────────────
 * `assertSessionHasErrors('x')` ভাঙলে PHPUnit বলে কেবল "Session is missing expected key [errors]" — দাবির নিজের ⛔ বার্তা
 * নয়, তাই মিউট্যান্ট সেই বার্তায় মরলে কোন পাহারা ভাঙল বোঝা যায় না (post-freeze-এর ছয়টা মিউট্যান্ট)। আর সেশন json-এ রাখা
 * হয় বলে ব্যাগটা কখনো ViewErrorBag, কখনো সাধারণ অ্যারে ({default: {format, messages}}) — `->all()` তখন Error দেয়
 * (HV1, HV2)। এখানে দুটোই পড়া হয়, আর দাবি নিজের বার্তায় assert করে।
 */
trait ReadsTheRefusal
{
    /** শেষ অনুরোধে `$key`-এর প্রথম কারণ, না থাকলে null */
    protected function refusalOn(string $key): ?string
    {
        $messages = $this->refusalMessages();

        return isset($messages[$key][0]) ? (string) $messages[$key][0] : null;
    }

    /**
     * শেষ অনুরোধের সব কারণ, ঘর ধরে।
     *
     * @return array<string, list<string>>
     */
    protected function refusalMessages(): array
    {
        $bag = session('errors');

        if ($bag === null) {
            return [];
        }

        $messages = is_array($bag)
            ? (($bag['default'] ?? $bag)['messages'] ?? [])
            : $bag->getBag('default')->toArray();

        return is_array($messages) ? $messages : [];
    }
}

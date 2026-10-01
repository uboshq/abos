<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use App\Core\Support\ViewedBranch;
use Illuminate\Database\Eloquent\Builder;

/**
 * ⭐ তালিকায় কেবল হেডারে বাছা শাখার সারি — মালিকের নির্দেশ, ১ অক্টোবর ২০২৬:
 * *"প্রতিটা শাখা পুরোপুরি আলাদা; একসাথে কেবল 'সব শাখা'-য়"*।
 *
 * ── ⛔ কেন গ্লোবাল স্কোপ নয় ───────────────────────────────────────────
 * মূলধন, উত্তোলন, জমা, ভাড়া — এদের সারি হিসাবেও পড়া হয়: মুনাফা-ভাগ গোটা মূলধন ধরে,
 * উত্তোলনের সীমা মানুষ ধরে মাসের সব উত্তোলন গোনে। গ্লোবাল স্কোপ হলে এক শাখা বাছা
 * থাকা অবস্থায় ঐ হিসাবগুলো চুপচাপ অর্ধেক হত। ⇒ দেয়াল কেবল **দেখানোয়** — তালিকা,
 * ট্যাবের গোনা, ব্যাজ — আর সেখানে নাম ধরে ডাকতে হয় (`->inViewedBranch()`)।
 *
 * ⓘ নিয়ম [[ViewedBranch::narrow()]]-এর: এক শাখা → কেবল সেটা (শাখাহীন নয়); "সব শাখা" →
 * নাগাল + শাখাহীন। [[Customer::scopeInViewedBranch()]]-এর একই ছাঁচ।
 */
trait ListedInViewedBranch
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInViewedBranch(Builder $query): Builder
    {
        return ViewedBranch::narrow($query, $query->getModel()->getTable().'.branch_id');
    }
}

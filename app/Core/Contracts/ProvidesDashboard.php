<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use App\Core\Engines\Dashboard\DashboardDefinition;

/**
 * একটা মডিউল নিজের ড্যাশবোর্ড নিজে ঘোষণা করে।
 *
 * ── কেন চুক্তি, কোরে একটা তালিকা নয় ──────────────────────────────────
 * কোরে `['inventory' => ..., 'sales' => ...]` লিখলে কোর মডিউলের নাম
 * জেনে ফেলত (§১৯.৭), আর ত্রয়োদশ মডিউল যোগ করতে কোর ফাইল খুলতে হত।
 * এখন মডিউল `module.php`-তে ক্লাসটা ঘোষণা করে, আর কোর কেবল ডাকে।
 *
 * ── কেন `static` ─────────────────────────────────────────────────────
 * ঘোষণাটা মডিউলের সম্পত্তি, কোনো নির্দিষ্ট অনুরোধের নয় —
 * [[ProvidesMetrics]] আর [[DashboardWidgets]] দুইটাই একই কারণে static।
 */
interface ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition;
}

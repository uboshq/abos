<?php

declare(strict_types=1);

namespace App\Modules\Customer\Dashboard;

use App\Core\Contracts\DashboardWidgets;
use App\Core\Dashboard\Widget;
use App\Core\Services\SettingsService;
use App\Core\Support\Money;
use App\Modules\Customer\Models\Customer;

/**
 * সীমা ছাড়ানোর সতর্কতা — "যা করা বাকি" দলে।
 *
 * ── কেন আলাদা কোনো সতর্কবার্তার ব্যবস্থা নয় ─────────────────────────
 * স্পেক চেয়েছিল থ্রেশহোল্ড অ্যালার্ট। আলাদা একটা ব্যবস্থা বানালে সেটা
 * রোজ একটা করে বার্তা পাঠাত, আর দুই সপ্তাহে মানুষ ওটা পড়া বন্ধ করে
 * দিত — যেমন প্রতিটা রিপোর্টের মাথায় বসানো "Data Quality Warning"
 * করত। যা আছে তাই যথেষ্ট: হোম পর্দার করণীয় সারি, যেটা মডিউল নিজে দেয়
 * আর ক্লিক করলে ঠিক ওই লোকগুলোর তালিকায় নিয়ে যায় (নিয়ম ১)।
 *
 * ── কেন সীমাগুলো সেটিংসের সারি ──────────────────────────────────────
 * এক ডিপোর "বেশি বকেয়া" আরেক ডিপোর রোজকার অবস্থা। কোডে একটা সংখ্যা
 * বসালে হয় সতর্কতাটা কারও কাছে অর্থহীন হত, নয় কারও কাছে চিরকাল লাল।
 */
final class CustomerWidgets implements DashboardWidgets
{
    /** @return list<Widget> */
    public static function widgets(): array
    {
        $settings = app(SettingsService::class);

        $widgets = [];

        if ($settings->get('customer.alert_over_limit', true)) {
            $widgets[] = self::overTheirLimit();
        }

        /*
         * টাকার সীমাটা ০ মানে বন্ধ, "শূন্য টাকার সীমা" নয়।
         *
         * উল্টোটা ধরলে সুইচটা চালু করা মাত্রই প্রতিদিন সতর্কতা আসত,
         * কারণ বকেয়া সবসময়ই শূন্যের বেশি।
         */
        $ceiling = (string) $settings->get('customer.alert_receivable_over', 0);

        if (bccomp($ceiling, '0', 4) > 0) {
            $widgets[] = self::receivableAbove($ceiling);
        }

        return $widgets;
    }

    /**
     * ধারের সীমা ছাড়িয়ে যাওয়া গ্রাহক।
     *
     * সংখ্যাটা আর তালিকাটা একই কোয়েরি থেকে (`overCreditLimit`), তাই
     * "৩ জন" দেখে ক্লিক করে চারজন পাওয়ার সুযোগ নেই।
     */
    private static function overTheirLimit(): Widget
    {
        $count = Customer::query()->active()->overCreditLimit()->count();

        return new Widget(
            group: 'todo',
            label: __('customer::dashboard.over_limit'),
            value: (string) $count,
            href: route('customer.index', ['over_limit' => 1]),
            permission: 'customer.view',
            tone: $count > 0 ? 'warn' : 'neutral',
            sort: 60,
            icon: 'customer',
        );
    }

    /**
     * মোট বকেয়া সীমার উপরে।
     *
     * ── কেন সংখ্যাটা টাকা, আর গোনা নয় ───────────────────────────────
     * "কতজনের বকেয়া আছে" প্রশ্নটার উত্তর রোজই বড় একটা সংখ্যা, আর
     * ওটা দেখে কিছু করার নেই। যেটা দেখে করার আছে সেটা হলো মোট টাকাটা
     * কত — কারণ ওটাই ব্যবসার বাইরে পড়ে থাকা মূলধন।
     */
    private static function receivableAbove(string $ceiling): Widget
    {
        $total = (string) (Customer::query()->active()->withOutstanding()->get()
            ->reduce(fn (string $sum, Customer $customer) => bcadd($sum, $customer->outstanding(), 4), '0'));

        $over = bccomp($total, $ceiling, 4) > 0;

        return new Widget(
            group: 'todo',
            label: __('customer::dashboard.receivable_over', ['limit' => Money::format($ceiling)]),
            value: Money::format($total),
            href: route('customer.report.show', ['slug' => 'due-list']),
            permission: 'customer.report',
            tone: $over ? 'warn' : 'neutral',
            sort: 61,
            icon: 'wallet',
        );
    }
}

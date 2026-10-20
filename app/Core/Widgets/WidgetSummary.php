<?php

declare(strict_types=1);

namespace App\Core\Widgets;

use App\Models\User;

/**
 * ⭐ একটা হোম-স্ক্রিন উইজেটের সার্ভার-অংশ — ফোনের উইজেট যা দেখায় তার সারাংশ (মোবাইলের পরিকল্পনা ৪, অংশ ৯; মালিকের আদেশ,
 * ২০ অক্টোবর ২০২৬; দলের নিয়ম E:\ABOS\app-widgets-ভিত্তি.md)।
 *
 * ⭐ দরজা একটাই — `GET /api/v1/widgets/summary` ([[WidgetSummaryController]]) — সব উইজেটের সারাংশ এক ডাকে।
 * প্রতিটা উইজেট এই চুক্তির একটা ক্লাস, নিজের মডিউলে, আর [[WidgetSummaries::CLASSES]]-এ এক লাইন।
 *
 * ⛔ নিয়ম (প্রতিটার দাবিতে):
 *  - কেবল পড়া — কোনো লেখা, কোনো লক নয়;
 *  - সংখ্যা ওয়েবের একই উৎস থেকে (মেট্রিক, ফ্যাক্ট, রিপোর্ট) — নিজে আলাদা করে গোনা নয়;
 *  - টাকা স্ট্রিং, bcmath ("1234.50"), ফোন সাজায়;
 *  - গ্রাহকের নাম "নাম · পয়েন্ট · শাখা" (Customer::nameWithPoint() আর শাখা), সরবরাহকারীর পাশে শাখা;
 *  - ব্যবহারকারীর শাখা-সীমা আর ডিলার-সীমা মানা (বিক্রয়কর্মী কেবল নিজের ডিলার) — ওয়েবের তালিকার একই scope;
 *  - বেতন নয়।
 */
interface WidgetSummary
{
    /** ফোনের উইজেটের চাবি — `sales_today`; ফোনের LauncherWidgetKind.key-এর সাথে হুবহু এক */
    public function key(): string;

    /** যে ফোন-মডিউলের সুইচ মানে ([[PhoneModules]]) — `sales`, `accounts`, … */
    public function module(): string;

    /** যে চাবি লাগে — ওয়েবের একই তালিকার চাবি (যেমন `sales.invoice.view`); null মানে সাইন-ইন করা প্রত্যেক কর্মী (নোটিশ, দ্রুত কাজ) */
    public function permission(): ?string;

    /** সারাংশ — ছোট, কেবল উইজেট যা দেখায়; সারি হলে বড় মাপের জন্য সর্বোচ্চ ৮টা */
    public function data(User $user): array;
}

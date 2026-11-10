<?php

declare(strict_types=1);

namespace App\Core\Engines\Assistant;

use App\Models\User;

/**
 * ⭐ কোপাইলটের একটা "দরজা" (টুল) — কেবল পড়ার, নিজের চাবির পেছনে (পূর্ণ পরিকল্পনা অংশ ৫ ও ১০)।
 *
 * ⓘ মডিউল নিজে দরজা দেয় (`module.php` → `assistant_tools`), কোর কোনো মডিউলের নাম জানে না ([[ToolRegistry]])।
 * ⓘ ভাষার মডেল কেবল দরজার নাম, কাজের বর্ণনা আর মানের ছক জানে ([[describe()]]) — ভেতরের তথ্য বা ডাটাবেস নয়। মডেল বলে "এই দরজা,
 *   এই মান"; দরজা নিজেই চাবি যাচাই করে, হিসাব মডিউলের নিজের সংজ্ঞা থেকে আনে, আর প্রতিটা সংখ্যার উৎস (লিংক) সাথে দেয়।
 * ⛔ দরজা কখনো লেখে না — কাগজ, টাকা, মজুদ কিছুই বদলায় না। খসড়া আলাদা পথে, মানুষের চাপে ([[DraftController]])।
 */
interface AssistantTool
{
    /** অনন্য নাম — "sales.top_customers", "credit.overdue" */
    public static function name(): string;

    /** যে চাবি লাগে — না থাকলে দরজা ডাকাই হয় না, খাতায় "আটকানো" ওঠে */
    public static function permission(): string;

    /**
     * মডেলের জন্য বর্ণনা — বাংলা আর ইংরেজিতে কী করে, আর মানের ছক (JSON Schema-র ছোট রূপ)।
     *
     * @return array{description: array{bn: string, en: string}, parameters: array<string, array{type: string, description: string, enum?: list<string>}>, required?: list<string>}
     */
    public static function describe(): array;

    /**
     * ⭐ দরজা খোলা — চলতি কোম্পানি, চলতি মানুষের চোখে (তাঁর শাখার দেয়াল, খরচের চাবি)।
     *
     * @param  array<string, mixed>  $args  মডেল বা শব্দ-তালিকা থেকে আসা মান — যাচাই দরজা নিজে করে
     * @return array{rows: list<array<string, string>>, columns: list<string>, summary: string, sources: list<array{label: string, href: string}>}
     */
    public function run(User $user, array $args): array;
}

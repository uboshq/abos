<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;

/**
 * তালিকার পর্দায় "Sort by" — এক জায়গায়, সব স্ক্রিনের জন্য।
 *
 * প্রতিটা কন্ট্রোলারে হাতে লিখলে একটায় অজানা মান এলে ৫০০ পড়ত, আরেকটায়
 * ডিফল্ট থাকত না, আর তৃতীয়টায় ব্যবহারকারীর পাঠানো লেখা সরাসরি
 * orderBy()-তে চলে যেত — অর্থাৎ SQL ইনজেকশনের দরজা।
 *
 * এখানে বাছাইটা সবসময় একটা ঘোষিত মানচিত্র থেকে হয়, তাই বাইরের কোনো
 * লেখা কখনো কোয়েরিতে পৌঁছায় না।
 */
trait SortsLists
{
    /**
     * বাছাই বসিয়ে দেয় এবং কোন বাছাইটা চলছে তা ফেরত দেয়।
     *
     * তালিকার প্রথম সারিটাই ডিফল্ট — তাই মানচিত্রে সবচেয়ে অর্থবহ
     * বাছাইটা আগে লিখতে হয় ("সবচেয়ে বেশি বকেয়া আগে"), বর্ণানুক্রম নয়।
     * ব্যবহারকারী তালিকা খুলেই যেন কাজের সারিগুলো উপরে পান।
     *
     * @param  array<string, callable(Builder): mixed>  $sorts
     */
    protected function applySort(Builder $query, Request $request, array $sorts): string
    {
        $chosen = (string) $request->query('sort', '');

        // অজানা মান নীরবে ডিফল্টে ফেরে — ব্যবহারকারী পুরনো বুকমার্ক
        // খুললে বা কেউ URL হাতে বদলালে পাতাটা ভাঙার কোনো কারণ নেই
        if (! array_key_exists($chosen, $sorts)) {
            $chosen = (string) array_key_first($sorts);
        }

        $sorts[$chosen]($query);

        return $chosen;
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * চলমান ব্যালেন্স — ডেবিট বিয়োগ ক্রেডিট, সারির পর সারি।
 *
 * আলাদা করে বের করা হয়েছে কারণ কাজটা এক জায়গার নয়। প্রথমে এটা ছিল
 * Report engine-এর ভেতরে private, আর গ্রাহকের পর্দায় লেজার দেখাতে গিয়ে
 * একই হিসাব দ্বিতীয়বার লিখতে হচ্ছিল। দুই জায়গায় দুই হিসাব মানে একদিন
 * একটায় গোলযোগ ঠিক হবে আর অন্যটায় থেকে যাবে — আর কোন সংখ্যাটা সত্যি
 * তা বলার উপায় থাকবে না।
 *
 * bcmath, float নয় (সেকশন ৮)। float-এ ০.১ + ০.২ ০.৩ নয়, আর হাজার সারির
 * লেজারে সেই ভুলগুলো জমে দৃশ্যমান হয়ে ওঠে।
 */
final class RunningBalance
{
    private const SCALE = 4;

    public function __construct(private string $balance = '0') {}

    /**
     * একটা সারি যোগ করে নতুন ব্যালেন্স ফেরত দেয়।
     *
     * ফেরত দেয় বলেই ডাকার জায়গায় আলাদা করে current() ডাকতে হয় না —
     * map()-এর ভেতরে এক লাইনেই বসে।
     */
    public function add(int|float|string|null $debit, int|float|string|null $credit): string
    {
        $this->balance = bcadd(
            $this->balance,
            bcsub($this->normalise($debit), $this->normalise($credit), self::SCALE),
            self::SCALE,
        );

        return $this->balance;
    }

    public function current(): string
    {
        return $this->balance;
    }

    /**
     * অনেকগুলো সারি একবারে — শুধু যোগফল দরকার হলে।
     *
     * আগের পাতাগুলোর সমষ্টি বের করতে এটাই ব্যবহার হয়: পাতা ৩-এর প্রথম
     * সারির ব্যালেন্স ঠিক হতে হলে পাতা ১ ও ২-এর সব সারি যোগ করে নিতে হয়,
     * নাহলে প্রতিটা পাতা শূন্য থেকে শুরু হত আর শেষ পাতার ব্যালেন্স
     * গ্রাহকের আসল পাওনার সাথে মিলত না।
     *
     * @param  iterable<mixed>  $rows
     * @param  callable(mixed): (int|float|string|null)  $debit
     * @param  callable(mixed): (int|float|string|null)  $credit
     */
    public static function sumOf(iterable $rows, callable $debit, callable $credit, string $opening = '0'): string
    {
        $running = new self($opening);

        foreach ($rows as $row) {
            $running->add($debit($row), $credit($row));
        }

        return $running->current();
    }

    private function normalise(int|float|string|null $value): string
    {
        // (string) null হয় খালি স্ট্রিং, আর bcmath সেটাকে ০ ধরে না —
        // ত্রুটি দেয়। তাই null আলাদা করে ধরা হয়।
        return $value === null || $value === '' ? '0' : (string) $value;
    }
}

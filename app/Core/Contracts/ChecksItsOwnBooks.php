<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use App\Core\Integrity\IntegrityCheck;

/**
 * যে মডিউল নিজের খাতা নিজে যাচাই করতে জানে।
 *
 * ── কেন মডিউল বলে, কোর নয় ───────────────────────────────────────────
 * কোর জানে না বিক্রয় বিলের মোট কীভাবে তৈরি হয়, বা লটের যোগফল কীসের
 * সমান হওয়ার কথা। যে নিয়মটা লিখেছে, সেই-ই কেবল বলতে পারে নিয়মটা
 * ভেঙেছে কি না (সেকশন ১৯.৭)। কোর শুধু জিজ্ঞেস করে আর ফল সাজিয়ে দেখায়।
 */
interface ChecksItsOwnBooks
{
    /** @return list<IntegrityCheck> */
    public static function checks(): array;
}

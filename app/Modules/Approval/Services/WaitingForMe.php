<?php

declare(strict_types=1);

namespace App\Modules\Approval\Services;

use App\Core\Engines\Approval\ApprovalEngine;

/**
 * ⭐ মেনুর ব্যাজ — এই মানুষের অনুমোদনের অপেক্ষায় কয়টা (মেনুর `count`, [[MenuBuilder::badge()]]; ফোনের নিচের বারে অনুমোদনের কোণে)।
 *
 * ⓘ টপবারের ✔-এর একই প্রশ্ন ([[shell.approval-quick-access]]: `pendingQueryFor($user)`) — ⛔ আলাদা গোনা লিখলে দুই জায়গা দুই
 * সংখ্যা বলত (মালিক, ৩১ অক্টোবর ২০২৬: নিচের বারে অনুমোদনের কোণে সংখ্যা নেই, অথচ ওপরে ৩)।
 */
final class WaitingForMe
{
    public function __construct(private readonly ApprovalEngine $engine) {}

    public function pendingCount(): int
    {
        $user = auth()->user();

        // ⓘ টপবারের একই শর্ত — চালু কোনো ছকে সইদাতা না হলে শূন্য ([[ApprovalEngine::signers()]]: আইডি => নাম)
        return $user === null || ! array_key_exists((int) $user->id, $this->engine->signers())
            ? 0
            : $this->engine->pendingQueryFor($user)->count();
    }
}

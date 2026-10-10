<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models\Concerns;

/**
 * ⭐ বিজ্ঞপ্তিতে কাগজের পক্ষ — "নাম · পয়েন্ট" (মালিক, ৮ অক্টোবর ২০২৬: *"notification e point name ase na"*)।
 *
 * ⓘ অনুমোদনের বিজ্ঞপ্তি কোরের ([[ApprovalEngine::noticeLabel()]]), আর কোর কোনো পক্ষের নাম জানে না — তাই বিক্রির কাগজ
 * নিজে বলে কে ([[Customer::nameWithPoint()]])। গ্রাহক না থাকলে null — বিজ্ঞপ্তি তখন ধরন আর নম্বরেই থাকে।
 */
trait NamesItsCustomerInNotices
{
    public function noticeParty(): ?string
    {
        $customer = $this->customer()->with('location.parent')->first();

        return $customer?->nameWithPoint();
    }
}

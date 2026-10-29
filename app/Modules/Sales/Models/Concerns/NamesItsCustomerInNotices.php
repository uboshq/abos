<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models\Concerns;

/**
 * ⭐ বিজ্ঞপ্তিতে কাগজের পক্ষ — "নাম · পয়েন্ট · শাখা" (মালিক, ৮ অক্টোবর ২০২৬: *"notification e point name ase na"*; শাখা ১৯ অক্টোবর)।
 *
 * ⓘ অনুমোদনের বিজ্ঞপ্তি কোরের ([[ApprovalEngine::noticeLabel()]]), আর কোর কোনো পক্ষের নাম জানে না — তাই বিক্রির কাগজ
 * নিজে বলে কে ([[Customer::nameWithPoint()]])। গ্রাহক না থাকলে null — বিজ্ঞপ্তি তখন ধরন আর নম্বরেই থাকে।
 */
trait NamesItsCustomerInNotices
{
    public function noticeParty(): ?string
    {
        // ⭐ শাখাও — "নাম · পয়েন্ট · শাখা" (মালিক, ১৯ অক্টোবর ২০২৬, স্থায়ী নিয়ম); একই প্রশ্নে, আলাদা ডাক নয়
        $customer = $this->customer()->with(['location.parent', 'branch'])->first();

        return $customer?->nameWithPoint();
    }
}

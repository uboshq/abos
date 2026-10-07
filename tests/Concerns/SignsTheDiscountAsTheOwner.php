<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Models\Approval;
use App\Models\User;

/**
 * মালিক অপেক্ষায় থাকা ছাড়ে সই দেন — যেসব দাবি ছাড়ের **হিসাব** মাপে, সই নয়।
 *
 * ⓘ মালিকের নিয়ম (১ অক্টোবর ২০২৬): যেকোনো হাতে-দেওয়া ছাড়, এক টাকাও, মালিকের সই চায়
 * ([[OwnerSignsDiscounts]])। ⛔ ছক বন্ধ করে পার করা যায় না — ছক না থাকলে ছাড় আটকায় (fail-closed)।
 * তাই হিসাবের দাবিগুলো আসল পথেই চলে: বিক্রি খসড়া → মালিকের সই → কাউন্টারের বিক্রি নিজে শেষ
 * ([[HeldCounterSaleFinisher]]); অফিসের বিল আবার "নিশ্চিত" চায়।
 * ⓘ সই নিজে মাপে [[TheOwnerSignsEveryDiscountTest]]।
 */
trait SignsTheDiscountAsTheOwner
{
    /** @return int কয়টা সই পড়ল */
    protected function ownerSignsTheDiscounts(): int
    {
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $signed = 0;

        foreach (Approval::query()->where('action', 'discount')->where('status', Approval::PENDING)->orderBy('id')->get() as $approval) {
            app(ApprovalEngine::class)->approve($approval, $owner);
            $signed++;
        }

        return $signed;
    }
}

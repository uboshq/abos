<?php

declare(strict_types=1);

namespace App\Core\Support;

use App\Core\Panels\FactRegistry;
use App\Core\Services\Ownership;
use App\Models\User;

/**
 * ⭐ নামের সাথে পদবি, রোল নয় — মালিক, ১৩ সেপ্টেম্বর ২০২৬: *"namer sate podobi rakbe roll noy"* (একজনের একাধিক রোল থাকতে পারে, পদবি একটা)।
 *
 * ⓘ পদবি থাকে HR-এর কর্মী-সারিতে, আর কোর HR-কে চেনে না — তাই [[FactRegistry]]-কে জিজ্ঞেস করা হয় "এই ব্যবহারকারী সম্পর্কে কার কী
 *   বলার আছে"। গ্রুপের মালিকের পদবি সব কোম্পানিতে একটাই, গোটা ব্যবস্থার সেটিং থেকে ([[Ownership::groupOwnerTitle()]], ২ অক্টোবর ২০২৬)।
 * ⓘ ফুটার থেকে তুলে আনা (৬ নভেম্বর ২০২৬): নাম-পদবি এখন টপবারে ছবির বাঁয়ে — মালিক: *"Al-Amin Shuvo গ্রুপ চেয়ারম্যান ও সিইও
 *   profile photor bame dibe upore nam niche designation"*।
 * ⛔ খালি থাকলে খালিই — ফাঁক ভরাতে রোল বসানো হয় না, তাতে ঠিক যে জিনিসটা বাদ দিতে বলা হলো সেটাই ফিরে আসত।
 */
final class UserDesignation
{
    public static function of(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $designation = app(FactRegistry::class)->forRecord('user', $user->id)[0]->value ?? null;

        if (app(Ownership::class)->isGroupOwner($user)) {
            $designation = app(Ownership::class)->groupOwnerTitle() ?? $designation;
        }

        return $designation === null || $designation === '' ? null : (string) $designation;
    }
}

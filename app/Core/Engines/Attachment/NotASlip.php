<?php

declare(strict_types=1);

namespace App\Core\Engines\Attachment;

/**
 * ব্যাংক বা বিকাশের স্লিপ হিসেবে নেওয়া গেল না — কেন, তার চাবিসহ (২৮ সেপ্টেম্বর ২০২৬)।
 *
 * ⓘ ইঞ্জিনের বাকি ভুলবার্তা ইংরেজি, কারণ সেগুলো মূলত ডেভেলপারের জন্য। ⚠️ স্লিপ
 * তোলেন কাউন্টারের মানুষ, আর মালিক সবকিছু বাংলায় পড়েন — তাই এখানে কেবল কারণের
 * চাবি (`too_big`, `wrong_kind`), বার্তা বানায় কন্ট্রোলার অনুবাদ থেকে।
 */
final class NotASlip extends AttachmentException
{
    public const TOO_BIG = 'too_big';

    public const WRONG_KIND = 'wrong_kind';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Not a slip: '.$reason);
    }
}

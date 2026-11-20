<?php

declare(strict_types=1);

namespace App\Core\Engines\Attachment;

/**
 * ব্যাংক বা বিকাশের স্লিপ হিসেবে নেওয়া গেল না — কেন, তার চাবিসহ (২৮ সেপ্টেম্বর ২০২৬)।
 *
 * ⓘ ইঞ্জিনের বাকি ভুলবার্তা ইংরেজি, কারণ সেগুলো মূলত ডেভেলপারের জন্য। ⚠️ স্লিপ
 * তোলেন কাউন্টারের মানুষ, আর মালিক সবকিছু বাংলায় পড়েন — তাই এখানে কেবল কারণের
 * চাবি (`too_big`, `wrong_kind`), বার্তা বানায় কন্ট্রোলার অনুবাদ থেকে।
 *
 * ⭐ ২০ নভেম্বর ২০২৬ — সীমা এখন ধরন ধরে (ছবি / PDF, কন্ট্রোল প্যানেলে KB; [[AttachmentEngine::slipMaxKb()]])। তাই ব্যতিক্রম
 *   নিজেই বলে কোন ধরন, সীমা কত আর ফাইল কত — [[text()]] পুরো বাক্যটা বানায়, প্রতিটা দরজা (ওয়েব, ফোন, পোর্টাল, HR) একই বাক্য দেয়।
 */
final class NotASlip extends AttachmentException
{
    public const TOO_BIG = 'too_big';

    public const WRONG_KIND = 'wrong_kind';

    public function __construct(
        public readonly string $reason,
        public readonly ?string $kind = null,
        public readonly ?int $maxKb = null,
        public readonly ?int $sizeKb = null,
    ) {
        parent::__construct('Not a slip: '.$reason);
    }

    /** সীমাটা মানুষের ভাষায় — ১০২৪ KB বা বেশি হলে MB (পর্দার অঙ্ক ইংরেজি) */
    public static function human(int $kb): string
    {
        return $kb >= 1024 ? rtrim(rtrim(number_format($kb / 1024, 1, '.', ''), '0'), '.').' MB' : $kb.' KB';
    }

    /**
     * ⭐ চলতি ভাষায় পুরো বার্তা — বাংলা বা ইংরেজি (`core.attachment.slip_*`)।
     *
     * ⓘ ধরন জানা থাকলে "PDF-টা 2.4 MB, সীমা 2 MB" — কোন ধরনের সীমা ছাড়াল, মানুষ ঠিক তাই জানেন; না জানলে পুরনো সাধারণ বাক্য।
     */
    public function text(): string
    {
        $max = self::human($this->maxKb ?? intdiv(AttachmentEngine::SLIP_MAX_BYTES, 1024));

        if ($this->reason === self::TOO_BIG && $this->kind !== null && $this->sizeKb !== null) {
            return __('core.attachment.slip_too_big_'.$this->kind, ['size' => self::human($this->sizeKb), 'max' => $max]);
        }

        return __('core.attachment.slip_'.$this->reason, ['max' => $max]);
    }
}

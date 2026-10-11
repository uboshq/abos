<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Core\Services\SettingsService;
use Illuminate\Http\UploadedFile;

/**
 * কোন ফাইল তাকে ওঠে — অনুমতি-তালিকা আর মাপের সীমা (§৬; ৮ অক্টোবর ২০২৬)।
 *
 * ── ⓘ সংযুক্তির দরজা থেকে কেন আলাদা ─────────────────────────────────
 * সাধারণ সংযুক্তি নিষেধ-তালিকা মানে (যা বিপজ্জনক নয় তা-ই চলে —
 * [[AttachmentEngine::FORBIDDEN]])। ⭐ DOC-এর কাগজ প্রিভিউতে **খোলা** হয় আর অনেকে
 * পড়েন, তাই এখানে উল্টো: কেবল যা জানা — PDF, ছবি, অফিসের ফাইল, সাদা লেখা।
 * ⚠️ ইঞ্জিনের নিষেধ-তালিকাও তবুও চলে ([[AttachmentEngine::store()]]-এর `$only`) —
 * দুই তালা, একটার উপর আরেকটা।
 *
 * ── ⛔ ধরন বাইট থেকে, নাম থেকে নয় ────────────────────────────────────
 * `চুক্তি.pdf` নামের ভিতরে HTML থাকলে নামটা নিরীহ, ফাইলটা নয়। ⓘ ধরন ঠিক হয় ফাইলের
 * বাইট পড়ে (finfo), আর নামের লেজকে সেই ধরনের হতেই হয় — ঠিক স্লিপের দরজার মতো।
 */
final class DocumentFiles
{
    /** ⓘ ১০ MB — সংযুক্তির ইঞ্জিনের সীমার সমান; শেয়ার্ড সার্ভারে এর বেশি ঝুঁকি */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** ফর্মের `max:` নিয়মের জন্য, কিলোবাইটে */
    public const MAX_KB = 10 * 1024;

    /** একবারে কয়টা ফাইল */
    public const MAX_FILES = 20;

    /**
     * বাইট থেকে পড়া ধরন => যে নাম-লেজগুলো চলে।
     *
     * ⚠️ অফিসের নতুন ফাইল (docx, xlsx, pptx) ভিতরে আসলে zip — কোনো যন্ত্রে finfo
     * সেটাই বলে, কোনোটায় আসল ধরন। দুইটাই রাখা, কিন্তু কেবল ঐ লেজগুলোর জন্য:
     * `.zip` নিজে তালিকায় নেই। পুরনো doc/xls/ppt-ও তাই (CDF ধারক)।
     *
     * @var array<string, list<string>>
     */
    public const ALLOWED = [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/gif' => ['gif'],

        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        'application/vnd.oasis.opendocument.text' => ['odt'],
        'application/vnd.oasis.opendocument.spreadsheet' => ['ods'],
        'application/zip' => ['docx', 'xlsx', 'pptx', 'odt', 'ods'],

        'application/msword' => ['doc'],
        'application/vnd.ms-excel' => ['xls'],
        'application/vnd.ms-powerpoint' => ['ppt'],
        'application/vnd.ms-office' => ['doc', 'xls', 'ppt'],
        'application/cdfv2' => ['doc', 'xls', 'ppt'],
        'application/x-ole-storage' => ['doc', 'xls', 'ppt'],

        'text/plain' => ['txt', 'csv'],
        'text/csv' => ['csv'],
    ];

    /**
     * ⭐ নিয়ন্ত্রণ প্যানেলের সুইচ (§২০ Storage Settings; দ্বিতীয় ধাপ) — কোন পরিবারের ফাইল চলে।
     * ⓘ PDF সবসময় চলে; ছবি, অফিসের ফাইল আর লেখার ফাইল কোম্পানি বন্ধ করতে পারে।
     *
     * @var array<string, list<string>> সুইচের চাবি => বাইটের ধরন
     */
    private const FAMILIES = [
        'documents.allow_images' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        'documents.allow_office' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.text', 'application/vnd.oasis.opendocument.spreadsheet',
            'application/zip', 'application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint',
            'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage',
        ],
        'documents.allow_text' => ['text/plain', 'text/csv'],
    ];

    /**
     * আজ এই কোম্পানিতে যা চলে — [[ALLOWED]] থেকে, বন্ধ পরিবার বাদে।
     *
     * @return array<string, list<string>>
     */
    public static function allowed(): array
    {
        $settings = app(SettingsService::class);
        $allowed = self::ALLOWED;

        foreach (self::FAMILIES as $key => $mimes) {
            if (! (bool) $settings->get($key, true)) {
                $allowed = array_diff_key($allowed, array_flip($mimes));
            }
        }

        return $allowed;
    }

    /**
     * একটা ফাইলের সীমা, বাইটে — সেটিং থেকে (১ থেকে ১০ MB), ⛔ কখনো ১০ MB-র বেশি নয়।
     */
    public static function maxBytes(): int
    {
        return self::maxMb() * 1024 * 1024;
    }

    public static function maxMb(): int
    {
        $mb = (int) app(SettingsService::class)->get('documents.max_upload_mb', 10);

        return max(1, min(10, $mb));
    }

    /**
     * প্রশাসনের পাতার জন্য — আজকের সীমা, কথায়।
     *
     * @return array{max: string, kinds: list<string>}
     */
    public static function limits(): array
    {
        return [
            'max' => self::maxMb().' MB',
            'kinds' => array_values(array_unique(array_merge(...array_values(self::allowed())))),
        ];
    }

    /** ফর্মের `extensions:` নিয়ম — নামের লেজ, প্রথম ছাঁকনি */
    public static function extensions(): string
    {
        return implode(',', array_values(array_unique(array_merge(...array_values(self::allowed())))));
    }

    /**
     * ফাইলটা চলে কি না — কারণের চাবিসহ (`too_big`, `wrong_kind`), নয়তো null।
     *
     * ⓘ ফর্ম জমার আগেই সব ফাইল দেখা হয় ([[DocumentUploadRequest]]), যাতে দশটার
     * নবমটা ফিরলে আগের আটটা ডিস্কে অনাথ হয়ে না থাকে।
     */
    public static function refusal(UploadedFile $file): ?string
    {
        if (! $file->isValid()) {
            return 'wrong_kind';
        }

        if ($file->getSize() > self::maxBytes()) {
            return 'too_big';
        }

        $path = $file->getRealPath();
        $sniffed = $path === false ? '' : strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($path));
        $extension = strtolower($file->getClientOriginalExtension());

        $allowed = self::allowed();

        return isset($allowed[$sniffed]) && in_array($extension, $allowed[$sniffed], true)
            ? null
            : 'wrong_kind';
    }
}

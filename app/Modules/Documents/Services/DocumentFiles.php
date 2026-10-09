<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

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

    /** ফর্মের `extensions:` নিয়ম — নামের লেজ, প্রথম ছাঁকনি */
    public static function extensions(): string
    {
        return implode(',', array_values(array_unique(array_merge(...array_values(self::ALLOWED)))));
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

        if ($file->getSize() > self::MAX_BYTES) {
            return 'too_big';
        }

        $path = $file->getRealPath();
        $sniffed = $path === false ? '' : strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($path));
        $extension = strtolower($file->getClientOriginalExtension());

        return isset(self::ALLOWED[$sniffed]) && in_array($extension, self::ALLOWED[$sniffed], true)
            ? null
            : 'wrong_kind';
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Engines\Attachment;

use App\Core\Engines\Image\ImageEngine;
use App\Core\Support\CompanyContext;
use App\Models\Attachment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * সংযুক্তি — প্ল্যান সেকশন ২১, অষ্টম engine।
 *
 * DMS-এর polymorphic নকশা, একটা গুরুত্বপূর্ণ পরিবর্তনসহ: ফাইল ডিস্কে বসে,
 * ডাটাবেজে নয়। base64 ফাইলকে ~৩৩% বড় করে, ডাটাবেজ ফুলে যায়, আর প্রতিটা
 * ব্যাকআপে সব ফাইল ঢোকে — শেয়ার্ড cPanel-এ ব্যাকআপ তখনই টাইমআউট করবে।
 */
final class AttachmentEngine
{
    /**
     * যেসব এক্সটেনশন কখনো নয়।
     *
     * অনুমোদিত তালিকা নয়, নিষিদ্ধ তালিকা — কারণ ব্যবসায়িক কাগজ কী কী হতে
     * পারে তার শেষ নেই, কিন্তু কোনটা বিপজ্জনক সেটার তালিকা ছোট ও জানা।
     */
    private const FORBIDDEN = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar',
        'exe', 'com', 'bat', 'cmd', 'sh', 'bash', 'ps1', 'psm1',
        'dll', 'so', 'jar', 'msi', 'scr', 'vbs', 'js', 'jse', 'wsf', 'hta',
    ];

    private const DEFAULT_MAX_BYTES = 10 * 1024 * 1024;

    /**
     * ⛔ প্রক্রিয়া **না হওয়া** একটা ছবি সর্বোচ্চ কত বড় হয়ে ডিস্কে বসতে পারে।
     *
     * ── কেন এই সীমাটা আলাদা, আর কেন এটা না থাকলে বিপদ ────────────────
     * ⓘ ঢোকার দরজার সীমা (`$maxBytes`) এখন ঢিলা — ১২ MB — কারণ যুক্তিটা
     * ছিল *"আমরাই তো ছোট করে নিচ্ছি"*।
     *
     * ⚠️ কিন্তু নিচের `keep()`-এ একটা fallback আছে: ইঞ্জিন ব্যর্থ হলে
     * কাঁচা ফাইলটাই জমা হয়। ⛔ দুইটা একসাথে রাখলে ফল দাঁড়াত — একটা
     * ১২ MB ছবি **সোজা ডিস্কে**, আর কেউ টের পেত না যতক্ষণ না শেয়ার্ড
     * cPanel-এর জায়গা ফুরাত।
     *
     * ⭐ তাই নিয়মটা দুই ভাগ: **ঢুকতে** পারে ১২ MB, কিন্তু **প্রক্রিয়া
     * ছাড়া থাকতে** পারে ২ MB। এর বেশি হলে চুপ করে রাখা হয় না —
     * ব্যবহারকারীকে বলা হয়।
     *
     * ⓘ এই ফাঁকটা abos-68 ধরেছে, ব্যবহারকারী নয় — আর সেটাই কাম্য।
     */
    private const RAW_IMAGE_CEILING = 2 * 1024 * 1024;

    public function __construct(
        private readonly string $disk = 'local',
        private readonly ImageEngine $images = new ImageEngine,
    ) {}

    public function store(
        UploadedFile $file,
        string $module,
        string $entity,
        int $entityId,
        ?int $replacesId = null,
        ?int $userId = null,
        ?int $maxBytes = null,
    ): Attachment {
        $this->assertAllowed($file, $maxBytes ?? self::DEFAULT_MAX_BYTES);

        $companyId = CompanyContext::id();
        $extension = strtolower($file->getClientOriginalExtension());

        // ব্যবহারকারীর দেওয়া নাম কখনো পথ তৈরিতে ব্যবহার হয় না। "../../.env"
        // নামের একটা ফাইল আপলোড করে অ্যাপের বাইরে লেখা যায় — এই একটা
        // সিদ্ধান্তেই সেটা অসম্ভব হয়ে যায়।
        $storedName = Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');

        $directory = sprintf(
            'attachments/%d/%s/%s/%s',
            $companyId,
            $module,
            now()->format('Y'),
            now()->format('m'),
        );

        $kept = $this->keep($file, $directory, $storedName, $extension);

        $version = 1;

        if ($replacesId !== null) {
            $previous = Attachment::query()->findOrFail($replacesId);
            $version = $previous->version + 1;
        }

        return Attachment::create([
            'company_id' => $companyId,
            'source_module' => $module,
            'source_entity' => $entity,
            'source_entity_id' => $entityId,
            /*
             * ⭐ নামটা ব্যবহারকারীর দেওয়াটাই থাকে — `IMG_20260914.jpg`।
             *
             * ⓘ ছবি প্রক্রিয়া করা হলেও এখানে নতুন নাম বসানো হয় না, কারণ
             * তালিকায় মানুষ **নিজের ফাইলটা চিনতে** চান। ⚠️ যে জিনিস
             * বদলেছে (ধরন, আকার, বাইট) সেগুলো নিচে সত্যি করে লেখা হয়।
             */
            'original_name' => $file->getClientOriginalName(),
            'stored_path' => $kept['path'],
            'mime_type' => $kept['mime'],
            'extension' => $kept['extension'],
            'size_bytes' => $kept['bytes'],
            'checksum' => hash_file('sha256', Storage::disk($this->disk)->path($kept['path'])),
            'version' => $version,
            'replaces_id' => $replacesId,
            'uploaded_by' => $userId ?? auth()->id(),
        ]);
    }

    /** @return Collection<int, Attachment> */
    public function listFor(string $module, string $entity, int $entityId)
    {
        return Attachment::query()
            ->for($module, $entity, $entityId)
            ->current()
            ->latest('id')
            ->get();
    }

    /**
     * ফাইলটা পড়া — কন্ট্রোলারের মধ্য দিয়ে, কখনো সরাসরি URL দিয়ে নয়।
     *
     * সরাসরি URL হলে লিংক জানলেই অন্য কোম্পানির কাগজ নামানো যেত, কোনো
     * লগইন ছাড়াই। গ্লোবাল স্কোপ এখানে কাজ করে বলেই অন্য কোম্পানির
     * সংযুক্তি এই পদ্ধতিতে কখনো পাওয়া যাবে না।
     */
    public function contents(Attachment $attachment): string
    {
        return Storage::disk($this->disk)->get($attachment->stored_path);
    }

    public function exists(Attachment $attachment): bool
    {
        return Storage::disk($this->disk)->exists($attachment->stored_path);
    }

    /**
     * সফট ডিলিট — নিয়ম ৫। ডিস্কের ফাইল রয়ে যায়।
     *
     * ফাইলটাও মুছে ফেললে ভুল করে মোছা একটা চুক্তিপত্র আর কখনো ফেরানো যেত না।
     * জায়গা খালি করার কাজ আলাদা, ইচ্ছাকৃত, আর সময় পেরোনোর পরে।
     */
    public function delete(Attachment $attachment): void
    {
        $attachment->delete();
    }

    /**
     * ⛔ বিলের ছবি যেমন আসত তেমনই জমা হত — ১৪ সেপ্টেম্বর ২০২৬।
     *
     * ── কী ভাঙা ছিল ──────────────────────────────────────────────────
     * এখানে আগে একটাই লাইন ছিল: `$file->storeAs(...)`। ⓘ অর্থাৎ ফোনে তোলা
     * একটা রসিদ **পাশ ফিরে, ছয় মেগাবাইট, ছায়াসহ** ডিস্কে বসত।
     *
     * ⚠️ মালিকের অভিযোগটা ঠিক এটাই ছিল: *"যেকোনো ফটো আপলোডের সময় নিজে
     * থেকে ক্রপ করে নেওয়ার ব্যবস্থা করার কথা ছিল সেটা হয় নাই"*।
     * ⓘ প্রোফাইল ছবিতে ব্যবস্থাটা ছিল ([[AvatarService]]), সংযুক্তিতে
     * কখনোই ছিল না — তাই "হয় নাই" কথাটা আক্ষরিক অর্থেই সত্যি ছিল।
     *
     * ── ⭐ যা ছবি নয়, তাতে হাত পড়ে না ─────────────────────────────────
     * PDF, Excel, Word — যেমন আসে তেমনই যায়। ⛔ একটা চুক্তিপত্রের PDF-কে
     * "উন্নত" করতে যাওয়া মানে সেটা নষ্ট করা।
     *
     * ── ⚠️ ব্যর্থ হলে ফাইলটা হারায় না ────────────────────────────────
     * GD একটা ভাঙা বা অদ্ভুত ছবিতে হোঁচট খেতে পারে। ⓘ তখন কাঁচা ফাইলটাই
     * জমা হয় — **একটা বড় ছবি থাকা, কাগজটা হারানোর চেয়ে ভালো**। ব্যবহারকারী
     * বিলটা তুলেছেন একবার; আমাদের যন্ত্রের অক্ষমতার দায় তাঁর নয়।
     *
     * @return array{path: string, extension: string, mime: string, bytes: int}
     */
    private function keep(UploadedFile $file, string $directory, string $storedName, string $extension): array
    {
        $source = $file->getRealPath();
        $isImage = $source !== false && @getimagesize($source) !== false;

        if ($isImage && $this->images->reads($source)) {
            try {
                $bytes = $this->images->paper($source);

                /*
                 * ⓘ যা বের হয় সেটা সবসময় JPEG, তাই নামের লেজও তাই — নাহলে
                 * `.png` নামের ভিতরে JPEG থাকত, আর একদিন কেউ নাম দেখে
                 * সিদ্ধান্ত নিয়ে ভুল করত।
                 */
                $name = preg_replace('/\.[^.]+$/', '', $storedName).'.jpg';
                $path = $directory.'/'.$name;

                Storage::disk($this->disk)->put($path, $bytes);

                return [
                    'path' => $path,
                    'extension' => 'jpg',
                    'mime' => 'image/jpeg',
                    'bytes' => strlen($bytes),
                ];
            } catch (\Throwable) {
                // নিচে পড়ে যায় — কাঁচা ফাইলই জমা হবে, সীমার মধ্যে হলে।
            }
        }

        /*
         * ⛔ এখানে পৌঁছানো মানে: এটা ছবি, কিন্তু প্রক্রিয়া হয়নি —
         * হয় GD হোঁচট খেয়েছে, নয় বিন্দুর সংখ্যা মেমরির চেয়ে বেশি
         * ([[ImageEngine::fitsInMemory]])।
         *
         * ⚠️ ছোট হলে চুপচাপ রেখে দেওয়া যায় — ব্যবহারকারীর কাগজ হারানোর
         * চেয়ে ভালো। ⛔ কিন্তু বড় হলে **নীরবে রাখা যায় না**: ডিস্ক ভরার
         * মতো ক্ষতি কেউ টের পায় না যতক্ষণ না দেরি হয়ে যায়।
         */
        if ($isImage && $file->getSize() > self::RAW_IMAGE_CEILING) {
            throw new AttachmentException(sprintf(
                'That photo could not be processed, and at %s it is too large to keep as it is. The limit for an unprocessed photo is %s.',
                $this->human((int) $file->getSize()),
                $this->human(self::RAW_IMAGE_CEILING),
            ));
        }

        $path = $file->storeAs($directory, $storedName, ['disk' => $this->disk]);

        return [
            'path' => $path,
            'extension' => $extension,
            'mime' => $file->getClientMimeType(),
            'bytes' => (int) Storage::disk($this->disk)->size($path),
        ];
    }

    private function assertAllowed(UploadedFile $file, int $maxBytes): void
    {
        if (! $file->isValid()) {
            throw new AttachmentException('The upload did not complete.');
        }

        if ($file->getSize() > $maxBytes) {
            throw new AttachmentException(sprintf(
                'File is %s but the limit is %s.',
                $this->human($file->getSize()),
                $this->human($maxBytes),
            ));
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, self::FORBIDDEN, true)) {
            throw new AttachmentException("Files of type .{$extension} cannot be attached.");
        }

        // নাম বদলে দেওয়া ফাইল ধরার জন্য বিষয়বস্তু দেখতে হয়। invoice.php-কে
        // invoice.pdf নাম দিলে এক্সটেনশন নিরীহ দেখায়, কিন্তু ভেতরে যা আছে
        // তা বদলায় না।
        //
        // getClientMimeType() এখানে ব্যবহার করা হয় না ইচ্ছাকৃতভাবে: ওটা
        // ব্রাউজারের পাঠানো মান, অর্থাৎ আপলোডকারীর নিয়ন্ত্রণে — নিরাপত্তার
        // সিদ্ধান্ত ওর উপর নেওয়া মানে আক্রমণকারীকে জিজ্ঞেস করা সে বিপজ্জনক
        // কি না। getMimeType() ফাইলটা পড়ে বলে।
        $sniffed = strtolower((string) $file->getMimeType());

        if (str_contains($sniffed, 'php') || str_contains($sniffed, 'x-httpd')
            || str_contains($sniffed, 'executable') || str_contains($sniffed, 'x-dosexec')) {
            throw new AttachmentException('That file type cannot be attached.');
        }

        $guessed = strtolower((string) $file->guessExtension());

        if ($guessed !== '' && in_array($guessed, self::FORBIDDEN, true)) {
            throw new AttachmentException('That file type cannot be attached.');
        }
    }

    private function human(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1).' MB'
            : round($bytes / 1024).' KB';
    }
}

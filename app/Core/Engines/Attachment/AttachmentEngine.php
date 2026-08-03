<?php

declare(strict_types=1);

namespace App\Core\Engines\Attachment;

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

    public function __construct(private readonly string $disk = 'local') {}

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

        $path = $file->storeAs($directory, $storedName, ['disk' => $this->disk]);

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
            'original_name' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'extension' => $extension,
            'size_bytes' => $file->getSize(),
            'checksum' => hash_file('sha256', Storage::disk($this->disk)->path($path)),
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

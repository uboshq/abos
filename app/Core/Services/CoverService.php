<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Engines\Image\ImageEngine;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * ⭐ প্রোফাইলের মলাট (cover) ছবি — বসানো, সরানো, পড়া (প্রোফাইল পাতা, ২০ নভেম্বর ২০২৬)।
 *
 * ⓘ [[AvatarService]]-এর হুবহু ছাঁচ: ছবির কাজ [[ImageEngine::cover()]]-এর (সোজা করা → ৩:১ কাটা → ১৫০০×৫০০ → ৩০০KB JPEG),
 * আর এই সেবা জানে কেবল কার মলাট, কোথায় বসবে, পুরনোটা কখন মুছবে।
 *
 * ── ⛔ নিরাপত্তা ───────────────────────────────────────────────────────
 * - ফাইল ব্যক্তিগত ডিস্কে (`local`, `covers/`), দেখা যায় কেবল [[App\Http\Controllers\UserCoverController]]-এর দরজা দিয়ে —
 *   সহকর্মী না হলে ৪০৪ (মুখের একই পাহারা, [[AvatarService::visibleTo()]])।
 * - ফলাফল সবসময় নতুন করে আঁকা JPEG — উৎস ফাইল কখনো ডিস্কে যায় না, EXIF (GPS সহ) সাথে যায় না।
 * - কলামের পথ কেবল `covers/…` হলেই পড়া বা মোছা হয় — হাতে বসানো `../.env` ধরনের কিছু এই দরজা ছোঁয় না।
 */
final class CoverService
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    /** @var list<string> */
    public const ACCEPTED = ImageEngine::READS;

    /** ⓘ নিরীক্ষার ঘটনার নাম */
    public const CHANGED = 'cover_changed';

    public const REMOVED = 'cover_removed';

    private const DIRECTORY = 'covers';

    private const DISK = 'local';

    private readonly AuditEngine $audit;

    public function __construct(
        private readonly ImageEngine $images = new ImageEngine,
        ?AuditEngine $audit = null,
    ) {
        $this->audit = $audit ?? app(AuditEngine::class);
    }

    /**
     * আপলোড করা মলাট বসানো — আগেরটা থাকলে মুছে যায়।
     *
     * @return string সংরক্ষিত পথ
     */
    public function store(User $user, UploadedFile $file): string
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new RuntimeException('cover.too_large');
        }

        $source = $file->getRealPath();

        if ($source === false || $source === '') {
            throw new RuntimeException('cover.not_an_image');
        }

        $bytes = $this->images->cover($source);

        // ⓘ নামে এলোমেলো অংশ — বদলালে ঠিকানা বদলায়, ক্যাশ পুরনোটা দেখায় না
        $path = self::DIRECTORY.'/'.$user->getKey().'-'.bin2hex(random_bytes(8)).'.jpg';

        $previous = $user->getRawOriginal('cover_path');

        Storage::disk(self::DISK)->put($path, $bytes);

        $user->forceFill(['cover_path' => $path])->save();

        $this->audit->recordAction($user, self::CHANGED);

        // ⓘ পুরনোটা মোছা সবার শেষে — লেখা বা সেভ ব্যর্থ হলে আগেরটা অন্তত থাকে
        if ($previous !== $path) {
            $this->deleteFile($previous);
        }

        return $path;
    }

    /** মলাট সরানো — ফাইল ও রেকর্ড দুই-ই; না থাকলে কিছুই নয় (খাতায় না-ঘটা ঘটনাও নয়)। */
    public function remove(User $user): void
    {
        $previous = $user->getRawOriginal('cover_path');

        if ($previous === null || $previous === '') {
            return;
        }

        $user->forceFill(['cover_path' => null])->save();

        $this->audit->recordAction($user, self::REMOVED);

        $this->deleteFile($previous);
    }

    /** মলাটের বাইট, না থাকলে null। */
    public function bytesOf(User $user): ?string
    {
        $path = (string) $user->getRawOriginal('cover_path');

        if (! $this->isOurs($path) || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        $bytes = Storage::disk(self::DISK)->get($path);

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    /** মলাটটা কি সত্যিই আছে? — ভাঙা ছবির বদলে রঙের পটি দেখাতে ([[User::coverUrl()]])। */
    public function exists(?string $path): bool
    {
        return $this->isOurs((string) $path) && Storage::disk(self::DISK)->exists((string) $path);
    }

    /** ⛔ কেবল আমাদের নিজের ফোল্ডারের পথ — বাকি সব পড়া নয়, মোছা নয়। */
    private function isOurs(string $path): bool
    {
        return preg_match('#^'.self::DIRECTORY.'/[A-Za-z0-9_-]+\.(jpg|jpeg|png|webp)$#', $path) === 1;
    }

    private function deleteFile(?string $path): void
    {
        if ($this->isOurs((string) $path)) {
            Storage::disk(self::DISK)->delete((string) $path);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Engines\Image\ImageEngine;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * ব্যবহারকারীর ছবি — কাটা, ছোট করা, সংরক্ষণ।
 *
 * ── ⭐ ১৪ সেপ্টেম্বর ২০২৬: GD-র কাজটা এখান থেকে সরে গেছে ──────────────
 * আগে এই ফাইলে ছবি খোলা, EXIF অনুযায়ী ঘোরানো আর বর্গ করে কাটার কোড
 * নিজের ছিল, আর সেটা **কাজ করত**। ⓘ সরানো হয়েছে একটাই কারণে: মালিক
 * বললেন সংযুক্তিতেও একই ব্যবস্থা লাগবে — *"যেকোনো ফটো আপলোডের সময়"*।
 *
 * ⛔ কোডটা এখানে রেখে দিলে ঐ ব্যবস্থা [[App\Core\Engines\Attachment\AttachmentEngine]]-এ
 * **দ্বিতীয়বার** লিখতে হত, আর ছবি জমা করে এমন জায়গা এই রিপোতে গুনে
 * পাওয়া গেছে **চারটা** (সংযুক্তি, প্রোফাইল, পণ্যের ছবি, কোম্পানির লোগো)।
 * ⚠️ চারবার লেখা মানে একদিন চারটা আলাদা আচরণ।
 *
 * ⭐ তাই ছবির কাজ এখন [[App\Core\Engines\Image\ImageEngine]]-এর, আর এই
 * সেবাটা যা জানে কেবল তাই করে: **কোন ব্যবহারকারীর ছবি, কোথায় বসবে,
 * পুরনোটা কখন মুছবে**।
 *
 * ── নিরাপত্তা (নিয়ম ৪ — প্রতিটা ইনপুটে ভ্যালিডেশন) ───────────────────
 * - ফাইলের এক্সটেনশন বা ব্রাউজারের বলা MIME বিশ্বাস করা হয় না; ছবিটা
 *   আসলে খোলা যায় কি না সেটাই একমাত্র প্রমাণ। "photo.jpg" নাম দিয়ে PHP
 *   ফাইল পাঠানোর পুরনো আক্রমণটা ইঞ্জিনের `open()`-এই আটকায়।
 * - SVG নেওয়া হয় না। SVG-তে `<script>` থাকতে পারে, আর সেটা একই ডোমেইন
 *   থেকে পরিবেশন করা মানে ব্যবহারকারীর সেশন ওই স্ক্রিপ্টের হাতে।
 * - ফলাফল সবসময় নতুন করে আঁকা JPEG — উৎস ফাইলটা কখনো ডিস্কে যায় না,
 *   তাই ছবির ভেতরে লুকানো কিছু (EXIF-এ বসানো পে-লোড) সাথে যায় না।
 */
class AvatarService
{
    /**
     * প্রোফাইল ছবির বাহু।
     *
     * ⚠️ আগে ছিল ২৫৬। মালিক বলেছেন *"প্রোপাইল পিক ক্রপ করে রেজুলেশন
     * এডজাস্ট করা"* — তাই এখন ইঞ্জিনের `FACE_EDGE`, অর্থাৎ **৫১২**।
     * ⓘ পর্দায় সবচেয়ে বড় দেখানো হয় ৯৬px; ৫১২ রাখলে দ্বিগুণ-ঘনত্বের
     * পর্দাতেও ছবিটা কড়কড়ে থাকে, আর ওজন ~৩৫KB — সস্তা।
     */
    public const SIZE = ImageEngine::FACE_EDGE;

    public const MAX_BYTES = 5 * 1024 * 1024;

    /** @var list<string> */
    public const ACCEPTED = ImageEngine::READS;

    /**
     * ⛔ নতুন ছবি **ব্যক্তিগত** ডিস্কে — a6, ২০ অক্টোবর ২০২৬।
     *
     * ── কেন সরল ───────────────────────────────────────────────────────
     * মালিকের নিয়ম: *"একই কোম্পানির মানুষই কেবল ফাইলটা দেখতে পারবে"*।
     * ⚠️ আগে ছবি বসত `public` ডিস্কে, ঠিকানা `/storage/avatars/…` — লগইন
     * ছাড়াই, যেকোনো কোম্পানির যে কেউ, এমনকি বাইরের কেউ ঠিকানাটা পেলেই
     * মুখটা দেখতেন। ⓘ নামে এলোমেলো অংশ ছিল, তাই আন্দাজে পাওয়া কঠিন —
     * কিন্তু একবার কোথাও লেগে গেলে (স্ক্রিনশট, শেয়ার করা পাতা) চিরকাল খোলা।
     *
     * ⭐ এখন ফাইল `local` ডিস্কে (`storage/app/private`, সংযুক্তির পাশে),
     * আর দেখা যায় কেবল [[App\Http\Controllers\UserPhotoController]]-এর
     * দরজা দিয়ে — সেখানে সহকর্মী কি না দেখা হয় ([[self::visibleTo()]])।
     *
     * ⓘ ফোল্ডারের নামই বলে দেয় কোন ডিস্ক: `faces/` = ব্যক্তিগত, পুরনো
     * `avatars/` = public। ⛔ মাইগ্রেশন নেই, পুরনো ফাইল সরানো হয়নি — ওগুলো
     * একই দরজা দিয়ে পড়া হয়, আর মানুষ ছবি বদলালে বা সরালে পুরনোটা মুছে যায়।
     * ⚠️ পুরনো ফাইলের `/storage/avatars/…` ঠিকানা তবু খোলা থাকে, যতদিন না
     * ফাইলটা সরানো হয় — কোনো পাতা আর ঐ ঠিকানা ছাপে না, কিন্তু আগে কেউ
     * টুকে রাখলে খোলে। সরানোর এককালীন কাজটা আলাদা, মালিকের হ্যাঁ-র পরে।
     */
    private const DIRECTORY = 'faces';

    private const DISK = 'local';

    /** ⓘ ২০ অক্টোবরের আগের ছবি — কেবল পড়া আর মোছা, এখানে নতুন কিছু লেখা হয় না। */
    private const LEGACY_DIRECTORY = 'avatars';

    private const LEGACY_DISK = 'public';

    /** ⓘ নিরীক্ষার ঘটনার নাম — শব্দ `governance::action.*`-এ। */
    public const CHANGED = 'photo_changed';

    public const REMOVED = 'photo_removed';

    private readonly AuditEngine $audit;

    public function __construct(
        private readonly ImageEngine $images = new ImageEngine,
        ?AuditEngine $audit = null,
    ) {
        $this->audit = $audit ?? app(AuditEngine::class);
    }

    /**
     * আপলোড করা ছবি বসানো। আগেরটা থাকলে মুছে যায়।
     *
     * ⓘ `$crop` এলে ব্যবহারকারী পর্দায় নিজে যে চৌকোটা টেনে বেছেছেন ঠিক
     * সেটাই নেওয়া হয়; না এলে ইঞ্জিন আন্দাজ করে কাটে (মুখ উপরের দিকে
     * থাকে ধরে)। ⭐ অর্থাৎ ক্রপের পর্দা না থাকলেও আচরণ আগের মতোই।
     *
     * @param  array{x: int, y: int, size: int}|null  $crop
     * @return string সংরক্ষিত পথ
     */
    public function store(User $user, UploadedFile $file, ?array $crop = null): string
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new RuntimeException('avatar.too_large');
        }

        $source = $file->getRealPath();

        if ($source === false) {
            throw new RuntimeException('avatar.not_an_image');
        }

        $bytes = $this->images->face($source, $crop);

        // নামে এলোমেলো অংশ: একই ব্যবহারকারী ছবি বদলালে পুরনো নামটাই আবার
        // ব্যবহার করলে ব্রাউজার ও CDN আগেরটা ক্যাশ থেকে দেখাত, আর
        // ব্যবহারকারী ভাবত আপলোড হয়নি।
        $path = self::DIRECTORY.'/'.$user->getKey().'-'.bin2hex(random_bytes(8)).'.jpg';

        $previous = $user->getRawOriginal('avatar_path');

        Storage::disk(self::DISK)->put($path, $bytes);

        $user->forceFill(['avatar_path' => $path])->save();

        /*
         * ⭐ নিরীক্ষার খাতায় ঘটনাটা — ওয়েব আর ফোন দুই দরজারই (a6, ২০ অক্টোবর ২০২৬)।
         * ⓘ `avatar_path` ঘরটা [[User::auditIgnores()]]-এ, তাই সাধারণ "সম্পাদনা" সারি বসে না; এখানে কেবল নাম-দেওয়া
         * ঘটনা। ⛔ ফাইলের বাইট বা পথ খাতায় যায় না — কে, কখন, কোন যন্ত্র থেকে (IP আর user-agent ইঞ্জিন নিজে লেখে)।
         */
        $this->audit->recordAction($user, self::CHANGED);

        // পুরনোটা মোছা সবার শেষে, আগে নয়: লেখা বা সেভ ব্যর্থ হলে
        // ব্যবহারকারী অন্তত আগের ছবিটা ফেরত পায়।
        $this->deleteFile($previous);

        return $path;
    }

    /** ছবি সরানো — ফাইল ও রেকর্ড দুই-ই। */
    public function remove(User $user): void
    {
        $previous = $user->getRawOriginal('avatar_path');

        // ⓘ ছবি ছিলই না — মোছার কিছু নেই, খাতায় না-ঘটা ঘটনাও নয়
        if ($previous === null || $previous === '') {
            return;
        }

        $user->forceFill(['avatar_path' => null])->save();

        $this->audit->recordAction($user, self::REMOVED);

        $this->deleteFile($previous);
    }

    /**
     * ⭐ এই মানুষটা কি ঐ মানুষটার মুখ দেখতে পারেন? — a6, ২০ অক্টোবর ২০২৬।
     *
     * ⓘ নিজের ছবি সবসময়। অন্যেরটা কেবল যখন দুজনেই একই **চালু** কোম্পানির **চালু** সদস্য (`company_user.is_active`
     * দুই পাশে, `companies.is_active`)। ⛔ চলতি কোম্পানি দেখা হয় না, ইচ্ছা করে: দুই কোম্পানির মালিক এক কোম্পানিতে বসে
     * অনুমোদনের ইতিহাসে অন্যটার সহকর্মীর মুখও দেখেন — সহকর্মী তো সহকর্মীই।
     */
    public function visibleTo(User $viewer, User $owner): bool
    {
        if ((int) $viewer->getKey() === (int) $owner->getKey()) {
            return true;
        }

        return DB::table('company_user as mine')
            ->join('company_user as theirs', 'theirs.company_id', '=', 'mine.company_id')
            ->join('companies', 'companies.id', '=', 'mine.company_id')
            ->where('mine.user_id', $viewer->getKey())
            ->where('mine.is_active', true)
            ->where('theirs.user_id', $owner->getKey())
            ->where('theirs.is_active', true)
            ->where('companies.is_active', true)
            ->whereNull('companies.deleted_at')
            ->exists();
    }

    /**
     * ছবির বাইট, না থাকলে null — নতুনটা ব্যক্তিগত ডিস্ক থেকে, পুরনোটা public থেকে।
     *
     * ⛔ পথটা কেবল আমাদের নিজের দুই ফোল্ডারের হলে: কলামে হাতে বসানো `../.env` ধরনের কিছু থাকলে এই দরজা সেটা পড়ে না।
     */
    public function bytesOf(User $user): ?string
    {
        $path = (string) $user->getRawOriginal('avatar_path');
        $disk = $this->diskFor($path);

        if ($disk === null || ! Storage::disk($disk)->exists($path)) {
            return null;
        }

        $bytes = Storage::disk($disk)->get($path);

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    /** ছবিটা কি সত্যিই আছে? — ভাঙা ছবির বদলে আদ্যক্ষর দেখাতে ([[User::avatarUrl()]])। */
    public function exists(?string $path): bool
    {
        $disk = $this->diskFor((string) $path);

        return $disk !== null && Storage::disk($disk)->exists((string) $path);
    }

    /** ⓘ ফোল্ডার দেখে ডিস্ক — আর আমাদের দুই ফোল্ডারের বাইরের যেকোনো পথ null (পড়া নয়, মোছা নয়)। */
    private function diskFor(string $path): ?string
    {
        if (preg_match('#^'.self::DIRECTORY.'/[A-Za-z0-9_-]+\.(jpg|jpeg|png|webp)$#', $path) === 1) {
            return self::DISK;
        }

        if (preg_match('#^'.self::LEGACY_DIRECTORY.'/[A-Za-z0-9_-]+\.(jpg|jpeg|png|webp)$#', $path) === 1) {
            return self::LEGACY_DISK;
        }

        return null;
    }

    private function deleteFile(?string $path): void
    {
        $disk = $this->diskFor((string) $path);

        if ($disk !== null) {
            Storage::disk($disk)->delete((string) $path);
        }
    }
}

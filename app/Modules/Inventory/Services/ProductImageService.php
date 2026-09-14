<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Core\Engines\Attachment\AttachmentEngine;
use App\Models\Attachment;
use App\Modules\Inventory\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * পণ্যের ছবি — তোলা, বদলানো, সরানো।
 *
 * ── কেন নতুন কোনো ফাইল-ব্যবস্থা লেখা হয়নি ────────────────────────────
 * [[AttachmentEngine]] আগে থেকেই আছে আর যা যা লাগে সব দেয়: UUID নাম,
 * নিষিদ্ধ এক্সটেনশন, আকারের সীমা, sha256, সংস্করণ, নরম-মোছা, আর
 * `storage/app/private`। এই ফাইলটা কেবল **ছবির নিজস্ব নিয়মগুলো** যোগ
 * করে আর পণ্যের সাথে সম্পর্কটা রাখে।
 *
 * ── ছবির নিয়ম ইঞ্জিনের চেয়ে কড়া, আর সেটা ইচ্ছাকৃত ───────────────────
 * ইঞ্জিনের তালিকা **নিষিদ্ধের** (php, exe, sh…), কারণ ব্যবসায়িক কাগজ কী
 * কী হতে পারে তার শেষ নেই। ছবির বেলায় প্রশ্নটা উল্টো — **কী কী চলবে
 * তার তালিকা ছোট ও জানা**, তাই এখানে অনুমোদিত তালিকা।
 *
 * আর সীমা ২ MB, ইঞ্জিনের ১০ নয়: একটা পণ্যের ছবি তালিকার সারিতে ৪০
 * পিক্সেল হয়ে দেখা যায়। ১০ MB-র ছবি রাখলে **প্রতিটা তালিকা লোড হতে
 * সেকেন্ড লাগত**, আর ডিপোর নেট ধীর।
 */
final class ProductImageService
{
    /** ইঞ্জিনের কাছে পণ্য কোন মডিউলের কোন জিনিস। */
    public const MODULE = 'inventory';

    public const ENTITY = 'product';

    /**
     * যা চলবে — অনুমোদিত তালিকা, নিষিদ্ধের নয়।
     *
     * ⚠️ `svg` ইচ্ছাকৃতভাবে বাইরে। SVG একটা **নথি**, ছবি নয়: তার ভিতরে
     * `<script>` থাকতে পারে, আর ব্রাউজার সেটা চালায়। একটা পণ্যের ছবি
     * হিসেবে আপলোড করা SVG পরে যে কারও পর্দায় খুলবে — অর্থাৎ সেটা
     * অন্যের সেশনে কোড চালানোর একটা দরজা।
     */
    public const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * ⭐ ১২ MB — ১৪ সেপ্টেম্বর ২০২৬-এ ২ থেকে বাড়ানো।
     *
     * ── কেন উল্টো সিদ্ধান্ত নেওয়া হলো ────────────────────────────────
     * ⚠️ এখানে আগে লেখা ছিল: *"ফোনে তোলা একটা ছবি সচরাচর ৩–৫ MB, তাই
     * সীমাটা মানুষ ছোঁবেন। সেটাই উদ্দেশ্য।"* অর্থাৎ যুক্তিটা ছিল —
     * **ব্যবহারকারী নিজে ছবিটা ছোট করে আনবেন**।
     *
     * ⛔ মালিক ঠিক ঐ যুক্তিটাই বাতিল করেছেন: *"নিজেই সাইজ করে নিতে হবে,
     * কত কেবি/এমবি হবে নিজের বানিয়ে নিতে হবে"*। দোকানের একজন কর্মীকে
     * ছবি ছোট করার অ্যাপ খুঁজতে পাঠানো আমাদের কাজের ব্যর্থতা, তাঁর নয়।
     *
     * ⭐ এখন [[App\Core\Engines\Image\ImageEngine]] সংযুক্তির পথেই ছবিটা
     * নামিয়ে আনে, আর **ডিস্কে যা বসে তার ছাদ ৮০০ KB** — তালিকার পাতা
     * দ্রুত থাকার আসল কারণ ঐটাই, ঢোকার দরজার সীমা নয়।
     *
     * ⓘ তাহলে সীমাটা রাখা কেন: একটা ২ GB ফাইল সার্ভারে তোলা ঠেকাতে।
     * ১২ MB-তে ফোনের সবচেয়ে বড় ছবিটাও ঢোকে, অথচ দরজাটা খোলা থাকে না।
     */
    public const MAX_BYTES = 12 * 1024 * 1024;

    public function __construct(private readonly AttachmentEngine $attachments) {}

    /**
     * নতুন ছবি — আর ওটাই এখন থেকে পণ্যের মুখ।
     *
     * ── আগের ছবিটার কী হয় ───────────────────────────────────────────
     * **মুছে যায় না।** ইঞ্জিনের `replaces_id` দিয়ে নতুনটা পুরনোটার
     * সংস্করণ হিসেবে বসে, আর পুরনো সারিটা থেকে যায়।
     *
     * ⚠️ কেন মোছা হয় না, যদিও মুছলে জায়গা বাঁচত: ভুল ছবি তুলে ফেললে
     * ফেরার পথ থাকা দরকার, আর **"ছবিটা কে কখন বদলাল" প্রশ্নের উত্তরও**।
     * এই রিপোর নিয়মই তাই — hard delete নেই, কোথাও।
     */
    public function replace(Product $product, UploadedFile $file, ?int $userId = null): Attachment
    {
        return DB::transaction(function () use ($product, $file, $userId): Attachment {
            $attachment = $this->attachments->store(
                file: $file,
                module: self::MODULE,
                entity: self::ENTITY,
                entityId: (int) $product->getKey(),
                replacesId: $product->primary_image_id,
                userId: $userId,
                maxBytes: self::MAX_BYTES,
            );

            /*
             * ⚠️ `forceFill()->save()`, `update()` নয় — আর কারণটা
             * A4-এর কাজের সাথে জড়ানো এড়াতে।
             *
             * `primary_image_id` পণ্যের `$fillable`-এ আছে, কিন্তু
             * `update()` ডাকলে সে `ProductService`-এর পথে যেত না — এটা
             * ছবির সিদ্ধান্ত, পণ্যের ঘরগুলোর নয়। এক লাইন, এক কলাম।
             */
            $product->forceFill(['primary_image_id' => $attachment->id])->save();

            return $attachment;
        });
    }

    /**
     * মুখটা সরানো — ছবিটা নয়।
     *
     * ⓘ কাগজটা ইঞ্জিনেই থেকে যায়। এখানে কেবল বলা হয় "এটা আর মুখ নয়"।
     * ছবিটা সত্যিই সরাতে হলে কাগজপত্রের সাধারণ পথ আছে
     * ([[AttachmentController::destroy()]]), আর সেখানে অডিটে কে কখন
     * সরাল তা লেখা থাকে।
     */
    public function clearPrimary(Product $product): void
    {
        $product->forceFill(['primary_image_id' => null])->save();
    }

    /**
     * ফাইলটা সত্যিই ছবি কি না — আর সেটা **বিষয়বস্তু দেখে**।
     *
     * ⚠️ ব্রাউজারের পাঠানো mime বিশ্বাস করা যায় না: ওটা ক্লায়েন্ট লেখে,
     * তাই যে কেউ `evil.php`-কে `image/png` বলে পাঠাতে পারে।
     * `getMimeType()` ফাইলের ভিতরটা পড়ে (finfo), আর সেটাই একমাত্র
     * উত্তর যা আক্রমণকারী লিখতে পারে না।
     *
     * ⓘ ইঞ্জিনের নিষিদ্ধ তালিকা এক্সটেনশন দেখে, তাই দুইটা একসাথে থাকলে
     * নাম ও বিষয়বস্তু দুইদিকই ঢাকা।
     */
    public function looksLikeAnImage(UploadedFile $file): bool
    {
        return in_array($file->getMimeType(), self::ALLOWED, true);
    }
}

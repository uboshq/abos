<?php

declare(strict_types=1);

namespace App\Core\Engines\Approval;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * অনুমোদন একটা কাগজ আটকেছে — আর কোন কাগজটা, সেটা সাথে থাকে।
 *
 * ── ⛔ কেন দরকার হলো, ১৯ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * মালিকের অভিযোগ: *"অনুমোদনে গেলে ক্রয়ের পর বিলটা হারিয়ে যায়।"*
 *
 * ⓘ ১৮ তারিখের সংশোধনে ([[DirectPurchaseService::complete()]]) বিলটা
 * আর মোছে না — খসড়া হয়ে থেকে যায়। ⚠️ কিন্তু পর্দা তবু ফিরে যেত
 * **সরাসরি ক্রয়ের ভরা ফর্মে**, লাল বার্তা নিয়ে, খসড়াটার কোনো লিংক
 * ছাড়া। মানুষের কাছে ওটা "কিছুই হয়নি"-র মতো দেখাত, আর আবার সেভ চাপলে
 * দ্বিতীয় একটা খসড়ার ঝুঁকি ছিল।
 *
 * ⛔ কন্ট্রোলার খসড়াটার কাছে যেতে পারত না, কারণ সাধারণ
 * `ValidationException` কেবল বার্তা বয়, **কাগজ বয় না**।
 *
 * ── ⭐ কেন উপ-ধরন, নতুন ধরন নয় ────────────────────────────────────────
 * [[DocumentApproval::assertClear()]] ছয়টা সেবা ডাকে (বেতন, স্থানান্তর,
 * পরিশোধ, বিল, ক্রয়াদেশ, ফেরত), আর সবগুলোর পর্দা `ValidationException`
 * দেখাতে জানে। ⓘ এটা তারই উপ-ধরন, তাই ঐ ছয় জায়গায় **কিছুই বদলায় না**;
 * কেবল যে কন্ট্রোলার আলাদা করে ধরতে চায়, সে-ই কাগজটা পায়।
 */
final class HeldForApproval extends ValidationException
{
    /** যে কাগজটা সইয়ের অপেক্ষায় আটকে আছে। */
    public ?Model $document = null;

    public static function on(Model $document, string $field, string $message): self
    {
        $held = self::withMessages([$field => $message]);
        $held->document = $document;

        return $held;
    }
}

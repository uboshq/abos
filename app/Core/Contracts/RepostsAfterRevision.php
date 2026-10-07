<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * যে কাগজের খাতা কোর নিজে উল্টায় আর আবার বসায় — [[App\Core\Services\RevisionKeeper::edit()]] (৩ অক্টোবর ২০২৬)।
 *
 * ⓘ ছবির অংশ [[RevisableDocument]]-এ; এখানে কেবল দুইটা কাজ: বদলের **আগে** উল্টানো, বদলের **পরে** আবার
 * বসানো। ⚠️ যে কাগজ নিজের পথে দুইটাই করে (কাউন্টারের সম্পাদনা — `SaleEditor`), তার এটা লাগে না —
 * সে [[RevisionKeeper::keep()]] ডাকে।
 */
interface RepostsAfterRevision extends RevisableDocument
{
    /**
     * খাতা আর মজুদের ছাপ উল্টানো — বদলের **আগে**, কাগজের নিজের তারিখে।
     *
     * ⓘ ডিফল্ট ([[KeepsRevisions]]) কেবল খাতা উল্টায়; মজুদ ছোঁয়া কাগজকে মজুদের অংশটা নিজে লিখতে হয়।
     */
    public function reverseForRevision(string $reason): void;

    /** বদলের পরে আবার বসানো — একই নম্বরে, নতুন অঙ্কে। মডিউলের নিজের পোস্টিংয়ের সব যাচাই আবার খাটে। */
    public function repostAfterRevision(): void;
}

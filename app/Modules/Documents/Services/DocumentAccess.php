<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Support\DocumentCatalog;

/**
 * গোপনীয়তার দরজা — কে কোন ধাপের কাগজ দেখবেন (§১৩, §১৪; ৮ অক্টোবর ২০২৬)।
 *
 * ── ⓘ সিঁড়ি ───────────────────────────────────────────────────────────
 * সবার জন্য আর অভ্যন্তরীণ — DOC দেখার চাবি (`documents.view`) থাকলেই।
 * গোপন → `documents.confidential`; অতি গোপন → `documents.highly_confidential`;
 * সংরক্ষিত → `documents.restricted` (পরিকল্পনা §১৪: *"restricted-এ বাড়তি permission"*)।
 * ⚠️ উপরের ধাপের চাবি নিচের সব ধাপ খোলে — যিনি অতি গোপন পড়েন, তাঁকে গোপনের
 * জন্য আলাদা টিক দিতে হলে ভূমিকার পর্দায় অর্থহীন জোড়া লাগত।
 *
 * ── ⓘ নিজের কাগজ সবসময় নিজের ─────────────────────────────────────────
 * কাগজের মালিক আর যিনি তুলেছেন, তাঁরা ধাপ যা-ই হোক নিজের কাগজ দেখেন। ⛔ নাহলে
 * কেউ নিজের চুক্তি "সংরক্ষিত" করে তুলে আর নিজেই খুঁজে পেতেন না।
 *
 * ── ⛔ এক নিয়ম, প্রতিটা পথে ──────────────────────────────────────────
 * তালিকা ([[Document::scopeVisibleTo()]]), বিস্তারিত, প্রিভিউ, নামানো, ছাপা — সবাই
 * এই একটা ক্লাস পড়ে। ⚠️ দুই জায়গায় দুইবার লিখলে একদিন একটা বদলাত আর অন্যটা নয়,
 * আর ফাঁকটা থাকত ঠিক সেই পথে যেটা কেউ পরীক্ষা করেনি।
 */
final class DocumentAccess
{
    /**
     * ধাপ => যে চাবি সেই ধাপ (আর তার নিচের সব) খোলে।
     *
     * @var array<string, string>
     */
    private const KEYS = [
        DocumentCatalog::CONFIDENTIAL => 'documents.confidential',
        DocumentCatalog::HIGHLY_CONFIDENTIAL => 'documents.highly_confidential',
        DocumentCatalog::RESTRICTED => 'documents.restricted',
    ];

    /**
     * এই মানুষ কোন ধাপগুলো দেখেন — নিজের কাগজ বাদে।
     *
     * @return list<string>
     */
    public function levelsFor(User $user): array
    {
        $top = 1; // ⓘ LEVELS-এর ১ = internal: চাবি ছাড়া এ পর্যন্ত

        foreach (self::KEYS as $level => $key) {
            if ($user->can($key)) {
                $top = max($top, (int) array_search($level, DocumentCatalog::LEVELS, true));
            }
        }

        return array_slice(DocumentCatalog::LEVELS, 0, $top + 1);
    }

    /** এই মানুষ এই কাগজটা দেখতে পান কি না — ধাপ, নয়তো নিজের কাগজ। */
    public function canSee(User $user, Document $document): bool
    {
        if ($this->isOwn($user, $document)) {
            return true;
        }

        return in_array((string) $document->confidentiality, $this->levelsFor($user), true);
    }

    /**
     * ধাপগুলোর মধ্যে কোনগুলো এই মানুষ কাগজে **বসাতে** পারেন।
     *
     * ⓘ যে ধাপ নিজে দেখেন না, সেই ধাপে অন্যের কাগজ লুকানো যায় না — নিজের কাগজ
     * হলেও নয়, কারণ মালিক বদলালে কাগজটা তখন আর কেউ খুঁজে পেতেন না।
     *
     * @return list<string>
     */
    public function levelsToChoose(User $user): array
    {
        return $this->levelsFor($user);
    }

    private function isOwn(User $user, Document $document): bool
    {
        $id = (int) $user->getKey();

        return (int) $document->owner_id === $id || (int) $document->created_by === $id;
    }
}

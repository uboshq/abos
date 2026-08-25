<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Engines\Audit\AuditEngine;
use App\Models\LookSkin;
use App\Models\LookSkinVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * রূপ প্রকাশ করা, আর দরকারে আগেরটায় ফেরা — থিম ইঞ্জিনের ধাপ ৩।
 *
 * ── কেন সেবা, কন্ট্রোলার নয় ─────────────────────────────────────────
 * প্রকাশ মানে চারটা কাজ একসাথে: গেট পাশ করানো, সংস্করণ বসানো, স্কিনে
 * সময় লেখা, খাতায় তোলা। কন্ট্রোলারে লিখলে দ্বিতীয় কোনো পথ (একটা
 * কমান্ড, একটা আমদানি) ওগুলোর দুইটা করে তৃতীয়টা ভুলত।
 */
final class LookSkinService
{
    public function __construct(private readonly AuditEngine $audit) {}

    /**
     * খসড়াটা প্রকাশ করা — সবাই এটাই দেখবেন।
     *
     * ── কেন গেটটা এখানেও, ফর্মে নয় ──────────────────────────────────
     * ফর্মেও যাচাই হয়, আর হওয়া উচিত — মানুষ সেভের সময়ই জানতে চান।
     * কিন্তু প্রকাশের পথ একাধিক হতে পারে (আমদানি, ফেরা, একটা কমান্ড),
     * আর পড়া যায় না এমন রূপ কারো পর্দায় পৌঁছানো **কোনো** পথেই চলবে না।
     *
     * তাই শেষ দরজাটা এখানে, আর এটা এড়ানো যায় না।
     *
     * @throws ValidationException
     */
    public function publish(LookSkin $skin, ?string $note = null, ?int $by = null): LookSkinVersion
    {
        $this->refuseIfUnreadable($skin);
        $this->refuseIfParentIsUnpublished($skin);

        return DB::transaction(function () use ($skin, $note, $by): LookSkinVersion {
            $version = $this->snapshot($skin, $note, $by);

            /*
             * `published_at` মানে "অন্তত একবার প্রকাশ হয়েছে"।
             *
             * সর্বশেষ কবে, সেটা সংস্করণের সারিতেই আছে। এখানে সময়টা
             * রাখা হয় কেবল `scopePublished()`-এর জন্য — তালিকার
             * কোয়েরিতে প্রতিবার সংস্করণের টেবিলে জয়েন করার চেয়ে
             * একটা ঘর দেখা সস্তা।
             */
            $skin->forceFill(['published_at' => now()])->save();

            $this->audit->recordAction($skin, 'look_published');

            return $version;
        });
    }

    /**
     * পুরনো একটা সংস্করণে ফেরা।
     *
     * ── কেন মুছে ফেলা নয় ────────────────────────────────────────────
     * তিন নম্বরে ফিরতে চাইলে চার নম্বরটা মোছা হয় না — তিনের কপি নিয়ে
     * **পাঁচ নম্বর** বসে, আর `reverted_from`-এ লেখা থাকে কোথা থেকে।
     *
     * ফেরাটাও একটা ভুল হতে পারে, আর তখন ফেরার-ফেরাটাও লাগে। ইতিহাস
     * মুছে ফেললে দ্বিতীয়বার আর কিছু করার থাকত না।
     *
     * খসড়াটাও ফিরিয়ে আনা হয়, নাহলে সম্পাদনার পর্দা খুললে সেই পুরনো
     * ভুল রূপটাই দেখা যেত — আর পরের প্রকাশে সেটাই আবার ফিরে আসত।
     *
     * @throws ValidationException
     */
    public function revert(LookSkin $skin, LookSkinVersion $to, ?int $by = null): LookSkinVersion
    {
        if ((int) $to->look_skin_id !== (int) $skin->id) {
            throw ValidationException::withMessages([
                'version' => __('core.look.version_not_this_skin'),
            ]);
        }

        return DB::transaction(function () use ($skin, $to, $by): LookSkinVersion {
            $skin->forceFill([
                'parent' => $to->parent,
                'tokens' => $to->tokens,
            ])->save();

            $version = $this->snapshot(
                $skin->fresh(),
                __('core.look.reverted_note', ['version' => $to->version]),
                $by,
                revertedFrom: (int) $to->version,
            );

            $skin->forceFill(['published_at' => now()])->save();

            $this->audit->recordAction($skin, 'look_reverted');

            return $version;
        });
    }

    /**
     * খসড়াটা প্রকাশযোগ্য কি না — না হলে কারণসহ ফেরত।
     *
     * @throws ValidationException
     */
    private function refuseIfUnreadable(LookSkin $skin): void
    {
        $complaints = $skin->complaints();

        if ($complaints !== []) {
            throw ValidationException::withMessages(['tokens' => $complaints]);
        }
    }

    /**
     * অপ্রকাশিত কিছুর উপর দাঁড়ানো রূপ প্রকাশ হয় না।
     *
     * ── কেন এই নিয়মটা লাগে ──────────────────────────────────────────
     * সন্তান কেবল নিজের বদলগুলো রাখে; বাকিটা পূর্বপুরুষ থেকে নামে।
     * পূর্বপুরুষটা যদি খসড়া হয়, তবে সন্তান প্রকাশ করা মানে এমন কিছু
     * সবার পর্দায় পাঠানো যা কেউ প্রকাশযোগ্য বলে মেনে নেয়নি — আর সেই
     * খসড়াটা গেটও পাশ করেনি।
     *
     * নিয়মটা সহজ ও বলা যায় এমন: **যা প্রকাশ হয়নি তার উপর দাঁড়ানো
     * যায় না।**
     *
     * @throws ValidationException
     */
    private function refuseIfParentIsUnpublished(LookSkin $skin): void
    {
        $parent = LookSkin::query()->withoutGlobalScopes()
            ->where('public_id', $skin->parent)->first();

        if ($parent !== null && $parent->live() === null) {
            throw ValidationException::withMessages([
                'parent' => __('core.look.parent_is_draft', ['name' => $parent->name]),
            ]);
        }
    }

    /**
     * খসড়ার একটা ছবি তুলে সংস্করণ হিসেবে বসানো।
     *
     * ── ক্রমটা কেন স্কিনের ভিতরে গোনা ────────────────────────────────
     * মানুষ বলেন "আমাদের রূপের তিন নম্বর", "রূপ-সংস্করণ ৪১৭" নয়।
     */
    private function snapshot(LookSkin $skin, ?string $note, ?int $by, ?int $revertedFrom = null): LookSkinVersion
    {
        $next = 1 + (int) LookSkinVersion::query()
            ->where('look_skin_id', $skin->id)
            ->max('version');

        return LookSkinVersion::query()->create([
            'look_skin_id' => $skin->id,
            'version' => $next,
            'parent' => $skin->parent,
            'tokens' => $skin->tokens ?? [],
            'note' => $note,
            'reverted_from' => $revertedFrom,
            'published_at' => now(),
            'published_by' => $by ?? auth()->id(),
        ]);
    }
}

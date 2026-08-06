<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Engines\Attachment\AttachmentException;
use App\Core\Engines\Drill\DrillResolver;
use App\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ডকুমেন্টের কাগজপত্র — সরবরাহকারীর বিল, চালানের ছবি, ব্যাংক স্লিপ।
 *
 * ── কেন এটা কোরে, কোনো মডিউলে নয় ────────────────────────────────────
 * কাগজ যেকোনো ডকুমেন্টের সাথে লাগে — ক্রয় বিলে, বিক্রয় চালানে, ভাউচারে।
 * প্রতিটা মডিউলে আলাদা আপলোড লিখলে ছয় জায়গায় একই কোড থাকত, আর ফাইল
 * নিরাপত্তার ভুলটা যেকোনো একটাতে থেকে যেত।
 *
 * ── তবু কোর কোনো মডিউলের নাম জানে না ────────────────────────────────
 * কাগজটা কোন ডকুমেন্টের, সেটা আসে (source_type, id) জোড়া থেকে — ঠিক
 * যেভাবে ড্রিল-ডাউন কাজ করে। রেজিস্ট্রি থেকে মডেলটা বেরোয়, আর অনুমতির
 * প্রশ্নটা ওই মডেলের নিজের পলিসিকেই করা হয়: যে বিলটা দেখতে পারে সে তার
 * কাগজও দেখতে পারে, আর যে বিলটা বদলাতে পারে সে কাগজ যোগ করতে পারে।
 */
class AttachmentController extends Controller
{
    public function __construct(
        private readonly AttachmentEngine $attachments,
        private readonly DrillResolver $drill,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'source_type' => ['required', 'string', 'max:64'],
            'source_id' => ['required', 'integer', 'min:1'],

            /*
             * ফাইলের ধরন এখানে বাঁধা হয় না — ইঞ্জিনের নিষিদ্ধ তালিকাই
             * শেষ কথা, আর সেটা এক জায়গায় থাকা দরকার। এখানে দ্বিতীয়
             * একটা তালিকা রাখলে একদিন দুইটা আলাদা হত, আর তখন কোনটা
             * সত্যি তা বলা যেত না।
             */
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $document = $this->document($validated['source_type'], (int) $validated['source_id']);

        $this->authorizeAttaching($document);

        $module = $this->drill->moduleFor($validated['source_type']);

        if ($module === null) {
            throw ValidationException::withMessages([
                'file' => __('core.attachment.unknown_source'),
            ]);
        }

        /*
         * ইঞ্জিনের "না" ফর্মের ভুল হয়ে ফেরে, ৫০০ হয়ে নয়।
         *
         * ── কেন ─────────────────────────────────────────────────────
         * ইঞ্জিনটা নিরাপত্তার সিদ্ধান্ত নেয় আর ব্যতিক্রম ছোঁড়ে — সেটাই
         * ঠিক, ওটা কোনো পর্দার কথা জানে না। কিন্তু ব্যবহারকারী একটা
         * ভুল ফাইল বেছে ফেললে তাঁর পাওয়ার কথা "এই ধরনের ফাইল রাখা
         * যায় না", একটা ভাঙা পাতা নয়। ফাইলটা তখনও ফেরানো হচ্ছিল —
         * অর্থাৎ পাহারা ঠিকই ছিল, শুধু বার্তাটা মানুষের জন্য ছিল না।
         */
        try {
            $this->attachments->store(
                file: $request->file('file'),
                module: $module,
                entity: $validated['source_type'],
                entityId: (int) $validated['source_id'],
            );
        } catch (AttachmentException $refused) {
            throw ValidationException::withMessages([
                'file' => __('core.attachment.refused', ['reason' => $refused->getMessage()]),
            ]);
        }

        return back()->with('saved', __('core.attachment.uploaded'));
    }

    /**
     * কাগজটা নামানো।
     *
     * ── কেন ফাইলটা সরাসরি public ফোল্ডারে নয় ────────────────────────
     * সরবরাহকারীর বিলে দাম লেখা থাকে, ব্যাংক স্লিপে হিসাব নম্বর। ওগুলো
     * public/ থাকলে ঠিকানা জানা যে কেউ খুলত — লগইন ছাড়াই। তাই ফাইল
     * থাকে বাইরে, আর প্রতিটা নামানোর আগে ডকুমেন্টের পলিসিকে জিজ্ঞেস
     * করা হয়।
     */
    public function download(Attachment $attachment): StreamedResponse|Response
    {
        $document = $this->document($attachment->source_entity, (int) $attachment->source_entity_id);

        $this->authorize('view', $document);

        if (! $this->attachments->exists($attachment)) {
            abort(404);
        }

        return response()->streamDownload(
            fn () => print ($this->attachments->contents($attachment)),
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type ?: 'application/octet-stream'],
        );
    }

    /**
     * কাগজ সরানো।
     *
     * ফাইলটা ডিস্কে থেকে যায় (ইঞ্জিনের সিদ্ধান্ত) — সারিটা নরম-মোছা হয়,
     * আর অডিটে কে কখন সরাল তা লেখা থাকে। ভুল ছবি তোলা হলে সরানো দরকার,
     * কিন্তু "কাগজটা কোথায় গেল" প্রশ্নের উত্তরও থাকা দরকার।
     */
    public function destroy(Attachment $attachment): RedirectResponse
    {
        $document = $this->document($attachment->source_entity, (int) $attachment->source_entity_id);

        $this->authorizeAttaching($document);

        $this->attachments->delete($attachment);

        return back()->with('saved', __('core.attachment.removed'));
    }

    /**
     * কাগজ যোগ বা সরানোর অনুমতি।
     *
     * ── কেন 'update' নয়, 'create' ───────────────────────────────────
     * ডকুমেন্টের update নিয়মে প্রায় সব মডিউলে একটা শর্ত আছে: "খসড়া
     * হলে তবেই"। কাগজে সেটা খাটে না — সরবরাহকারীর আসল বিলটা হাতে
     * আসে বিল পোস্ট করার পরে, কখনো পরদিন। update ধরলে ঠিক যে
     * ক্ষেত্রটার জন্য এই ব্যবস্থাটা বানানো, সেখানেই আপলোডের বোতামটা
     * থাকত না।
     *
     * ধরা পড়েছে পর্দা চালিয়ে: নিশ্চিত হওয়া বিলের পাতায় কাগজের তালিকা
     * ছিল, যোগ করার ঘরটা ছিল না।
     *
     * তাই যিনি এই ধরনের ডকুমেন্ট তৈরি করতে পারেন তিনিই তার কাগজ
     * রাখতে পারেন — অবস্থা যা-ই হোক।
     */
    private function authorizeAttaching(Model $document): void
    {
        $this->authorize('create', $document::class);
    }

    /**
     * যে ডকুমেন্টের কাগজ — না পেলে ৪০৪।
     */
    private function document(string $sourceType, int $sourceId): Model
    {
        $document = $this->drill->resolve($sourceType, $sourceId);

        if ($document === null) {
            abort(404);
        }

        return $document;
    }
}

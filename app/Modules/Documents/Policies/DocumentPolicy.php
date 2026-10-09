<?php

declare(strict_types=1);

namespace App\Modules\Documents\Policies;

use App\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\DocumentAccess;
use App\Modules\Documents\Support\DocumentCatalog;

/**
 * ডকুমেন্টের দরজা — প্রতিটা কাজের নিজের চাবি (§১৩; ৮-৯ অক্টোবর ২০২৬)।
 *
 * ⓘ চাবিগুলো: দেখা, তোলা, বদলানো, মোছা, নামানো, ছাপা, আর্কাইভ, ফেরানো, চিরতরে মোছা,
 * অধিকার দেওয়া (`documents.view` … module.php)। ⭐ কাগজের উপর প্রতিটা কাজ আগে "দেখা" চায়
 * ([[DocumentAccess::canSee()]])।
 *
 * ⭐ কাগজ-ধরে অধিকার (দ্বিতীয় ধাপ): নামানো, ছাপা, বদল — মডিউলের চাবি **বা** এই কাগজে দেওয়া
 * অধিকার ([[DocumentAccess::granted()]])। ⛔ মোছা, আর্কাইভ, ফেরানো কখনো কাগজ-ধরে নয় — ওগুলো
 * কাগজের জীবন বদলায়, তাই কেবল ভূমিকার চাবিতে।
 *
 * ⚠️ কোম্পানি আর শাখার দেয়াল এখানে নয় — মডেলের গ্লোবাল স্কোপে, তাই ঐ কাগজ পলিসি
 * পর্যন্ত পৌঁছায়ই না (৪০৪)।
 */
class DocumentPolicy
{
    public function __construct(private readonly DocumentAccess $access) {}

    public function view(User $user, Document $document): bool
    {
        return $user->can('documents.view') && $this->access->canSee($user, $document);
    }

    public function create(User $user): bool
    {
        return $user->can('documents.upload');
    }

    /**
     * বিবরণ বদল, নিজের জায়গায়।
     *
     * ⛔ অনুমোদিত বা প্রকাশিত কাগজ নয় (§৯: *"Approved document সরাসরি overwrite করা যাবে না"*) —
     * তার বদল নতুন ভার্সন ([[addVersion()]])। ⛔ জমা বা পর্যালোচনায় থাকা কাগজও নয় — যিনি সই
     * করছেন তিনি যা পড়ছেন সেটা নড়বে না। ⛔ আর্কাইভ করা কাগজও নয় — আগে ফেরান।
     */
    public function update(User $user, Document $document): bool
    {
        return $this->mayEdit($user, $document)
            && ! $document->isArchived()
            && $document->isEditableInPlace();
    }

    /** নতুন ভার্সন — অনুমোদিত কাগজেও চলে, কারণ বদলের পথ এটাই; পর্যালোচনার মাঝে নয় */
    public function addVersion(User $user, Document $document): bool
    {
        return $this->mayEdit($user, $document)
            && ! $document->isArchived()
            && ($document->isEditableInPlace() || $document->isApproved());
    }

    /** পুরনো ভার্সন ফেরানো — নতুন ভার্সন বানায়, তাই বদলের চাবিও লাগে */
    public function restoreVersion(User $user, Document $document): bool
    {
        return $user->can('documents.restore') && $this->addVersion($user, $document);
    }

    public function download(User $user, Document $document): bool
    {
        return $this->view($user, $document)
            && ($user->can('documents.download') || $this->access->granted($user, $document, 'download'));
    }

    public function print(User $user, Document $document): bool
    {
        return $this->view($user, $document)
            && ($user->can('documents.print') || $this->access->granted($user, $document, 'print'));
    }

    public function archive(User $user, Document $document): bool
    {
        return $user->can('documents.archive') && $this->view($user, $document) && ! $document->isArchived();
    }

    public function unarchive(User $user, Document $document): bool
    {
        return $user->can('documents.restore') && $this->view($user, $document) && $document->isArchived();
    }

    public function delete(User $user, Document $document): bool
    {
        return $user->can('documents.delete') && $this->view($user, $document) && ! $document->trashed();
    }

    /** রিসাইকেল বিন থেকে ফেরানো (§১৯) */
    public function restore(User $user, Document $document): bool
    {
        return $user->can('documents.restore') && $this->view($user, $document) && $document->trashed();
    }

    /** ⛔ চিরতরে মোছা — কেবল বিনের কাগজ, আর নিজের আলাদা চাবিতে (§১৯ "permission অনুযায়ী") */
    public function forceDelete(User $user, Document $document): bool
    {
        return $user->can('documents.purge') && $this->view($user, $document) && $document->trashed();
    }

    /** কাগজ-ধরে অধিকার দেওয়া বা সরানো (§১৩) */
    public function grant(User $user, Document $document): bool
    {
        return $user->can('documents.permissions') && $this->view($user, $document);
    }

    /** অনুমোদনে পাঠানো আর ফেরত নেওয়া (§১০) — কাগজ দেখতে পারতে হবে */
    public function submit(User $user, Document $document): bool
    {
        return $user->can('documents.submit') && $this->view($user, $document) && ! $document->isArchived();
    }

    /** প্রকাশ — কেবল অনুমোদিত কাগজ */
    public function publish(User $user, Document $document): bool
    {
        return $user->can('documents.publish') && $this->view($user, $document)
            && $document->status === DocumentCatalog::APPROVED;
    }

    private function mayEdit(User $user, Document $document): bool
    {
        return $this->view($user, $document)
            && ($user->can('documents.edit') || $this->access->granted($user, $document, 'edit'));
    }
}

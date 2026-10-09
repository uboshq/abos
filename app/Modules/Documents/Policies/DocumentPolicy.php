<?php

declare(strict_types=1);

namespace App\Modules\Documents\Policies;

use App\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\DocumentAccess;

/**
 * ডকুমেন্টের দরজা — প্রতিটা কাজের নিজের চাবি (§১৩; ৮ অক্টোবর ২০২৬)।
 *
 * ⓘ চাবিগুলো: দেখা, তোলা, বদলানো, মোছা, নামানো, ছাপা, আর্কাইভ, ফেরানো
 * (`documents.view` … `documents.restore`, module.php)। ⭐ কাগজের উপর প্রতিটা কাজ
 * আগে "দেখা" চায় — যে কাগজ আপনার গোপনীয়তার ধাপের বাইরে, তাতে কোনো কাজই নেই
 * ([[DocumentAccess::canSee()]])।
 *
 * ⚠️ কোম্পানি আর শাখার দেয়াল এখানে নয় — মডেলের গ্লোবাল স্কোপে, তাই ঐ কাগজ পলিসি
 * পর্যন্ত পৌঁছায়ই না (৪০৪)।
 */
class DocumentPolicy
{
    public function __construct(private readonly DocumentAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $user->can('documents.view');
    }

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
     * ⛔ অনুমোদিত কাগজ নয় (§৯: *"Approved document সরাসরি overwrite করা যাবে না"*) —
     * তার বদল নতুন ভার্সন ([[addVersion()]])। ⛔ আর্কাইভ করা কাগজও নয় — আগে ফেরান।
     */
    public function update(User $user, Document $document): bool
    {
        return $user->can('documents.edit')
            && $this->view($user, $document)
            && ! $document->isArchived()
            && ! $document->isApproved();
    }

    /** নতুন ভার্সন — অনুমোদিত কাগজেও চলে, কারণ বদলের পথ এটাই */
    public function addVersion(User $user, Document $document): bool
    {
        return $user->can('documents.edit')
            && $this->view($user, $document)
            && ! $document->isArchived();
    }

    /** পুরনো ভার্সন ফেরানো — নতুন ভার্সন বানায়, তাই বদলের চাবিও লাগে */
    public function restoreVersion(User $user, Document $document): bool
    {
        return $user->can('documents.restore') && $this->addVersion($user, $document);
    }

    public function download(User $user, Document $document): bool
    {
        return $user->can('documents.download') && $this->view($user, $document);
    }

    public function print(User $user, Document $document): bool
    {
        return $user->can('documents.print') && $this->view($user, $document);
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
        return $user->can('documents.delete') && $this->view($user, $document);
    }
}

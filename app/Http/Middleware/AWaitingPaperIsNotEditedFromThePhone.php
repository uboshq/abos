<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Engines\Approval\WaitingPaper;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⛔ সইয়ের অপেক্ষার কাগজ ফোন থেকেও কেউ বদলান না — মালিকের চূড়ান্ত সিদ্ধান্ত, ২০ নভেম্বর ২০২৬ ([[WaitingPaper]];
 * ওয়েবের দরজা [[AWaitingPaperIsNotEdited]])।
 *
 * ── কেন আলাদা ─────────────────────────────────────────────────────────
 * ফোনের রুটে কাগজ বাঁধা থাকে না — ঠিকানায় `{id}` = `public_id` লেখা, কাগজটা কন্ট্রোলার নিজে খোঁজে। তাই ওয়েবের মতো "রুটের
 * ঘরে মডেল আছে কি না" দেখা যায় না; রুট নিজে বলে কোন ধরনের কাগজ ([[for()]])। ⭐ কাগজ সইয়ের অপেক্ষায় থাকলে কিছু না লিখে ৪০৯,
 * বার্তা "অনুমোদনের অপেক্ষায় — বদলাতে হলে সংশোধনের জন্য ফেরত পাঠান"। ⓘ সই, ফেরত আর "না"-র দরজা (`/approvals/...`) এর বাইরে।
 *
 * ⚠️ সুপারভাইজারের "অনুমোদিত পরিমাণ" দরজা (`/orders/{id}/approved-quantities`, `/delivery-orders/{id}/approved-quantities`) এখনো
 * বাইরে, জেনেবুঝে: সেটা কেবল অপেক্ষায় চলে (সইকারীর সিদ্ধান্তের অংশ), আর আদেশ সংশোধনে ফেরত যায় না (কাগজ খসড়া নয় —
 * [[ApprovalEngine::canSendBack()]]); পাহারা বসালে সুপারভাইজারের হাতে কেবল হ্যাঁ/না থাকে। ওয়েবের `*.quantities`-ও একই।
 * মালিক বললে রুটে `->middleware(self::for(...))` এক লাইন।
 *
 * ⓘ কাগজ খুঁজে না পেলে (অন্য কোম্পানি, নাগালের বাইরে, ভুল id) কিছু না বলে কন্ট্রোলারে ছাড়ে — সেখানে আজকের মতো ৪০৪/৪০৩।
 * ⚠️ "একবারের লেখা" ([[RemembersAPhoneWrite]])-র আগে বসে, যাতে ফেরানো উত্তরটা মনে রাখা না হয় — ফেরত পাঠানোর পরে একই
 * চাবিতে আবার পাঠালে আসল কাজটা হয়।
 */
class AWaitingPaperIsNotEditedFromThePhone
{
    public function __construct(private readonly WaitingPaper $waiting) {}

    /** রুটে বসানোর লেখা — `->middleware(AWaitingPaperIsNotEditedFromThePhone::for(SalesOrder::class))` */
    public static function for(string $paperClass): string
    {
        return static::class.':'.$paperClass;
    }

    public function handle(Request $request, Closure $next, string $paperClass = ''): Response
    {
        $id = $request->route('id');

        if ($paperClass === '' || ! is_subclass_of($paperClass, Model::class) || ! is_string($id) || $id === '') {
            return $next($request);
        }

        /** @var Model|null $paper */
        $paper = $paperClass::query()
            ->when(Str::isUuid($id), fn ($q) => $q->where('public_id', $id), fn ($q) => $q->whereKey((int) $id))
            ->first();

        if ($paper === null || ! $this->waiting->waits($paper)) {
            return $next($request);
        }

        return response()->json([
            'message' => __('core.approval_hold.hint'),
            'reason' => 'waiting_for_approval',
        ], 409);
    }
}

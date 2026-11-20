<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Engines\Approval\WaitingPaper;
use App\Core\Services\FormBranchPin;
use App\Core\Services\FormIsNotSubmittedTwice;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⛔ সইয়ের অপেক্ষার কাগজ কেউ বদলাতে পারেন না — মালিকের চূড়ান্ত সিদ্ধান্ত, ২০ নভেম্বর ২০২৬ ([[WaitingPaper]])।
 *
 * ⓘ কেন দরজায়, প্রতিটা নীতিতে নয়: কাগজ ২০টার বেশি মডিউলে, আর প্রতিটা নীতির `update()` আলাদা মানুষ লিখেছেন — একটায় ভুলে
 * গেলে ঐ কাগজটাই আবার COL-0008 হত। ⭐ নাম ধরে: প্রতিটা `*.edit` (ফর্ম খোলা) আর `*.update` (জমা) — রুটে বাঁধা কাগজের
 * কোনোটা সইয়ের অপেক্ষায় থাকলে কিছু না লিখে কাগজের পাতায় ফেরত, বার্তাসহ; JSON হলে ৪০৯।
 *
 * ⚠️ বাঁধাইয়ের **পরে** চলে (`SubstituteBindings` web গোষ্ঠীর আগে বসে) — তাই রুটের ঘরে আসল মডেল থাকে। ⓘ ফেরত পাঠানো
 * (`returned`) কাগজে অপেক্ষার অনুরোধ নেই, তাই লেখক আজকের মতোই বদলান। ⓘ ফোনের দরজা (`/api`) নিজের পাহারায় ([[AWaitingPaperIsNotEditedFromThePhone]], ২০ নভেম্বর ২০২৬)।
 */
class AWaitingPaperIsNotEdited
{
    public function __construct(
        private readonly WaitingPaper $waiting,
        private readonly FormIsNotSubmittedTwice $forms,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $name = (string) $route?->getName();

        if ($route === null || ! (str_ends_with($name, '.edit') || str_ends_with($name, '.update')) || str_starts_with($name, 'approval.')) {
            return $next($request);
        }

        $paper = null;

        foreach ($route->parameters() as $value) {
            if ($value instanceof Model && $this->waiting->waits($value)) {
                $paper = $value;

                break;
            }
        }

        if ($paper === null) {
            return $next($request);
        }

        $token = $request->attributes->get(FormIsNotSubmittedTwice::FIELD);

        if (is_string($token) && $token !== '') {
            $this->forms->release($token);
        }

        $message = __('core.approval_hold.refused');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 409);
        }

        // ⓘ কাগজের নিজের পাতায় — সেখান থেকেই সই, ফেরত বা "না"; পাতা না থাকলে যেখান থেকে এসেছিলেন
        $show = substr($name, 0, (int) strrpos($name, '.')).'.show';
        $target = Route::has($show) ? $this->tryRoute($show, $route->parameters()) : null;

        return ($target !== null ? redirect()->to($target) : back())
            ->with(FormBranchPin::OFFER, ['message' => $message]);
    }

    /** @param  array<string, mixed>  $parameters */
    private function tryRoute(string $name, array $parameters): ?string
    {
        try {
            return route($name, $parameters);
        } catch (\Throwable) {
            return null;
        }
    }
}

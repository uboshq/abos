<?php

declare(strict_types=1);

namespace App\Modules\Mail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Mail\Services\MailOptOuts;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * ⭐ "এমন মেইল আর চাই না" — লগইন ছাড়া খোলে, কারণ যিনি খোলেন তিনি আমাদের গ্রাহক, কর্মী নন (NEXUS-এর পর্যালোচনা R2; RFC 8058)।
 *
 * ⛔ এখানে চাবি হলো সই করা লিংক (`signed`)। লিংক না মিললে 403; এই পথে ঠিকানাটা বন্ধ করা ছাড়া আর কিছুই হয় না।
 * ⓘ দুই পথ:
 *   · GET — মানুষ লিংকে চাপেন। "বন্ধ করুন" বোতামসহ পাতা খোলে। মেইল প্রোগ্রাম আগে থেকে লিংক খুলে দেখলে
 *     তাতে কিছু বন্ধ হয় না (RFC 8058 §1)।
 *   · POST — পাতার বোতাম, বা মেইল প্রোগ্রামের নিজের "Unsubscribe" (`List-Unsubscribe=One-Click`)। এতেই বন্ধ হয়।
 */
final class MailUnsubscribeController extends Controller
{
    /** ⓘ এক রুট, দুই পথ — GET পাতা দেখায়, POST বন্ধ করে */
    public function __invoke(Request $request, MailOptOuts $optOuts): View|Response
    {
        return $request->isMethod('POST') ? $this->stop($request, $optOuts) : $this->show($request);
    }

    public function show(Request $request): View
    {
        return view('mail::unsubscribe', ['email' => (string) $request->query('e'), 'done' => false, 'action' => $request->fullUrl()]);
    }

    public function stop(Request $request, MailOptOuts $optOuts): View|Response
    {
        $email = (string) $request->query('e');
        $company = (int) $request->query('c');
        abort_if($email === '' || $company <= 0 || ! filter_var($email, FILTER_VALIDATE_EMAIL), 404);

        $oneClick = $request->input('List-Unsubscribe') === 'One-Click';
        $optOuts->stop($company, $email, $request->query('s') ? (string) $request->query('s') : null, $oneClick ? 'one-click' : 'link');

        // ⓘ মেইল প্রোগ্রামের ডাকে পাতা নয়, কেবল "হয়েছে"
        if ($oneClick) {
            return response('', 200);
        }

        return view('mail::unsubscribe', ['email' => $email, 'done' => true, 'action' => null]);
    }
}

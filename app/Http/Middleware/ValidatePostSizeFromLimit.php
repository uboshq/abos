<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Support\UploadLimit;
use Illuminate\Http\Middleware\ValidatePostSize;

/**
 * ⭐ Laravel-এর POST-মাপের পাহারা, সংখ্যাটা [[UploadLimit::postMax()]] থেকে (fe, ২১ অক্টোবর ২০২৬)।
 *
 * ⓘ কাজ হুবহু আগেরটা — `CONTENT_LENGTH` সীমার বেশি হলে PostTooLargeException, সেশন আর CSRF-এর আগে। কেবল সংখ্যাটা এক
 * জায়গা থেকে: ফর্মের পাশের "সর্বোচ্চ X MB" যেখান থেকে, এটাও সেখান থেকে; আর পরীক্ষা কনফিগে লাইভের সীমা বসাতে পারে।
 */
class ValidatePostSizeFromLimit extends ValidatePostSize
{
    protected function getPostMaxSize()
    {
        return UploadLimit::postMax();
    }
}

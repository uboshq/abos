<?php

declare(strict_types=1);

namespace App\Core\Engines\Attachment;

/**
 * ⛔ এই ধরনের ফাইল কাগজ হিসেবে নেওয়া হয় না — বাইটের ধরন অনুমতি-তালিকায় নেই, নাম-লেজ ধরনের সাথে মেলে না, বা ফাইলটা কোড
 * ([[AttachmentEngine::GENERAL]], [[AttachmentEngine::FORBIDDEN]]; ফাঁক ৪৮, ২০ অক্টোবর ২০২৬)।
 *
 * ⓘ বার্তাটা আগের ইংরেজিই থাকে (ডেভেলপার আর অন্য দরজার জন্য)। ⭐ সাধারণ কাগজের দরজা ([[AttachmentController::store()]]) এই
 * ধরন চিনে মানুষকে বাংলায় বলে — আগে "ফাইলটি নেওয়া গেল না — Files of type .pdf cannot be attached. Allowed: …" পড়ত।
 */
final class NotAllowedType extends AttachmentException {}

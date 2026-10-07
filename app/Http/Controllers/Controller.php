<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * সব কন্ট্রোলারের ভিত্তি।
 *
 * AuthorizesRequests এখানে, কারণ অলঙ্ঘনীয় শর্ত ৪ বলে "প্রতিটা রুটে
 * permission" — আর $this->authorize() যেন সব কন্ট্রোলারেই হাতের কাছে
 * থাকে। Laravel ১১ থেকে বেস কন্ট্রোলার খালি আসে, যা ছোট অ্যাপের জন্য
 * যুক্তিসঙ্গত।
 *
 * সতর্কতা: এই trait-এর authorizeResource() এখানে চলবে না — ওটা
 * $this->middleware() ডাকে, যেটা Laravel ১১-এ কন্ট্রোলার থেকে সরে গেছে।
 * resource কন্ট্রোলারে App\Core\Concerns\AuthorizesResource ব্যবহার করুন।
 */
abstract class Controller
{
    use AuthorizesRequests;
}

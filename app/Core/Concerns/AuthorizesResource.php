<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use Illuminate\Routing\Controllers\Middleware;

/**
 * resource কন্ট্রোলারের সাতটা পদ্ধতিতে অনুমতি বসানো — অলঙ্ঘনীয় শর্ত ৪।
 *
 * Laravel-এর নিজের authorizeResource() এখানে চলে না। ওটা কনস্ট্রাক্টর থেকে
 * $this->middleware() ডাকে, আর Laravel ১১ থেকে কন্ট্রোলারে ওই পদ্ধতিটাই
 * নেই — মিডলওয়্যার এখন static middleware() থেকে আসে। ফলে ওটা ডাকলে
 * ৫০০ পড়ে, নীরবে খোলা থাকে না; তবু ভুলটা ধরা পড়ে চালানোর সময়।
 *
 * এই trait একই ছকটা নতুন ব্যবস্থায় লিখে দেয়, এক জায়গায়। প্রতিটা
 * কন্ট্রোলারে সাতটা করে can: লাইন হাতে লিখলে একদিন একটা বাদ যেত, আর
 * বাদ পড়া লাইনটা কোনো ভুল দেখাত না — শুধু ওই একটা পদ্ধতি সবার জন্য
 * খুলে থাকত।
 */
trait AuthorizesResource
{
    /**
     * কোন পদ্ধতি কোন ক্ষমতা চায়।
     *
     * নামগুলো Laravel-এর AuthorizesRequests-এর সাথে ইচ্ছাকৃতভাবে আলাদা:
     * ওখানে একই কাজের non-static পদ্ধতি আছে, আর দুইটা trait একসাথে
     * ব্যবহার করলে PHP static ও non-static মেলাতে না পেরে থেমে যায়।
     *
     * store → create আর edit → update, কারণ ফর্ম দেখা আর সেভ করা একই
     * অধিকারের দুই ধাপ; আলাদা করলে কেউ ফর্ম দেখতে পেয়েও সেভ করতে না
     * পেরে বুঝত না কী হয়েছে।
     *
     * @return array<string, string>
     */
    protected static function abilityPerResourceMethod(): array
    {
        return [
            'index' => 'viewAny',
            'create' => 'create',
            'store' => 'create',
            'show' => 'view',
            'edit' => 'update',
            'update' => 'update',
            'destroy' => 'delete',
        ];
    }

    /**
     * যে পদ্ধতিগুলো কোনো একটা রেকর্ড নয়, পুরো ধরনের উপর কাজ করে।
     *
     * এদের ক্ষেত্রে মডেলের শ্রেণিনাম যায়, রুট প্যারামিটার নয় — কারণ
     * তখনো কোনো রেকর্ড নেই।
     *
     * @return list<string>
     */
    protected static function resourceMethodsActingOnTheType(): array
    {
        return ['index', 'create', 'store'];
    }

    /**
     * @param  class-string  $model  মডেলের শ্রেণি
     * @param  string  $parameter  রুট প্যারামিটারের নাম
     * @return list<Middleware>
     */
    protected static function resourcePermissions(string $model, string $parameter): array
    {
        $withoutModel = static::resourceMethodsActingOnTheType();

        $middleware = [];

        foreach (static::abilityPerResourceMethod() as $method => $ability) {
            $target = in_array($method, $withoutModel, true) ? $model : $parameter;

            $middleware[] = new Middleware("can:{$ability},{$target}", only: [$method]);
        }

        return $middleware;
    }
}

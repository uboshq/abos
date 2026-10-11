<?php

declare(strict_types=1);

namespace App\Modules\Executive\Support;

/**
 * একটা ঘরের "কোথায় যাবে" — [[OpenController]]-এর `go` মান।
 *
 * ⓘ পাতার প্রতিটা ঘর একই ফর্মের একটা বোতাম, আর বোতামের মান একটাই — তাই কোম্পানি, শাখা, পাতার নাম
 * আর ঠিকানার অংশ একটা কোয়েরি-লেখায় বাঁধা। খালি অংশ বাদ যায় (শাখা নেই = সব শাখা)।
 */
final class Go
{
    /**
     * @param  array<string, mixed>  $params
     */
    public static function to(int $company, ?int $branch, string $route, array $params = []): string
    {
        return http_build_query(array_filter(
            ['company' => $company, 'branch' => $branch, 'route' => $route, 'params' => array_filter($params, fn ($v) => $v !== null)],
            fn ($v) => $v !== null && $v !== [],
        ));
    }
}

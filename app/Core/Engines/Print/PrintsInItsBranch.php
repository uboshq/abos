<?php

declare(strict_types=1);

namespace App\Core\Engines\Print;

use App\Core\Services\BranchSettings;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ কাগজ তার নিজের শাখার মাথায় ছাপা — শাখার নাম-ঠিকানা, লোগো, মাপ আর নকশা (পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬, ছাপা ১৮;
 * [[EveryPaperWearsItsBranchsHeadTest]])।
 *
 * ⓘ বিক্রয়ের ছাপা ([[SalesPrintController::callAction()]]) আর ভাউচার আগে থেকেই [[BranchSettings::during()]] ডাকত; ক্রয়, মজুদ-বদলি,
 * টাকা-বদলি আর বেতনশিট ডাকত না — নেত্রকোনার ক্রয় বিলেও কোম্পানির মাথা আর লোগো। ⓘ প্রতিটা কাজের আগে, রুটে বাঁধা কাগজের শাখা ধরে;
 * নকশা আর মাপ `pdf()`-এর আগেই পড়া হয়, তাই কেবল ছাপার মুহূর্তে মোড়ালে চলত না। শাখা না থাকলে কোম্পানির সেটিং, আগের মতোই।
 */
trait PrintsInItsBranch
{
    /** @param  array<string, mixed>  $parameters */
    public function callAction($method, $parameters): mixed
    {
        $document = collect($parameters)->first(fn ($value) => $value instanceof Model);

        return app(BranchSettings::class)->during(
            $document === null ? null : $this->branchOfPaper($document),
            fn () => $this->{$method}(...array_values($parameters)),
        );
    }

    /** কাগজের শাখা — নিজের ঘর থেকে; যে কাগজের শাখা অন্য কোথাও, সে নিজে বলে দেয় */
    protected function branchOfPaper(Model $document): ?int
    {
        $branch = $document->getAttribute('branch_id');

        return $branch === null ? null : (int) $branch;
    }
}

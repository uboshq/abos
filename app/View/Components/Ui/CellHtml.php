<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use Illuminate\Support\HtmlString;

/** তালিকার এক ঘরের আঁকা লেখা, আর সেটা খালি কি না ([[Table::cellHtml()]]) */
final class CellHtml extends HtmlString
{
    public function __construct(string $html, public readonly bool $blank)
    {
        parent::__construct($html);
    }
}

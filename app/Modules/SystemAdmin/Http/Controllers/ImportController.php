<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Contracts\WarnsOnPartialImport;
use App\Core\Services\ImportRunner;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * পুরনো খাতা থেকে আনা।
 *
 * প্রতিটা নতুন গ্রাহক আসে Tally, Excel বা হাতের খাতা নিয়ে। প্রথম দিনে
 * তিনশো গ্রাহক হাতে তুলতে বললে go-live ছয় মাস পিছিয়ে যায় — আর সেটা
 * ফিচারের অভাব নয়, ঠিক এই পর্দাটার অভাব।
 *
 * দুই ধাপ, ইচ্ছাকৃতভাবে: আগে দেখা, তারপর বসানো। একধাপে করলে তিনশো
 * সারির মধ্যে দুইটা ভুল থাকলে ব্যবহারকারী জানতেন না কোন দুইটা, আর
 * ফাইলটা চোখে খুঁজতে হত।
 *
 * কোন কোন জিনিস আনা যায় তা এখানে লেখা নেই — মডিউলগুলো নিজেরা ঘোষণা
 * করে (সেকশন ১৯.৭)।
 */
class ImportController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly ImportRunner $imports,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        // ইমপোর্ট মানে অনেক সারি একসাথে বসানো — রোজকার কাজের অনুমতি নয়
        return [new Middleware('can:system_admin.import.manage')];
    }

    public function index(Request $request): View
    {
        return view('system_admin::import.index', [
            'menu' => $this->menu->forUser($request->user()),
            'kinds' => $this->kinds(),
            'result' => session('import_result'),
            'checked' => session('import_checked'),
            'maxRows' => ImportRunner::MAX_ROWS,
        ]);
    }

    /**
     * নমুনা ফাইল।
     *
     * ব্যবহারকারীকে কলামের নাম মুখস্থ করতে বলা যায় না; নমুনাটা নামিয়ে
     * ভরে ফেরত দেওয়াই সবচেয়ে কম ভুলের পথ।
     */
    public function template(string $kind): StreamedResponse
    {
        abort_unless(isset($this->imports->available()[$kind]), 404);

        $csv = $this->imports->template($kind);

        return response()->streamDownload(
            fn () => print ($csv),
            "abos-{$kind}-template.csv",
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /** আগে দেখা — কিছু সেভ না করে। */
    public function check(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $result = $this->imports->check($data['kind'], $request->file('file'));

        return back()->with('import_checked', [
            'kind' => $data['kind'],
            ...$result,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $result = $this->imports->run($data['kind'], $request->file('file'));

        if ($result['imported'] === 0 && $result['failed'] === []) {
            return back()->withErrors(['file' => __('core.import.nothing_to_import')]);
        }

        /*
         * কিছু সারি ব্যর্থ হলে জোরালো সতর্কবার্তা।
         *
         * "৮ সফল, ২ ব্যর্থ" একটা পরিসংখ্যান, সতর্কবার্তা নয় — পণ্যের
         * তালিকায় ঠিক আছে, কিন্তু কিছু ইমপোর্ট একটা দলিল (খোলার জের),
         * আর অর্ধেক দলিল মানে নীরব ভুল। সাধারণ বার্তা সব ইমপোর্টে; যে
         * ইমপোর্টার নিজেকে দলিল বলে (WarnsOnPartialImport) তার নিজের কড়া
         * বার্তা তাকে ছাপিয়ে যায়।
         */
        $warning = null;

        if ($result['failed'] !== []) {
            $warning = __('core.import.partial_warning');

            $importer = app($this->imports->importerFor($data['kind']));

            if ($importer instanceof WarnsOnPartialImport
                && ($specific = $importer->partialWarning()) !== null) {
                $warning = $specific;
            }
        }

        return back()->with('import_result', [
            'kind' => $data['kind'],
            'warning' => $warning,
            ...$result,
        ]);
    }

    /**
     * @return array{kind: string}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', 'string', 'in:'.implode(',', array_keys($this->imports->available()))],
            /*
             * mimes-এ csv ও txt দুটোই।
             *
             * উইন্ডোজে Excel থেকে "CSV" সেভ করলে ফাইলটার MIME অনেক সময়
             * text/plain হয়ে আসে, আর শুধু csv লিখলে ব্যবহারকারীর নিজের
             * তৈরি ফাইলটাই প্রত্যাখ্যাত হত।
             */
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function kinds(): array
    {
        $kinds = [];

        foreach ($this->imports->available() as $key => $class) {
            $kinds[$key] = __($class::label());
        }

        return $kinds;
    }
}

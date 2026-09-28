{{--
    মার্জিনের সতর্কতা — দেয়াল "warn" পথে বিক্রি হতে দিয়েছে, কিন্তু কথাটা বলা দরকার।

    ⓘ বার্তাগুলো [[MarginGuard::assertMargin()]] সেশনে রাখে। ⛔ দামের নীতির
    সতর্কতা (`price_warnings`) সেশনে বসত ঠিকই, কিন্তু কোনো পর্দা সেটা আঁকত
    না — সতর্কতাটা লেখা হত আর কেউ দেখত না। ⭐ এই খণ্ডটা সেই ফাঁকটা এই
    দেয়ালের জন্য বন্ধ করে: বিলের পাতা আর কাউন্টারের পাতা দুইটাতেই বসে।

    ⚠️ খরচের চাবি ছাড়া মানুষের বার্তায় সংখ্যা থাকে না — সেটা দেয়ালই
    ঠিক করে, এখানে আবার ছাঁকা হয় না।

    ⓘ `$spacing` — যে পাতা বসায় সে-ই ফাঁক ঠিক করে (বিলের পাতায় `mb-4`,
    কাউন্টারের বোতামের নিচে `mt-2`), পাশের "সংরক্ষিত" বার্তার হুবহু।
--}}
@php($marginWarnings = (array) session(\App\Modules\Sales\Services\MarginGuard::FLASH, []))

@if ($marginWarnings !== [])
    <div role="status"
         class="{{ $spacing ?? '' }} rounded-(--radius-field) bg-(--color-badge-warning-bg) px-3 py-2 text-sm
                text-(--color-badge-warning-ink)">
        <p class="font-semibold">{{ __('sales::margin.warnings_title') }}</p>
        <ul class="list-inside list-disc">
            @foreach ($marginWarnings as $warning)
                <li>{{ $warning }}</li>
            @endforeach
        </ul>
    </div>
@endif

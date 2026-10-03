@props(['f', 'state' => null])

{{--
    ⓘ সংশোধনের ইতিহাসের একটা ঘর ([[x-ui.revisions]]) — নাম এক লাইনে, তার নিচে আগে আর পরে।
    ছোট পর্দায় একটার নিচে আরেকটা, চওড়া পর্দায় পাশাপাশি; বদলানো ঘর রঙে আলাদা।
    নতুন সারিতে কেবল "পরে", বাদ পড়া সারিতে কেবল "আগে" — অন্যটা থাকেই না।
--}}
<div data-revision-field="{{ $f['field'] }}" data-revision-changed="{{ $f['changed'] ? '1' : '0' }}"
     class="mt-1 rounded-(--radius-field) px-2 py-1 text-sm {{ $f['changed'] ? 'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)' : '' }}">
    <p class="text-2xs font-medium">{{ \App\Core\Support\RevisionDiff::label($f['field']) }}</p>
    <div class="grid gap-x-3 sm:grid-cols-2">
        @if ($state !== \App\Core\Support\RevisionDiff::ADDED)
            <p class="min-w-0 break-words">
                <span class="text-2xs opacity-75">{{ __('revision.before') }}:</span>
                <span data-revision-before>{{ \App\Core\Support\RevisionDiff::show($f['before']) }}</span>
            </p>
        @endif
        @if ($state !== \App\Core\Support\RevisionDiff::REMOVED)
            <p class="min-w-0 break-words">
                <span class="text-2xs opacity-75">{{ __('revision.after') }}:</span>
                <span data-revision-after class="{{ $f['changed'] ? 'font-semibold' : '' }}">{{ \App\Core\Support\RevisionDiff::show($f['after']) }}</span>
            </p>
        @endif
    </div>
</div>

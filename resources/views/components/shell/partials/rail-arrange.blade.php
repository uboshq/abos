{{-- ⓘ রেল সাজানোর পর্দা — sidebar.blade.php থেকে আলাদা (১০০০ লাইনের সীমা, ২৪ নভেম্বর ২০২৬); রেলের `sidebarFilter`-এর
     Alpine অবস্থা (arrangeRows, openArrange …) রেলের ভিতরেই, তাই @include-এ আচরণ একই। --}}
{{-- ⭐ রেল সাজানো — পিন (রেলে সবসময়), ক্রম টেনে বা ↑ ↓ বোতামে (পর্দা-পাঠকেও); রাখলে নিজের সারিতে, অন্য যন্ত্রেও এক --}}
<dialog x-ref="arrange" data-rail-arrange-dialog aria-labelledby="rail-arrange-title"
        class="m-auto w-full max-w-md rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-0">
    <div class="grid gap-3 p-4">
        <h2 id="rail-arrange-title" class="font-semibold">{{ __('core.rail.arrange_title') }}</h2>
        <p class="text-xs text-(--color-ink-muted)">{{ __('core.rail.arrange_hint') }}</p>
        <ol class="max-h-[60vh] overflow-y-auto" data-rail-arrange-list>
            <template x-for="(row, i) in arrangeRows" :key="row.code">
                <li class="flex items-center gap-2 rounded-(--radius-field) px-1 py-1 hover:bg-(--color-surface-hover)"
                    draggable="true" :data-code="row.code"
                    @dragstart="dragStart(i)" @dragover.prevent="dragOver(i)" @drop.prevent="dragDrop()">
                    <span class="cursor-grab text-(--color-ink-muted)" aria-hidden="true">⠿</span>
                    <span class="size-2.5 shrink-0 rounded-full" :style="row.dotStyle" aria-hidden="true"></span>
                    <span class="min-w-0 flex-1" x-text="row.label"></span>
                    <label class="flex items-center gap-1 text-xs">
                        <input type="checkbox" :checked="row.pinned" @change="togglePin(i)" data-rail-pin>
                        <span>{{ __('core.rail.pin') }}</span>
                    </label>
                    <button type="button" class="rounded px-1.5 text-sm hover:bg-(--color-surface-selected)"
                            :aria-label="moveUpLabel(row)" @click="moveUp(i)" data-rail-up>↑</button>
                    <button type="button" class="rounded px-1.5 text-sm hover:bg-(--color-surface-selected)"
                            :aria-label="moveDownLabel(row)" @click="moveDown(i)" data-rail-down>↓</button>
                </li>
            </template>
        </ol>
        <div class="flex justify-end gap-2">
            {{-- ⓘ সাধারণ বোতাম, x-ui.button নয় — ওর প্রধান রূপ `data-page-primary` বসায়, আর রেলের ঘর পাতার প্রধান কাজ নয় --}}
            <button type="button" x-on:click="closeArrange()" data-rail-cancel
                    class="inline-flex min-h-(--spacing-touch) items-center justify-center rounded-(--radius-field) border
                           border-(--color-border) bg-(--color-surface-card) px-4 text-sm font-medium hover:bg-(--color-surface-hover)">{{ __('core.rail.cancel') }}</button>
            <button type="button" x-on:click="saveArrange()" data-rail-save
                    class="inline-flex min-h-(--spacing-touch) items-center justify-center rounded-(--radius-field)
                           bg-(--color-brand-500) px-4 text-sm font-medium text-(--color-brand-ink) hover:bg-(--color-brand-600)">{{ __('core.rail.save') }}</button>
        </div>
    </div>
</dialog>

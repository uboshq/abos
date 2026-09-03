{{--
    পুরনো খাতা থেকে আনা।

    দুই ধাপ: আগে দেখা, তারপর বসানো। একধাপে করলে তিনশো সারির মধ্যে দুইটা
    ভুল থাকলে ব্যবহারকারী জানতেন না কোন দুইটা — "ইমপোর্ট ব্যর্থ" বলে
    থামলে ফাইলটা চোখে খুঁজতে হত।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('core.import.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('core.import.title')" :subtitle="__('core.import.note')" />
    </x-slot:header>

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- বসানোর ফল --}}
    @if ($result)
        {{-- আংশিক ইমপোর্টের জোরালো সতর্কবার্তা — সংখ্যার আগে, লাল।
             "৮ সফল, ২ ব্যর্থ" একটা পরিসংখ্যান; কিছু ইমপোর্ট (খোলার জের)
             একটা দলিল, আর অর্ধেক দলিল নীরব ভুল। ImportController বার্তাটা
             ঠিক করে দেয় — সাধারণ, নয়তো ইমপোর্টারের নিজের কড়া বার্তা। --}}
        @if (! empty($result['warning']))
            <div role="alert"
                 class="mb-4 rounded-(--radius-card) border-2 border-(--color-danger)
                        bg-(--color-surface-card) p-4">
                <p class="font-semibold text-(--color-danger)">
                    {{ $result['warning'] }}
                </p>
            </div>
        @endif

        <div role="status"
             class="mb-4 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">
            <p class="font-semibold text-(--color-badge-success-ink)">
                {{ trans_choice('core.import.imported', $result['imported'], ['count' => $result['imported']]) }}
            </p>

            @if ($result['failed'] !== [])
                {{-- ব্যর্থ সারিগুলো লুকানো হয় না: ব্যবহারকারী ওগুলো ঠিক
                     করে আবার পাঠাবেন, আর কোনটা কেন ব্যর্থ তা না জানলে
                     পুরো ফাইলটা আবার দেখতে হত। --}}
                <p class="mt-2 text-sm text-(--color-danger)">
                    {{ trans_choice('core.import.bad_rows', count($result['failed']), ['count' => count($result['failed'])]) }}
                </p>

                <ul class="mt-2 space-y-1 text-2xs text-(--color-ink-muted)">
                    @foreach ($result['failed'] as $failure)
                        <li>
                            <span class="num font-medium">{{ __('core.import.line') }} {{ $failure['line'] }}</span>
                            — {{ $failure['error'] }}
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <form method="POST" enctype="multipart/form-data"
                  action="{{ route('system_admin.import.check') }}"
                  x-data="{ kind: '{{ $checked['kind'] ?? array_key_first($kinds) }}' }"
                  class="space-y-3">
                @csrf

                <x-ui.select name="kind" :label="__('core.import.what')"
                             :options="$kinds"
                             x-model="kind"
                             :selected="$checked['kind'] ?? null" required />

                {{-- নমুনা ফাইলের লিংক বাছাইয়ের সাথে বদলায়, নাহলে
                     ব্যবহারকারী গ্রাহক বেছে সরবরাহকারীর নমুনা নামাতেন --}}
                <p>
                    <a :href="'{{ route('system_admin.import.template', ['kind' => 'KIND']) }}'.replace('KIND', kind)"
                       class="text-sm text-(--color-brand-500) underline-offset-2 hover:underline">
                        ↓ {{ __('core.import.template') }}
                    </a>
                </p>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">{{ __('core.import.file') }}</span>
                    <input type="file" name="file" accept=".csv,text/csv,text/plain" required
                           class="w-full rounded-(--radius-field) border border-(--color-border)
                                  bg-(--color-surface-card) p-2 text-sm">
                </label>

                <div class="flex flex-wrap gap-2">
                    <x-ui.button type="submit" tone="secondary">
                        {{ __('core.import.check') }}
                    </x-ui.button>

                    {{-- বসানোর বোতামটা একই ফর্মে, শুধু action আলাদা।

                         আলাদা ফর্ম করলে ফাইলটা দুইবার বাছতে হত: ব্রাউজার
                         নিরাপত্তার কারণে একটা file input-এর মান আরেকটায়
                         কপি করতে দেয় না। --}}
                    <x-ui.button type="submit" tone="primary"
                                 formaction="{{ route('system_admin.import.store') }}">
                        {{ __('core.import.commit') }}
                    </x-ui.button>
                </div>
            </form>
        </section>

        {{-- যাচাইয়ের ফল --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) lg:col-span-2">
            @if (! $checked)
                <x-ui.empty-state :message="__('core.import.note')" />
            @else
                <div class="flex flex-wrap items-center gap-3 border-b border-(--color-border) px-4 py-3">
                    <span class="font-semibold text-(--color-badge-success-ink)">
                        {{ trans_choice('core.import.ok_rows', $checked['ok'], ['count' => $checked['ok']]) }}
                    </span>

                    @if ($checked['bad'] > 0)
                        <span class="text-(--color-danger)">
                            {{ trans_choice('core.import.bad_rows', $checked['bad'], ['count' => $checked['bad']]) }}
                        </span>
                    @endif
                </div>

                @if ($checked['truncated'])
                    {{-- সীমা ছাড়ালে চুপচাপ কাটা হয় না — কাটলে ব্যবহারকারী
                         ভাবতেন পুরো ফাইলটাই ঢুকেছে --}}
                    <p class="border-b border-(--color-border) bg-(--color-badge-pending-bg) px-4 py-2
                              text-sm text-(--color-badge-pending-ink)">
                        {{ __('core.import.truncated', ['max' => $maxRows]) }}
                    </p>
                @endif

                <x-ui.table
                    :empty="__('core.import.empty_file')"
                    :rows="$checked['rows']"
                    :columns="[
                        ['key' => 'line', 'label' => __('core.import.line'), 'numeric' => true, 'width' => '5rem',
                         'render' => fn ($r) => $r['line']],
                        ['key' => 'name', 'label' => __('supplier::field.name'),
                         'render' => fn ($r) => $r['data']['name_en'] ?? ''],
                        ['key' => 'errors', 'label' => __('core.import.problem'),
                         'render' => fn ($r) => view('system_admin::import.partials.row-state', ['row' => $r])],
                    ]" />
            @endif
        </section>
    </div>
</x-layouts.app>

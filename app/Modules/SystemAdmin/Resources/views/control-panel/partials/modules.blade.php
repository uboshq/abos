{{--
    মডিউলের ছক — প্রথম ট্যাব।

    ── কেন ছক, কার্ড নয়, ৩০ আগস্ট ২০২৬ ────────────────────────────────
    মালিকের কথা: *"এগুলো একটা টেবিলে রাখ।"*

    প্রথমে প্রতিটা মডিউল একটা করে কার্ড ছিল, আর বারোটা কার্ডে পাতাটা
    দাঁড়াল ১,৩৫০ পিক্সেল — অথচ তথ্য মোটে বারো লাইনের। ছকে সুইচগুলো
    **একই খাড়া রেখায়** থাকে, তাই কে চালু আর কে বন্ধ সেটা এক নজরে
    পড়া যায়; কার্ডে প্রতিটা সুইচ আলাদা জায়গায় বলে চোখকে বারোবার
    খুঁজতে হত।

    ── কেন গুনতি দুইটা ────────────────────────────────────────────────
    "হিসাব" বন্ধ করলে ঠিক কতটা বন্ধ হচ্ছে — চারটা ভাগ, তেত্রিশটা
    পর্দা — সেটা না জানিয়ে সুইচটা দিলে সিদ্ধান্তটা অন্ধ হত।
--}}
<div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card)">
    <div class="table-responsive">
        {{-- `ui-list` — তিনটা নামের একটা।

             ── কেন নামটা লাগে (৭ নম্বর ঝুঁকি) ──────────────────────────
             ছকের মাপ আর ধার আসে থিম থেকে। ঘরে হাতে `px-4 py-2` লিখলে
             Tailwind-এর utility স্তর থিমের নিয়মকে হারায়, আর মাপটা
             পাথরে খোদাই হয়ে যায় — রং বদলায়, ঘনত্ব বদলায় না। থিম
             বদলালে এই একটা পর্দা তখন আগের চেহারায় বসে থাকত।

             এই ছকটা প্রথমবার নাম ছাড়াই লেখা হয়েছিল — শুধু চওড়া আর
             ছোট লেখা, ঘরে হাতে বসানো প্যাডিং। `EveryScreenObeysTheThemeTest`
             সেটা ধরে ফেলল, আর ধরাটাই ঠিক ছিল। --}}
        <table class="ui-list table-cards w-full border-collapse">
            <thead>
                <tr class="border-b border-(--color-border)">
                    <th class="w-10"><span class="sr-only">{{ __('system_admin::control.column_on') }}</span></th>
                    <th class="text-start">{{ __('system_admin::control.column_module') }}</th>
                    <th class="num">{{ __('system_admin::control.column_groups') }}</th>
                    <th class="num">{{ __('system_admin::control.column_screens') }}</th>
                    <th class="text-end">
                        <span class="sr-only">{{ __('system_admin::control.open_module_menu') }}</span>
                    </th>
                </tr>
            </thead>

            <tbody>
                @foreach ($tree as $module)
                    @php $moduleOn = $settings->get($module['key'], true); @endphp

                    <tr class="hover:bg-(--color-surface-hover)">
                        <td data-label="{{ __('system_admin::control.column_on') }}">
                            <input type="checkbox" name="settings[{{ $module['key'] }}]" value="1"
                                   @checked($moduleOn) data-was="{{ $moduleOn ? '1' : '' }}"
                                   x-model="on['{{ $module['key'] }}']"
                                   aria-label="{{ $module['label'] }}"
                                   class="size-4">
                            <input type="hidden" name="scope[]" value="{{ $module['key'] }}">
                        </td>

                        <td data-label="{{ __('system_admin::control.column_module') }}">
                            <span class="font-medium">{{ $module['label'] }}</span>

                            {{-- বন্ধ মডিউলের সারিটা যেন চোখে পড়ে: সুইচটা
                                 ছোট, আর বারো সারির ছকে একটা খালি ঘর
                                 সহজেই মিস হয়। --}}
                            <span x-cloak x-show="! on['{{ $module['key'] }}']"
                                  class="ms-2 rounded-(--radius-field) bg-(--color-badge-draft-bg) px-2 py-0.5
                                         text-2xs text-(--color-badge-draft-ink)">
                                {{ __('system_admin::control.module_off') }}
                            </span>
                        </td>

                        <td class="num text-(--color-ink-muted)"
                            data-label="{{ __('system_admin::control.column_groups') }}">
                            {{ $module['group_count'] }}
                        </td>

                        <td class="num text-(--color-ink-muted)"
                            data-label="{{ __('system_admin::control.column_screens') }}">
                            {{ $module['row_count'] }}
                        </td>

                        <td class="text-end">
                            <a href="{{ route('system_admin.control-panel', ['tab' => $module['code']]) }}"
                               class="text-(--color-link) hover:underline">
                                {{ __('system_admin::control.open_module_menu') }}
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

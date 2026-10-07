{{--
    ⭐ কম্বো ও বান্ডলের উপাদান — "কোন পণ্য, প্রতি সেটে অন্তত কতটা"।

    ⓘ show.blade.php এটা @include করে কেবল যখন অফারের ধরন কম্বো বা বান্ডল।
    ⚠️ তবু এখানেও প্রশ্নটা আবার করা হয় — অন্য কোনো পাতা ভুল করে include
    করলে সাধারণ অফারে পণ্যের তালিকা বসার ঘর দেখাত, আর সেবা প্রতিবার ভুল দিত।

    ⛔ যোগ-সরানোর ঘর কেবল খসড়ায়: অনুমোদনকারী "ক + খ + গ"-এ সই দিয়েছিলেন;
    পরে একটা সরানো গেলে অফারটা সহজে খুলত, অথচ সইটা থেকেই যেত। সেবাও থামায়
    ([[ComboRules]]) — বোতাম লুকানো একমাত্র পাহারা নয়।

    ⚠️ কোনো @php ব্লক নেই, আর কম্পোনেন্টের গুণে ডাবল-কোট নেই — দুইটাই এই
    মডিউলে আগে পাতা ভেঙেছে।
--}}
@if (\App\Modules\Promotion\Services\BillPromotionEngine::isBillType($offer->type))
    <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <h2 class="mb-1 font-semibold">{{ __('promotion::combo.title') }}</h2>
        <p class="mb-3 text-xs text-(--color-ink-muted)">{{ __('promotion::combo_screen.hint') }}</p>

        {{-- ⓘ সম্পর্ক ছাড়াই সরাসরি প্রশ্ন — Promotion মডেলে comboItems() জোড়া না হলেও পাতাটা চলে --}}
        @forelse (\App\Modules\Promotion\Models\ComboItem::query()->with('product')->where('promotion_id', $offer->id)->orderBy('id')->get() as $item)
            <div class="flex items-center justify-between border-b border-(--color-border) py-2 text-sm">
                <span>
                    {{ $item->product?->name() ?? '#'.$item->product_id }}
                    <span class="text-(--color-ink-muted)">· {{ __('promotion::combo.min_qty') }}</span>
                    <span class="tabular-nums">{{ rtrim(rtrim((string) $item->min_qty, '0'), '.') }}</span>
                </span>

                @if ($offer->status === \App\Modules\Promotion\Support\PromotionStatus::DRAFT)
                    @can('promotion.update')
                        <form method="POST" action="{{ route('promotion.combo.destroy', [$offer, $item]) }}">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" tone="danger">{{ __('promotion::combo.remove_item') }}</x-ui.button>
                        </form>
                    @endcan
                @endif
            </div>
        @empty
            {{-- ⚠️ উপাদানহীন কম্বো কখনো খোলে না — সেটা স্পষ্ট বলা, আর "অন্তত দুইটা" মনে করানো --}}
            <p class="text-sm text-(--color-ink-muted)">{{ __('promotion::combo.empty') }}</p>
        @endforelse

        @if ($offer->status === \App\Modules\Promotion\Support\PromotionStatus::DRAFT)
            @can('promotion.update')
                <form method="POST" action="{{ route('promotion.combo.store', $offer) }}" class="mt-4 grid gap-3 sm:grid-cols-3">
                    @csrf
                    <x-ui.field name="product_id" type="number" :label="__('promotion::combo_screen.product_id')" required />
                    <x-ui.field name="min_qty" type="number" step="0.0001" min="0.0001" :label="__('promotion::combo.min_qty')" required />
                    <div class="flex items-end">
                        <x-ui.button type="submit" tone="secondary">{{ __('promotion::combo.add_item') }}</x-ui.button>
                    </div>
                </form>
            @endcan
        @else
            <p class="mt-3 text-xs text-(--color-ink-muted)">{{ __('promotion::combo_screen.frozen') }}</p>
        @endif
    </section>
@endif

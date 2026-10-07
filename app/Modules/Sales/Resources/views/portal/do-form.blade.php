{{--
    গ্রাহকের নতুন DO — পণ্য আর পরিমাণ, এক লাইনে এক পণ্য (মালিকের নিয়ম)। ⛔ দামের ঘর নেই — দাম পণ্যের।
    ⓘ "খসড়া রাখুন" বা "জমা দিন"; জমার পরে আর বদলানো যায় না।
--}}
<x-sales::portal.layout :customer="$customer">
    <h1 class="mb-3 text-lg font-semibold">{{ __('sales::delivery_order.new') }}</h1>

    <x-ui.errors />

    <form method="POST" action="{{ route('sales.portal.do.store') }}" class="grid gap-3" data-portal-do-form>
        @csrf
        @for ($i = 0; $i < 8; $i++)
            <div class="grid gap-1 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3">
                <select name="lines[{{ $i }}][product_id]"
                        class="h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-3">
                    <option value="">{{ __('sales::delivery_order.pick_product') }}</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" @selected((string) old("lines.$i.product_id") === (string) $product->id)>
                            {{ $product->name() }} — {{ \App\Core\Support\Money::format($product->sale_price) }}
                        </option>
                    @endforeach
                </select>
                <input type="number" step="1" min="0" name="lines[{{ $i }}][qty]" value="{{ old("lines.$i.qty") }}"
                       placeholder="{{ __('sales::delivery_order.qty') }}"
                       class="num h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-3 text-end">
            </div>
        @endfor

        <input type="text" name="narration" value="{{ old('narration') }}" maxlength="500"
               placeholder="{{ __('sales::portal.note') }}"
               class="h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-3">

        <button type="submit" name="submit" value="1"
                class="h-(--spacing-field) rounded-(--radius-field) bg-(--color-brand-500) font-medium text-white">
            {{ __('sales::delivery_order.submit') }}
        </button>
        <button type="submit"
                class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) font-medium">
            {{ __('sales::delivery_order.keep_draft') }}
        </button>
    </form>
</x-sales::portal.layout>

{{--
    ⭐ মজুদের ড্যাশবোর্ডের ছাঁকনির সারি — নমুনার হুবহু (HmzeE5CN2wvtyLPE5exjvV): দিন, গুদাম, ব্র্যান্ড, শ্রেণি, ABC; ডানে হালনাগাদের সময়,
    PDF · Excel আর "+ মাল নিন / বদলি / গণনা ▾"। ⓘ প্রতিটা ছাঁকনি একটা ছোট মেনু, ভিতরে সাধারণ লিংক (?warehouse=…) — জাভাস্ক্রিপ্ট লাগে না।
--}}
@php
    $btn = 'height: 2.25rem; padding: 0 0.75rem; border: 1px solid var(--color-dash-d6dbe7); background: var(--color-dash-ffffff); border-radius: 0.625rem; font: inherit; display: inline-flex; align-items: center; gap: 0.375rem; cursor: pointer; color: var(--color-dash-111827); list-style: none';
    $menu = 'position: absolute; z-index: 30; top: 2.5rem; left: 0; min-width: 12rem; max-height: 20rem; overflow-y: auto; background: var(--color-dash-ffffff); border: 1px solid var(--color-dash-e5e7f0); border-radius: 0.75rem; box-shadow: 0 0.75rem 1.75rem var(--color-dash-17-24-39-a06); padding: 0.375rem; display: flex; flex-direction: column';
    $item = 'padding: 0.4375rem 0.625rem; border-radius: 0.5rem; color: var(--color-dash-1f2937); font-size: 0.8125rem; text-decoration: none';
@endphp
<div data-inv-toolbar style="width: 100%; height: 2.75rem; display: flex; align-items: center; gap: 0.625rem; font-size: 0.8125rem">
    <span style="{{ $btn }} font-weight: 600">{{ $bar['today'] }}</span>
    @foreach ($bar['filters'] as $f)
        <details data-inv-filter="{{ $f['key'] }}" style="position: relative">
            <summary style="{{ $btn }} {{ $f['active'] ? 'border-color: var(--color-dash-6366f1); color: var(--color-dash-4f46e5); font-weight: 700' : '' }}">{{ $f['label'] }} ▾</summary>
            <div style="{{ $menu }}">
                @foreach ($f['options'] as $o)
                    <a href="{{ $o['href'] }}" style="{{ $item }} {{ $o['on'] ? 'background: var(--color-dash-eef2ff); font-weight: 700' : '' }}">{{ $o['label'] }}</a>
                @endforeach
            </div>
        </details>
    @endforeach
    <span style="margin-left: auto; color: var(--color-dash-6b7280)">{{ $bar['updated'] }}</span>
    @if ($bar['export'])
        <a href="{{ $bar['export'] }}" style="{{ $btn }} text-decoration: none">PDF · Excel</a>
    @endif
    @if ($bar['create'] !== [])
        <details data-inv-create style="position: relative">
            <summary style="{{ $btn }} border: none; background: linear-gradient(135deg, var(--color-dash-0ea5e9), var(--color-dash-6366f1)); color: var(--color-dash-ffffff); font-weight: 700">{{ __('inventory::board.t.create') }}</summary>
            <div style="{{ $menu }} left: auto; right: 0">
                @foreach ($bar['create'] as $o)
                    <a href="{{ $o['href'] }}" style="{{ $item }}">{{ $o['label'] }}</a>
                @endforeach
            </div>
        </details>
    @endif
</div>

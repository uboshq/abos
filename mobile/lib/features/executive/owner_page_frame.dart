import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../books/books_widgets.dart';
import 'owner_pages_api.dart';

/// ⭐ মালিকের কেন্দ্রের পাতার ছাঁচ — আনা, টেনে নবায়ন, বাংলায় ভুল; উপরের বাছাইগুলো (সময়, সংখ্যা, কোম্পানি) আনার সময়ও থাকে।
///
/// <p>[reloadKey] বদলালে (বাছাই বদল) নতুন করে আনে — "আজ" পাতার চিপের একই আচরণ ([ExecutiveScreen])। ⓘ আগের উত্তর ততক্ষণ
/// পর্দায় থাকে, উপরে কেবল চলার দাগ — পাতাটা ফাঁকা হয়ে লাফায় না।
class OwnerPageFrame<T> extends StatefulWidget {
  const OwnerPageFrame({
    super.key,
    required this.title,
    required this.load,
    required this.body,
    required this.failed,
    this.controls = const [],
    this.reloadKey,
  });

  final String title;
  final Future<T> Function() load;
  final List<Widget> Function(BuildContext context, T data) body;
  final String failed;
  final List<Widget> controls;
  final Object? reloadKey;

  @override
  State<OwnerPageFrame<T>> createState() => _OwnerPageFrameState<T>();
}

class _OwnerPageFrameState<T> extends State<OwnerPageFrame<T>> {
  T? _data;
  bool _busy = false;
  String? _error;

  /// ⚠️ পুরনো উত্তর নতুনটার পরে এলে পর্দা পুরনো বাছাইয়ের সংখ্যা দেখাত — কেবল শেষ ডাকের উত্তর বসে
  int _asked = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant OwnerPageFrame<T> old) {
    super.didUpdateWidget(old);
    if (old.reloadKey != widget.reloadKey) _load();
  }

  Future<void> _load() async {
    final ask = ++_asked;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final data = await widget.load();
      if (!mounted || ask != _asked) return;
      setState(() => _data = data);
    } catch (e) {
      if (mounted && ask == _asked) {
        setState(() => _error = errorMessageFor(e,
            fallback: widget.failed,
            whenAbsent: 'মালিকের কেন্দ্রের এই পাতা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন। অফিসে জানান।'));
      }
    } finally {
      if (mounted && ask == _asked) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final data = _data;
    return Scaffold(
      appBar: AbosScreenBar(title: widget.title),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            ...widget.controls,
            if (_busy) const LinearProgressIndicator(),
            if (_error != null) BooksError(_error!),
            if (data != null) ...widget.body(context, data),
          ],
        ),
      ),
    );
  }
}

/// চিপের সারি — একটা বাছা ("আজ" পাতার সময়ের চিপের একই রূপ); [prefix] দিয়ে চাবি আলাদা, এক পাতায় কয়েক সারি থাকে
class OwnerChips extends StatelessWidget {
  const OwnerChips({super.key, required this.prefix, required this.choices, required this.selected, required this.onSelected});

  final String prefix;
  final Map<String, String> choices;
  final String? selected;
  final ValueChanged<String> onSelected;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: AppSpacing.xs),
        child: Wrap(
          spacing: AppSpacing.xs,
          runSpacing: AppSpacing.xs,
          children: [
            for (final e in choices.entries)
              ChoiceChip(
                key: ValueKey('$prefix-${e.key}'),
                label: Text(e.value),
                selected: selected == e.key,
                onSelected: (_) {
                  if (selected != e.key) onSelected(e.key);
                },
              ),
          ],
        ),
      );
}

/// মোটের পট্টি — [TotalStrip]-এর রূপ, নিজের চাবিতে (এক পাতায় একাধিক বসে)
class OwnerTotal extends StatelessWidget {
  const OwnerTotal({super.key, required this.label, required this.value, this.tail});

  final String label;
  final String value;
  final String? tail;

  @override
  Widget build(BuildContext context) => Card(
        color: context.palette.successSurface,
        margin: const EdgeInsets.only(bottom: AppSpacing.xs),
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Row(
            children: [
              Expanded(child: Text(label, style: const TextStyle(fontWeight: FontWeight.w700))),
              Text(value, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
              if (tail != null)
                Padding(
                  padding: const EdgeInsets.only(left: AppSpacing.sm),
                  child: Text(tail!, style: TextStyle(color: context.palette.onSurfaceMuted)),
                ),
            ],
          ),
        ),
      );
}

/// কার্ডের মাথার ছোট শিরোনাম — "আজ" পাতার শিরোনামের একই রূপ
class SectionHeading extends StatelessWidget {
  const SectionHeading(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(4, AppSpacing.md, 4, AppSpacing.xs),
        child: Text(text, style: TextStyle(fontWeight: FontWeight.w700, color: context.palette.onSurfaceMuted)),
      );
}

/// ছোট ধূসর কথা — "কেন", "কোথা থেকে"
class OwnerNote extends StatelessWidget {
  const OwnerNote(this.text, {super.key, this.colour});

  final String text;
  final Color? colour;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs, horizontal: 4),
        child: Text(text, style: TextStyle(fontSize: 12, color: colour ?? context.palette.onSurfaceMuted)),
      );
}

/// ঘরের লেখা — ঢাকা "••••", না থাকা "—", টাকা ৳ লাখ-কোটির ভাগে, গোনা সংখ্যা ভাগসহ
String ownerCell(String? value, {bool money = true}) {
  if (value == null) return '—';
  if (value == ownerHidden) return '••••';
  return money ? Money.taka(value) : Money.plain(value);
}

/// বদল % — "▲ ১২.৫০%" / "▼ ৩.০০%"; না থাকলে null
String? ownerChange(String? change) {
  final v = Money.value(change);
  if (v == null) return null;
  return '${v >= 0 ? '▲' : '▼'} ${Money.amount(v.abs())}%';
}

/// ঋণাত্মক সংখ্যা লাল — লোকসান, কম মার্জিন
Color? ownerTone(BuildContext context, String? value) {
  final v = Money.value(value);
  return v != null && v < 0 ? context.palette.danger : null;
}

/// দুই তারিখ, ওয়েবের রূপে — "01/10/2026 – 19/10/2026"
String ownerRange(String from, String to) => from == to ? dayOf(from) : '${dayOf(from)} – ${dayOf(to)}';

/// সরল দাগের চার্ট — চার্টের কোনো লাইব্রেরি নেই ([ModuleDashboardScreen]-এর একই ধারা): সংখ্যা সার্ভারের, দাগ কেবল আকার দেখায়
class OwnerBars extends StatelessWidget {
  const OwnerBars({super.key, required this.rows, required this.firstLabel, this.secondLabel, this.money = true});

  final List<({String label, String first, String? second})> rows;
  final String firstLabel;
  final String? secondLabel;
  final bool money;

  @override
  Widget build(BuildContext context) {
    final values = [
      for (final r in rows) ...[Money.valueOrZero(r.first).abs(), if (r.second != null) Money.valueOrZero(r.second).abs()],
    ];
    final max = values.fold<double>(0, (m, v) => v > m ? v : m);

    Widget bar(String? value, Color colour) => Padding(
          padding: const EdgeInsets.only(top: 2),
          child: Row(children: [
            Expanded(
              child: FractionallySizedBox(
                alignment: Alignment.centerLeft,
                widthFactor: max == 0 ? 0 : (Money.valueOrZero(value).abs() / max).clamp(0.0, 1.0),
                child: Container(height: 10, color: colour),
              ),
            ),
            const SizedBox(width: AppSpacing.sm),
            Text(ownerCell(value, money: money), style: const TextStyle(fontSize: 12)),
          ]),
        );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(secondLabel == null ? '■ $firstLabel' : '■ $firstLabel   ■ $secondLabel', style: Theme.of(context).textTheme.bodySmall),
        const SizedBox(height: AppSpacing.xs),
        for (final r in rows) ...[
          Text(r.label, style: const TextStyle(fontSize: 12.5)),
          bar(r.first, AppColors.primary),
          if (secondLabel != null) bar(r.second, context.palette.warning),
          const SizedBox(height: AppSpacing.xs),
        ],
      ],
    );
  }
}

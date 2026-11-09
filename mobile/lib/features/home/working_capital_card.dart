import 'package:flutter/material.dart';

import '../../core/locale/l10n.dart';
import '../../core/records/money.dart';
import '../../core/records/working_capital_record.dart';
import '../books/books_widgets.dart' show dayOf;
import '../web_inside/web_inside_screen.dart';

/// ⭐ চলতি মূলধনের কার্ড — হোমে টাকার কার্ডের ঠিক নিচে (মালিক, ৯ নভেম্বর ২০২৬: নমুনা TWYacgufwfzT9ZqR1eqvaW-এর ফোনের পর্দা;
/// "mobile app e za ekhon zemon ache tai thakbe sudu working capital er card ti bosabe")। ওয়েবের অর্থের ড্যাশবোর্ডের একই অঙ্ক।
///
/// তারিখ (আজ · গত মাসের শেষ · গত বছরের শেষ · ক্যালেন্ডার) আর গড় (৬ · ১২ মাস) বদলালে সার্ভার থেকে নতুন অঙ্ক।
/// ⓘ রং নিজের — গাঢ় নীল-বেগুনি জমিন, সোনালি মাথা — আলো আর অন্ধকার দুই থিমেই একই (জমিনও নিজের, তাই লেখা পড়া যায়)।
class WorkingCapitalCard extends StatefulWidget {
  const WorkingCapitalCard({super.key, required this.initial, this.api = const ServerWorkingCapitalApi(), this.today, this.onOpenReport});

  final WorkingCapitalRecord initial;
  final WorkingCapitalApi api;

  /// পরীক্ষার "আজ" — না দিলে ফোনের
  final DateTime? today;

  /// "পুরো রিপোর্ট" — না দিলে অ্যাপের ভিতরে ওয়েবের অর্থের ড্যাশবোর্ড
  final VoidCallback? onOpenReport;

  @override
  State<WorkingCapitalCard> createState() => _WorkingCapitalCardState();
}

const _ink = Color(0xFFFFFFFF);
const _soft = Color(0xFFC7D2FE);
const _gold = Color(0xFFF5C76B);
const _deep = Color(0xFF1E1B4B);
const _yellow = Color(0xFFFDE047);
const _good = Color(0xFF86EFAC);
const _chart = Color(0xFFFFFFFF);
const _chartInk = Color(0xFF0F172A);
const _chartSoft = Color(0xFF334155);
const _parts = [Color(0xFFFF6B81), Color(0xFF4ADE80), Color(0xFF60A5FA), Color(0xFFF472B6), Color(0xFFFDBA74), Color(0xFF0B2FB8)];

class _WorkingCapitalCardState extends State<WorkingCapitalCard> {
  late WorkingCapitalRecord _wc = widget.initial;
  bool _busy = false;
  bool _failed = false;

  DateTime get _now => widget.today ?? DateTime.now();
  String _iso(DateTime d) => '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  Future<void> _load({String? date, int? span}) async {
    setState(() {
      _busy = true;
      _failed = false;
    });
    try {
      final next = await widget.api.load(date: date ?? _wc.date, span: span ?? _wc.span);
      if (!mounted) return;
      setState(() => _wc = next ?? _wc);
    } catch (_) {
      if (mounted) setState(() => _failed = true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _pickDate() async {
    final now = _now;
    final current = DateTime.tryParse(_wc.date) ?? now;
    final picked = await showDatePicker(
      context: context,
      initialDate: current.isAfter(now) ? now : current,
      firstDate: DateTime(now.year - 10),
      lastDate: now,
      helpText: context.l10n.wcPickDate,
    );
    if (picked != null) await _load(date: _iso(picked));
  }

  void _openReport() {
    final open = widget.onOpenReport;
    if (open != null) return open();
    Navigator.of(context).push(MaterialPageRoute<void>(
      builder: (_) => WebInsideScreen(webPath: '/dashboard/finance?wc_date=${_wc.date}&wc_span=${_wc.span}', title: context.l10n.wcTitle),
    ));
  }

  String _lakh(double v) => (v / 100000).toStringAsFixed(1);

  @override
  Widget build(BuildContext context) {
    final l = context.l10n;
    final now = _now;
    final presets = [
      ('today', l.wcToday, _iso(now)),
      ('lastMonth', l.wcLastMonthEnd, _iso(DateTime(now.year, now.month, 0))),
      ('lastYear', l.wcLastYearEnd, _iso(DateTime(now.year, 1, 0))),
    ];
    final change = _wc.changePct;
    final ratio = _wc.ratio;
    final (ratioText, ratioColor) = ratio == null || ratio >= 1.5
        ? (l.wcRatioGood, _good)
        : ratio >= 1
            ? (l.wcRatioWatch, const Color(0xFFFDE68A))
            : (l.wcRatioBad, const Color(0xFFFCA5A5));

    Widget chip(String key, String label, bool on, VoidCallback onTap, {Color line = _gold, Color fill = _gold, Color onInk = _deep}) => OutlinedButton(
          key: ValueKey(key),
          onPressed: _busy ? null : onTap,
          style: OutlinedButton.styleFrom(
            minimumSize: const Size(48, 36),
            tapTargetSize: MaterialTapTargetSize.padded,
            padding: const EdgeInsets.symmetric(horizontal: 10),
            visualDensity: const VisualDensity(horizontal: -2, vertical: 0),
            side: BorderSide(color: on ? line : const Color(0x55FFFFFF)),
            backgroundColor: on ? fill : const Color(0x00000000),
            foregroundColor: on ? onInk : const Color(0xFFE0E7FF),
            shape: const StadiumBorder(),
          ),
          child: Text(label, style: TextStyle(fontSize: 12.5, fontWeight: on ? FontWeight.w700 : FontWeight.w500)),
        );

    final rows = [
      (l.wcReceivable, '+', _wc.receivable),
      (l.wcStock, '+', _wc.stock),
      (l.wcMoney, '+', _wc.money),
      (l.wcPayable, '−', _wc.payable),
      (l.wcOther, '−', _wc.other),
    ];

    return Container(
      key: const ValueKey('wc-card'),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: const Color(0x73F5C76B)),
        gradient: const LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [Color(0xFF0B1437), Color(0xFF1E1B4B), Color(0xFF2E1065)]),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          Expanded(child: Text(l.wcTitle, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700, color: _gold))),
          OutlinedButton(
            key: const ValueKey('wc-date'),
            onPressed: _busy ? null : _pickDate,
            style: OutlinedButton.styleFrom(
              minimumSize: const Size(48, 40),
              tapTargetSize: MaterialTapTargetSize.padded,
              backgroundColor: const Color(0xFFFEF3C7),
              foregroundColor: _deep,
              side: const BorderSide(color: _gold),
            ),
            child: Text('${dayOf(_wc.date)} ▾', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
          ),
        ]),
        const SizedBox(height: 6),
        Wrap(spacing: 6, children: [
          for (final (key, label, iso) in presets) chip('wc-preset-$key', label, _wc.date == iso, () => _load(date: iso)),
        ]),
        const SizedBox(height: 8),
        if (_busy) const LinearProgressIndicator(minHeight: 2, color: _gold, backgroundColor: Color(0x22FFFFFF)),
        if (_failed) Text(l.wcFailed, style: const TextStyle(color: Color(0xFFFCA5A5), fontSize: 12.5)),
        Wrap(crossAxisAlignment: WrapCrossAlignment.center, spacing: 8, runSpacing: 4, children: [
          Text(Money.taka(_wc.capital), key: const ValueKey('wc-capital'), style: const TextStyle(fontSize: 28, fontWeight: FontWeight.w700, color: _ink)),
          if (change != null)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
              decoration: BoxDecoration(color: const Color(0x3822C55E), borderRadius: BorderRadius.circular(999)),
              child: Text(change >= 0 ? l.wcUp(change.abs().toStringAsFixed(1)) : l.wcDown(change.abs().toStringAsFixed(1)),
                  style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: change >= 0 ? _good : const Color(0xFFFCA5A5))),
            ),
        ]),
        const SizedBox(height: 10),
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Expanded(
            child: Container(
              padding: const EdgeInsets.all(10),
              decoration:
                  BoxDecoration(color: const Color(0x1AFFFFFF), borderRadius: BorderRadius.circular(12), border: Border.all(color: const Color(0x2EFFFFFF))),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(l.wcRatio, style: const TextStyle(fontSize: 12, color: _soft)),
                Text(ratio == null ? '—' : ratio.toStringAsFixed(2),
                    key: const ValueKey('wc-ratio'), style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w700, color: _ink)),
                Text(ratioText, style: TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: ratioColor)),
              ]),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Container(
              key: const ValueKey('wc-sales'),
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: const Color(0xFFC4B5FD)),
                gradient: const LinearGradient(colors: [Color(0xFF7C3AED), Color(0xFF4C1D95)]),
              ),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(l.wcSalesShare, style: const TextStyle(fontSize: 12, color: Color(0xFFEDE9FE))),
                Text(_wc.salesPct == null ? '—' : '${_wc.salesPct}%',
                    key: const ValueKey('wc-sales-pct'), style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w700, color: _yellow)),
                Wrap(spacing: 4, children: [
                  for (final n in const [6, 12])
                    chip('wc-span-$n', l.wcMonths('$n'), _wc.span == n, () => _load(span: n), line: _yellow, fill: _yellow, onInk: const Color(0xFF3B0764)),
                ]),
              ]),
            ),
          ),
        ]),
        const SizedBox(height: 10),
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 2),
          decoration: BoxDecoration(color: const Color(0x10FFFFFF), borderRadius: BorderRadius.circular(12)),
          child: Column(children: [
            for (final (i, (name, sign, value)) in rows.indexed)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 6),
                child: Row(children: [
                  Container(width: 9, height: 9, decoration: BoxDecoration(color: _parts[i], borderRadius: BorderRadius.circular(3))),
                  const SizedBox(width: 8),
                  Expanded(child: Text(name, style: const TextStyle(fontSize: 13.5, color: _ink))),
                  Text('$sign ${Money.taka(value)}',
                      key: ValueKey('wc-part-$i'),
                      style: TextStyle(fontSize: 13.5, fontWeight: FontWeight.w700, color: sign == '+' ? _ink : const Color(0xFFFBCFE8))),
                ]),
              ),
          ]),
        ),
        const SizedBox(height: 10),
        _Waterfall(wc: _wc, lakh: _lakh),
        Align(
          alignment: AlignmentDirectional.centerEnd,
          child: TextButton(
            key: const ValueKey('wc-report'),
            onPressed: _openReport,
            style: TextButton.styleFrom(foregroundColor: _gold, minimumSize: const Size(48, 48)),
            child: Text(l.wcFullReport, style: const TextStyle(fontWeight: FontWeight.w700)),
          ),
        ),
      ]),
    );
  }
}

/// ছোট waterfall — সাদা জমিনে ছয় বার: তিন যোগ, দুই বাদ, শেষে চলতি মূলধন; অক্ষ সবচেয়ে উঁচু ধাপ পর্যন্ত
class _Waterfall extends StatelessWidget {
  const _Waterfall({required this.wc, required this.lakh});

  final WorkingCapitalRecord wc;
  final String Function(double) lakh;

  @override
  Widget build(BuildContext context) {
    final l = context.l10n;
    final r = wc.receivable, s = wc.stock, m = wc.money, p = wc.payable;
    final steps = [(0.0, r), (r, r + s), (r + s, r + s + m), (r + s + m, r + s + m - p), (r + s + m - p, wc.capital), (0.0, wc.capital)];
    final peak = steps.map((st) => st.$1 > st.$2 ? st.$1 : st.$2).fold<double>(1, (a, b) => a > b ? a : b);
    const plot = 104.0;
    double px(double v) => (v < 0 ? 0 : v) / peak * plot;
    final labels = ['+${lakh(r)}', '+${lakh(s)}', '+${lakh(m)}', '−${lakh(p)}', '−${lakh(wc.other)}', '=${lakh(wc.capital)}'];
    final names = [l.wcReceivable, l.wcStock, l.wcBarMoney, l.wcBarPayable, l.wcBarOther, l.wcBarCapital];
    return Container(
      key: const ValueKey('wc-chart'),
      padding: const EdgeInsets.fromLTRB(6, 18, 6, 6),
      decoration: BoxDecoration(color: _chart, borderRadius: BorderRadius.circular(12)),
      child: Column(children: [
        SizedBox(
          height: plot + 16,
          child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            for (final (i, (from, to)) in steps.indexed)
              Expanded(
                child: LayoutBuilder(builder: (context, box) {
                  final lo = px(from < to ? from : to), hi = px(from < to ? to : from);
                  return Stack(clipBehavior: Clip.none, children: [
                    Positioned(
                      left: box.maxWidth * 0.12,
                      right: box.maxWidth * 0.12,
                      bottom: lo,
                      height: (hi - lo) < 2 ? 2 : hi - lo,
                      child: DecoratedBox(decoration: BoxDecoration(color: _parts[i], borderRadius: BorderRadius.circular(5))),
                    ),
                    Positioned(
                      left: 0,
                      right: 0,
                      bottom: hi + 3,
                      child: Text(labels[i],
                          textAlign: TextAlign.center,
                          maxLines: 1,
                          overflow: TextOverflow.visible,
                          softWrap: false,
                          style: const TextStyle(fontSize: 10, fontWeight: FontWeight.w700, color: _chartInk)),
                    ),
                  ]);
                }),
              ),
          ]),
        ),
        const Divider(height: 1, color: Color(0xFFCBD5E1)),
        const SizedBox(height: 3),
        Row(children: [
          for (final (i, name) in names.indexed)
            Expanded(
              child: Text(name,
                  textAlign: TextAlign.center,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(fontSize: 10, color: i == 5 ? _chartInk : _chartSoft, fontWeight: i == 5 ? FontWeight.w700 : FontWeight.w400)),
            ),
        ]),
        const SizedBox(height: 2),
        Text(l.wcChartNote, textAlign: TextAlign.center, style: const TextStyle(fontSize: 10.5, color: Color(0xFF64748B))),
      ]),
    );
  }
}

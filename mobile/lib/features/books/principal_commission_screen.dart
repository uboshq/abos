import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/books/principal_commission_api.dart';
import '../../core/menu/module_gate.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/records/money.dart';
import '../../core/records/principal_commission.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/design_list_row.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/state_views.dart';
import '../../core/widgets/totals_bar.dart';
import '../../core/locale/l10n.dart';

/// ⭐ সব মডিউল → সরবরাহকারী → "প্রিন্সিপালের কমিশন" (মালিকের আদেশ "app e dekhate hobe", fe-র মাধ্যমে; a4-এর নকশার নিয়ম ২;
/// ২০ অক্টোবর ২০২৬)। ⓘ মালিকের হোমের কার্ডের (a4, ০.৪.৪২) "সব দেখুন"ও এই পর্দা খোলে — `/home/principal-commission`।
///
/// <p>ওয়েবের রিপোর্টের একই হিসাব (`GET /supplier/principal-commission`): প্রতিটা প্রিন্সিপালের সারি — নাম, তার নিচে মলিন
/// "এসেছে · কমিশন · পাঠানো", ডানে জের আর কথায় দিক ("দেনা · দিতে হবে" / "পাওনা · কোম্পানির কাছে পাব"); উপরে মাস বাছাই
/// (ওয়েবের মতো; খালি = চলতি চক্র) আর চক্রের তারিখ; নিচে স্থির [TotalsBar] — ⛔ সার্ভারের bcmath মোট, অ্যাপ যোগ করে না।
///
/// <p>শাখা: হেডারে বাছা শাখা সার্ভারেই থাকে, তাই ওয়েব আর অ্যাপের বাকি রিপোর্টের মতোই এই পর্দাও সেই শাখার।
/// রং কেবল থিমের; চাপার জায়গা ৪৮dp; সারি অন্তত ৭২dp ([DesignListRow]); ২০০% অক্ষরে কিছু উপচে পড়ে না (দাবি-টেস্ট)।
class PrincipalCommissionScreen extends StatefulWidget {
  const PrincipalCommissionScreen(
      {super.key, this.api = const ServerPrincipalCommissionApi(), this.month, this.clock = DateTime.now});

  final PrincipalCommissionApi api;

  /// আজকের দিন — মাস বাছাইয়ের "চলতি" মাস (পরীক্ষা নিজের দিন দেয়)
  final DateTime Function() clock;

  /// শুরুর মাস ("2026-09") — ওয়েবের লিংক `?month=` থেকে; null = চলতি চক্র
  final String? month;

  @override
  State<PrincipalCommissionScreen> createState() => _PrincipalCommissionScreenState();
}

class _PrincipalCommissionScreenState extends State<PrincipalCommissionScreen> {
  /// null = চলতি চক্র (সার্ভারে মাস যায় না)
  late String? _month = widget.month;

  /// আজকের ক্যালেন্ডার-মাস — এর আগের মাসগুলো বাছা যায়; এটা আর পরের দিকে "চলতি চক্র"।
  /// ⓘ সার্ভার মাস না চাইলে `month: null` দেয় — প্রতিটা প্রিন্সিপালের চক্র আলাদা মাসে শেষ হতে পারে (সার্ভার, cb ২০ অক্টোবর)
  late final String _currentMonth = () {
    final d = widget.clock();
    return '${d.year}-${d.month.toString().padLeft(2, '0')}';
  }();

  PrincipalCommissionPage? _page;
  Object? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final page = await widget.api.load(month: _month);
      if (!mounted) return;
      setState(() {
        _page = page;
        _loading = false;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _error = error;
        _loading = false;
      });
    }
  }

  void _go(String? month) {
    // ⓘ চলতি মাসে বা তার পরে গেলে মাস পাঠানো হয় না — ওয়েবের মতো "চলতি চক্র"
    _month = month == null || month.compareTo(_currentMonth) >= 0 ? null : month;
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final page = _page;
    final showRows = !_loading && _error == null && page != null && page.rows.isNotEmpty;
    return Scaffold(
      appBar: AbosScreenBar(title: context.l10n.booksPcTitle, module: context.l10n.booksModuleSupplier),
      bottomNavigationBar: showRows ? _totalsBar(page) : null,
      body: Column(children: [
        _MonthBar(
          month: _month,
          // ⓘ "চলতি চক্র" থেকে এক ধাপ পেছনে = আগের ক্যালেন্ডার-মাস
          onPrev: () => _go(shiftMonth(_month ?? _currentMonth, -1)),
          onNext: _month == null ? null : () => _go(shiftMonth(_month!, 1)),
          onCurrent: () => _go(null),
        ),
        Expanded(
          child: RefreshIndicator(
            onRefresh: _load,
            child: ListView(
              key: const ValueKey('principal-commission-list'),
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.fromLTRB(AppSpacing.md, 0, AppSpacing.md, AppSpacing.md),
              children: _body(context),
            ),
          ),
        ),
      ]),
    );
  }

  List<Widget> _body(BuildContext context) {
    // ⭐ তিন অবস্থা (নকশা ৩, পাতা ৮–১০): আনা চলছে → খসড়া সারি; ভুল → বাংলায় কথা আর "আবার চেষ্টা করুন"; খালি → বোতামসহ
    if (_loading) return const [LoadingSkeleton(key: ValueKey('pc-loading'))];
    final error = _error;
    if (error != null) {
      return [
        ErrorState(
          key: const ValueKey('pc-error'),
          title: _moduleOff(error) ? ModuleGate.offTitle : context.l10n.booksPcLoadFailedTitle,
          // ⓘ ফোনে ক্রয়ের সুইচ বন্ধ → সার্ভারের ৪০৩ `module_off` — অন্য বন্ধ পর্দার একই কথা ([ModuleGate])
          message: _moduleOff(error)
              ? ModuleGate.offMessage
              : errorMessageFor(
            error,
            fallback: context.l10n.booksPcLoadFailed,
            whenAbsent: context.l10n.booksPcAbsent,
          ),
          onRetry: _load,
        ),
      ];
    }
    final page = _page!;
    if (page.rows.isEmpty) {
      return [
        EmptyState(
          key: const ValueKey('pc-empty'),
          icon: Symbols.percent,
          title: context.l10n.booksPcEmptyTitle,
          message: context.l10n.booksPcEmptyHint,
          action: OutlinedButton.icon(
            key: const ValueKey('pc-empty-refresh'),
            style: OutlinedButton.styleFrom(minimumSize: const Size(48, 48)),
            onPressed: _load,
            icon: const Icon(Icons.refresh),
            label: Text(context.l10n.booksRefresh),
          ),
        ),
      ];
    }
    return [
      _TotalsCard(total: page.total),
      // ⓘ চক্রের তারিখ প্রতিটা সারিতে — প্রতিটা প্রিন্সিপালের চক্র আলাদা মাসে শেষ হতে পারে (সার্ভারের `month: null`)
      for (final row in page.rows) PrincipalCommissionTile(key: ValueKey('pc-row-${row.supplierId}'), row: row),
    ];
  }

  static bool _moduleOff(Object error) {
    if (error is! DioException || error.response?.statusCode != 403) return false;
    final body = error.response?.data;
    return body is Map && body['error'] == 'module_off';
  }

  /// ⛔ সার্ভারের মোট — সারির যোগফল নয়
  Widget _totalsBar(PrincipalCommissionPage page) {
    final total = page.total;
    return TotalsBar.grand(
      key: const ValueKey('pc-totals-bar'),
      label: context.l10n.booksPcGrandTotal(page.rows.length, total.side.word),
      allSum: unsigned(total.balance) ?? '—',
    );
  }
}

/// মাস বাছাই — ‹ আগের মাস · "অক্টোবর 2026" · পরের মাস ›, আর "চলতি চক্র" (ওয়েবের রিপোর্টের মাসের ঘরের মতো)
class _MonthBar extends StatelessWidget {
  const _MonthBar({
    required this.month,
    required this.onPrev,
    required this.onNext,
    required this.onCurrent,
  });

  /// null = চলতি চক্র
  final String? month;
  final VoidCallback onPrev;
  final VoidCallback? onNext;
  final VoidCallback onCurrent;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.sm, vertical: AppSpacing.xs),
      child: Row(children: [
        IconButton(
          key: const ValueKey('pc-month-prev'),
          tooltip: context.l10n.booksPcPrevMonth,
          constraints: const BoxConstraints(minWidth: 48, minHeight: 48),
          onPressed: onPrev,
          icon: const Icon(Icons.chevron_left),
        ),
        Expanded(
          child: Text(
            month == null ? context.l10n.booksPcCurrentCycle : monthLabel(month!),
            key: const ValueKey('pc-month'),
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 15, fontWeight: FontWeight.w600, color: cs.onSurface),
          ),
        ),
        IconButton(
          key: const ValueKey('pc-month-next'),
          tooltip: context.l10n.booksPcNextMonth,
          constraints: const BoxConstraints(minWidth: 48, minHeight: 48),
          onPressed: onNext,
          icon: const Icon(Icons.chevron_right),
        ),
        if (month != null)
          TextButton(
            key: const ValueKey('pc-month-current'),
            style: TextButton.styleFrom(minimumSize: const Size(48, 48)),
            onPressed: onCurrent,
            child: Text(context.l10n.booksPcCurrent),
          ),
      ]),
    );
  }
}

/// মাথার ছোট কার্ড — সবার মোট এসেছে · কমিশন · পাঠানো, সার্ভারের যোগফল
class _TotalsCard extends StatelessWidget {
  const _TotalsCard({required this.total});

  final PrincipalCommissionTotal total;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    Widget cell(String key, String label, String value) => Padding(
          padding: const EdgeInsets.only(right: AppSpacing.md, bottom: AppSpacing.xs),
          child: Column(key: ValueKey('pc-total-$key'), crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(label, style: TextStyle(fontSize: 12.5, color: cs.onSurfaceVariant)),
            Text(value, style: TextStyle(fontSize: 15, fontWeight: FontWeight.w700, color: cs.onSurface)),
          ]),
        );
    return Card(
      key: const ValueKey('pc-totals'),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 6),
        // ⓘ Wrap — বড় অক্ষরে ঘর নিচে নামে, উপচে পড়ে না
        child: Wrap(children: [
          cell('inflow', context.l10n.booksPcTotalInflow, Money.taka(total.inflow)),
          cell('commission', context.l10n.booksPcTotalCommission, total.commissionShown),
          cell('paid', context.l10n.booksPcTotalSent, Money.taka(total.paid)),
        ]),
      ),
    );
  }
}

/// এক প্রিন্সিপালের সারি — [DesignListRow] (৭২dp); ডানে জের (চিহ্ন ছাড়া অঙ্ক) আর তার নিচে দিকের কথা
class PrincipalCommissionTile extends StatelessWidget {
  const PrincipalCommissionTile({super.key, required this.row});

  final PrincipalCommissionRow row;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    final rate = row.basisRate;
    final second = [
      context.l10n.booksPcInflowLine(Money.taka(row.inflow)),
      rate == null ? context.l10n.booksPcCommissionLine(row.commissionShown) : context.l10n.booksPcCommissionRateLine(rate, row.commissionShown),
      context.l10n.booksPcSentLine(Money.taka(row.paid)),
    ].join(' · ');
    final tone = switch (row.side) {
      BalanceSide.pay => cs.error,
      BalanceSide.receive => cs.tertiary,
      BalanceSide.settled => cs.onSurfaceVariant,
    };
    return Semantics(
      container: true,
      label: [
        row.label,
        context.l10n.booksPcInflowLine(Money.spoken(row.inflow) ?? context.l10n.booksPcUnknown),
        context.l10n.booksPcCommissionLine(Money.speakable(row.commissionShown)),
        context.l10n.booksPcSentLine(Money.spoken(row.paid) ?? context.l10n.booksPcUnknown),
        '${row.side.sentence} ${Money.spoken(unsigned(row.balance)) ?? ''}',
      ].join(', '),
      excludeSemantics: true,
      child: DesignListRow(
        icon: Symbols.percent,
        name: row.label,
        second: second,
        secondKey: ValueKey('pc-second-${row.supplierId}'),
        below: row.cycle == null
            ? null
            : Text(context.l10n.booksPcCycle(row.cycle!),
                key: ValueKey('pc-cycle-${row.supplierId}'), style: TextStyle(fontSize: 12, color: cs.onSurfaceVariant)),
        // ⓘ টাকা লেখা হিসেবেই ([Money.taka] দশমিক-লেখা নেয়) — সারির ডানে, দিকের কথা তার নিচে
        pill: Column(crossAxisAlignment: CrossAxisAlignment.end, mainAxisSize: MainAxisSize.min, children: [
          Text(Money.taka(unsigned(row.balance)),
              key: ValueKey('pc-balance-${row.supplierId}'),
              textAlign: TextAlign.end,
              style: TextStyle(fontSize: 15, fontWeight: FontWeight.w700, color: tone)),
          Text(row.side.sentence,
              key: ValueKey('pc-side-${row.supplierId}'),
              textAlign: TextAlign.end,
              style: TextStyle(fontSize: 12, color: cs.onSurfaceVariant)),
        ]),
      ),
    );
  }
}

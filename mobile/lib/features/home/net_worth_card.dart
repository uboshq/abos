import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../core/accounts/net_worth_api.dart';
import '../../core/locale/l10n.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/state_views.dart';
import 'owner_home_cards.dart';

/// ⭐ নিট সম্পদ বিবরণী (Net Worth Statement) — মালিকের হোমে "নগদ · MFS · ব্যাংক" কার্ডের ঠিক নিচে (মালিক, ২৫ অক্টোবর ২০২৬:
/// "Cash mfs bank card er niche daw"; নমুনা "নিট-সম্পদ-বিবরণী-নমুনা-২৫-অক্টোবর.pdf")।
///
/// <p>⛔ কেবল মালিক: সার্ভার অন্য কাউকে ৪০৪ দেয়, তখন কার্ড নেই — আনতে আনতেও কিছু দেখায় না, যাতে মালিক নন এমন কারো পর্দায়
/// কার্ডের ছায়াও না পড়ে। চাপলে পুরো বিবরণী ([NetWorthScreen])।

/// `2026-10-25` → `25/10/2026`; পড়া না গেলে যেমন এসেছে
String netWorthDate(String asOf) {
  final parsed = DateTime.tryParse(asOf);
  return parsed == null ? asOf : DateFormat('dd/MM/yyyy').format(parsed);
}

class NetWorthHomeCard extends StatefulWidget {
  const NetWorthHomeCard({super.key, this.api = const ServerNetWorthApi()});

  final NetWorthApi api;

  @override
  State<NetWorthHomeCard> createState() => _NetWorthHomeCardState();
}

class _NetWorthHomeCardState extends State<NetWorthHomeCard> {
  late Future<NetWorthSummary?> _future = widget.api.summary();

  void _retry() => setState(() => _future = widget.api.summary());

  void _open() => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => NetWorthScreen(api: widget.api)));

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<NetWorthSummary?>(
      future: _future,
      builder: (context, snap) {
        if (snap.connectionState != ConnectionState.done) return const SizedBox.shrink();
        if (snap.hasError) {
          return HomeSectionCard(
            key: const ValueKey('net-worth-card'),
            title: context.l10n.netWorthTitle,
            children: [ErrorState(message: context.l10n.netWorthLoadFailed, onRetry: _retry)],
          );
        }
        final summary = snap.data;
        if (summary == null) return const SizedBox.shrink();
        final palette = context.palette;
        return HomeSectionCard(
          key: const ValueKey('net-worth-card'),
          title: context.l10n.netWorthTitle,
          subtitle: context.l10n.netWorthAsOf(netWorthDate(summary.asOf)),
          actionLabel: context.l10n.homeSeeAll,
          onAction: _open,
          children: [
            InkWell(
              key: const ValueKey('net-worth-open'),
              onTap: _open,
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                AmountLine(label: context.l10n.netWorthNet, amount: Money.value(summary.net), total: true),
                AmountLine(label: context.l10n.netWorthThisYear, amount: Money.value(summary.thisYear)),
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 8),
                  child: Text(
                    summary.agrees ? context.l10n.netWorthAgrees : context.l10n.netWorthDoesNotAgree,
                    key: const ValueKey('net-worth-agrees'),
                    style: TextStyle(
                        fontSize: 12.5,
                        fontWeight: FontWeight.w600,
                        color: summary.agrees ? palette.success : palette.danger),
                  ),
                ),
              ]),
            ),
          ],
        );
      },
    );
  }
}

/// ⭐ পুরো বিবরণী — উপরে নিট অঙ্ক আর দুই দিক মেলে কি না; নিচে চার ঘর (নগদ-ব্যাংক, পাওনা, মজুদ, অন্য সম্পদ) তাদের খাত
/// নিয়ে; শেষে দুই দিক: সম্পদ − দায় = নিট, আর মূলধন − উত্তোলন + আগের লাভ + এ বছরের লাভ = নিট। ওয়েবের পাতার একই সাজ, এক
/// কলামে — ফোনের পর্দা ডান দিক কেটে দেয়।
class NetWorthScreen extends StatefulWidget {
  const NetWorthScreen({super.key, this.api = const ServerNetWorthApi()});

  final NetWorthApi api;

  @override
  State<NetWorthScreen> createState() => _NetWorthScreenState();
}

class _NetWorthScreenState extends State<NetWorthScreen> {
  late Future<NetWorthStatementRecord> _future = widget.api.statement();

  Future<void> _reload() async {
    final next = widget.api.statement();
    setState(() => _future = next);
    await next.catchError((_) => const NetWorthStatementRecord({}));
  }

  @override
  Widget build(BuildContext context) {
    final l = context.l10n;
    return Scaffold(
      appBar: AbosScreenBar(title: l.netWorthTitle),
      body: FutureBuilder<NetWorthStatementRecord>(
        future: _future,
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) return const LoadingSkeleton();
          if (snap.hasError) return ErrorState(message: l.netWorthLoadFailed, onRetry: _reload);
          final s = snap.data!;
          final tileNames = {
            'cash': l.netWorthCash,
            'receivable': l.netWorthReceivable,
            'stock': l.netWorthStock,
            'other_assets': l.netWorthOtherAssets,
          };
          return RefreshIndicator(
            onRefresh: _reload,
            child: ListView(
              padding: const EdgeInsets.all(AppSpacing.md),
              children: [
                _NetBox(statement: s),
                const SizedBox(height: AppSpacing.sm),
                for (final key in NetWorthStatementRecord.tiles)
                  HomeSectionCard(
                    key: ValueKey('net-worth-tile-$key'),
                    title: tileNames[key]!,
                    subtitle: Money.taka(s.figure(key)),
                    children: [
                      for (final a in s.accounts(key))
                        AmountLine(label: '${a.code} · ${a.name}', amount: Money.value(a.amount)),
                    ],
                  ),
                HomeSectionCard(
                  key: const ValueKey('net-worth-position'),
                  title: l.netWorthPosition,
                  children: [
                    AmountLine(label: l.netWorthAssets, amount: Money.value(s.figure('assets')), total: true),
                    AmountLine(label: l.netWorthLessPayable, amount: _less(s.figure('payable'))),
                    AmountLine(label: l.netWorthLessOtherLiabilities, amount: _less(s.figure('other_liabilities'))),
                    AmountLine(label: '= ${l.netWorthNet}', amount: Money.value(s.figure('net')), total: true),
                  ],
                ),
                HomeSectionCard(
                  key: const ValueKey('net-worth-equity'),
                  title: l.netWorthEquity,
                  children: [
                    AmountLine(label: l.netWorthCapital, amount: Money.value(s.figure('capital'))),
                    AmountLine(label: l.netWorthDrawings, amount: _less(s.figure('drawings'))),
                    AmountLine(label: l.netWorthEarlier, amount: Money.value(s.figure('earlier'))),
                    AmountLine(label: l.netWorthThisYear, amount: Money.value(s.figure('this_year'))),
                    AmountLine(label: '= ${l.netWorthNet}', amount: Money.value(s.figure('equity')), total: true),
                  ],
                ),
              ],
            ),
          );
        },
      ),
    );
  }

  /// বাদ যাওয়া অঙ্ক বিয়োগ চিহ্নে — ওয়েবের পাতার "−" (সার্ভারের অঙ্কের চিহ্ন উল্টে, কষে নয়)
  static double? _less(dynamic value) {
    final v = Money.value(value);
    return v == null ? null : -v;
  }
}

/// উত্তরের বাক্স: সবুজ (দুই দিক মেলে) বা লাল (খাতায় ভুল) — বড় নিট অঙ্ক, নিচে কোম্পানি আর তারিখ
class _NetBox extends StatelessWidget {
  const _NetBox({required this.statement});

  final NetWorthStatementRecord statement;

  @override
  Widget build(BuildContext context) {
    final l = context.l10n;
    final palette = context.palette;
    final ok = statement.agrees;
    final ink = ok ? palette.success : palette.danger;
    return Container(
      key: const ValueKey('net-worth-net-box'),
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: ok ? palette.successSurface : palette.dangerSurface,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: ok ? palette.border : palette.danger),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(l.netWorthNet, style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700, color: ink)),
        const SizedBox(height: 4),
        Text(Money.taka(statement.figure('net')),
            key: const ValueKey('net-worth-net'),
            style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w800, fontFeatures: [FontFeature.tabularFigures()])),
        const SizedBox(height: 4),
        Text('${l.netWorthThisYear} ${Money.taka(statement.figure('this_year'))}',
            style: TextStyle(fontSize: 13, color: palette.onSurfaceMuted)),
        Text(ok ? l.netWorthAgrees : l.netWorthDoesNotAgree,
            style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: ink)),
        Text('${statement.companyName} · ${l.netWorthAsOf(netWorthDate(statement.asOf))}',
            style: TextStyle(fontSize: 12.5, color: palette.onSurfaceMuted)),
      ]),
    );
  }
}

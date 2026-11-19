import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../core/locale/l10n.dart';
import '../../core/records/money.dart';
import '../../core/records/today_record.dart';
import '../../core/theme/app_look.dart';
import '../../core/theme/expressive.dart';
import '../../core/theme/glass.dart';

/// ⭐ হোমের মাথার কার্ড — Liquid Glass আর Material 3-এ (0.4.58; মালিকের নমুনা, ২০ নভেম্বর ২০২৬ "ok"): "সুপ্রভাত, নাম",
/// বড় করে "আজকের কাজ", তার নিচে আজকের বিক্রির কার্ড — কাচে লক্ষ্যের রেখা, Material 3-এ বেগুনি কার্ডে ঢেউ।
///
/// <p>ⓘ অঙ্ক কেবল আজকের তথ্য থেকে ([TodayRecord]) — বিক্রির নাম সার্ভারের ([TodayFigure.salesHeading]); সার্ভার বিক্রি না
/// পাঠালে (চাবি নেই) কার্ডে কেবল শিরোনাম। লক্ষ্য কেবল সার্ভার `target` পাঠালে (`{target, achieved}`); না পাঠালে রেখা বা ঢেউ নেই,
/// তার জায়গায় আজকের আদায় — বানানো শতাংশ কখনো নয়। ABOS রূপে এটা আঁকা হয় না।
class LookHero extends StatelessWidget {
  const LookHero({super.key, required this.today, required this.name, this.onTile});

  final TodayRecord? today;
  final String name;

  /// Material 3-এর চার ঘরে চাপ — `/home/<path>`
  final void Function(String path, String label)? onTile;

  /// লক্ষ্যের কত ভাগ — সার্ভারের `target` থেকে; না থাকলে বা শূন্য হলে null
  static double? targetShare(TodayRecord? today) {
    final block = today?.payload['target'];
    if (block is! Map) return null;
    final target = Money.value(block['target']);
    if (target == null || target <= 0) return null;
    return Money.valueOrZero(block['achieved']) / target;
  }

  @override
  Widget build(BuildContext context) {
    final look = (Theme.of(context), AppLooks.instance.look.value).$2;
    if (look == AppLook.abos) return const SizedBox.shrink();
    final l = context.l10n;
    final sales = today?.sales;
    final share = targetShare(today);
    final collected = today?.collections?.amount;
    final caption = share != null
        ? l.lookTargetPercent('${(share * 100).floor()}')
        : collected != null
            ? l.lookCollectedToday(Money.taka(collected))
            : null;
    final heading = Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Text(l.lookGreeting(name), style: const TextStyle(fontSize: 13.5)),
      Text(l.lookTodayWork,
          style: TextStyle(
              fontSize: look == AppLook.expressive ? 32 : 30,
              fontWeight: look == AppLook.expressive ? FontWeight.w800 : FontWeight.w700,
              height: 1.15)),
    ]);
    final amount = sales?.amount == null ? null : Money.taka(sales!.amount);
    if (look == AppLook.glass) {
      return Column(key: const ValueKey('look-hero-glass'), crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        DefaultTextStyle.merge(
          style: const TextStyle(color: Colors.white, shadows: [Shadow(color: Color(0x59000000), blurRadius: 12)]), // dark-ok: কাচ সবসময় অন্ধকার পটে
          child: heading,
        ),
        const SizedBox(height: 12),
        GlassPanel(
          radius: 28,
          padding: const EdgeInsets.fromLTRB(18, 14, 18, 14),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(sales?.salesHeading ?? l.lookTodayWork, style: const TextStyle(fontSize: 13)),
            if (amount != null)
              Text(amount, key: const ValueKey('look-hero-amount'), style: const TextStyle(fontSize: 30, fontWeight: FontWeight.w700, letterSpacing: -0.5)),
            if (share != null) ...[
              const SizedBox(height: 8),
              ClipRRect(
                borderRadius: BorderRadius.circular(4),
                child: SizedBox(
                  height: 8,
                  child: Stack(fit: StackFit.expand, children: [
                    ColoredBox(color: Colors.white.withValues(alpha: 0.18)), // dark-ok: কাচ সবসময় অন্ধকার পটে
                    FractionallySizedBox(
                      key: const ValueKey('look-hero-ring'),
                      alignment: Alignment.centerLeft,
                      widthFactor: share.clamp(0.0, 1.0),
                      child: const DecoratedBox(
                          decoration: BoxDecoration(gradient: LinearGradient(colors: [Color(0xFFFFFFFF), Color(0xFFBFE6FF)]))),
                    ),
                  ]),
                ),
              ),
            ],
            if (caption != null) ...[
              const SizedBox(height: 4),
              Text(caption, style: const TextStyle(fontSize: 13)),
            ],
          ]),
        ),
      ]);
    }
    // Material 3 Expressive
    final tiles = <(String, String, String)>[
      (l.lookQuickDeposit, '৳', 'deposit-report'), // l10n-ok: the taka sign is the currency itself
      (l.lookTileCollected, today?.collections?.count == null ? '—' : '${today!.collections!.count}', 'collections'),
      (l.lookTileApprovals, today?.pendingApprovals == null ? '—' : '${today!.pendingApprovals}', 'approvals'),
      (l.lookTileDues, today?.dues?.amount == null ? '—' : _short(today!.dues!.amount!), 'dues'),
    ];
    return Column(key: const ValueKey('look-hero-expressive'), crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      DefaultTextStyle.merge(style: const TextStyle(color: Expressive.ink), child: heading),
      const SizedBox(height: 12),
      Container(
        constraints: const BoxConstraints(minHeight: 150),
        padding: const EdgeInsets.fromLTRB(18, 16, 18, 16),
        clipBehavior: Clip.antiAlias,
        decoration: const BoxDecoration(color: Expressive.primary, borderRadius: Expressive.heroShape),
        child: Stack(clipBehavior: Clip.none, children: [
          Positioned(right: -36, top: -34, child: Opacity(opacity: 0.25, child: CustomPaint(size: const Size(110, 110), painter: _Cookie()))),
          DefaultTextStyle.merge(
            style: const TextStyle(color: Colors.white), // dark-ok: নমুনার বেগুনি কার্ডে সাদা লেখা
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(sales?.salesHeading ?? l.lookTodayWork, style: const TextStyle(fontSize: 13)),
              if (amount != null)
                Text(amount, key: const ValueKey('look-hero-amount'), style: Expressive.bigNumber.copyWith(fontSize: 34)),
              if (caption != null) Text(caption, style: const TextStyle(fontSize: 13)),
              if (share != null) ...[
                const SizedBox(height: 12),
                ExpressiveWave(key: const ValueKey('look-hero-wave'), progress: share),
              ],
            ]),
          ),
        ]),
      ),
      const SizedBox(height: 12),
      LayoutBuilder(builder: (context, box) {
        final w = (box.maxWidth - 8) / 2;
        return Wrap(spacing: 8, runSpacing: 8, children: [
          for (final (i, (label, value, path)) in tiles.indexed)
            SizedBox(
              width: w,
              child: Material(
                key: ValueKey('look-tile-$path'),
                color: Expressive.tiles[i].$1,
                borderRadius: Expressive.tiles[i].$3,
                child: InkWell(
                  borderRadius: Expressive.tiles[i].$3,
                  onTap: onTile == null ? null : () => onTile!(path, label),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                    child: ConstrainedBox(
                      constraints: const BoxConstraints(minHeight: 58),
                      child: Column(
                        crossAxisAlignment: i == 2 ? CrossAxisAlignment.center : CrossAxisAlignment.start,
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text(label,
                              maxLines: 2,
                              textAlign: i == 2 ? TextAlign.center : TextAlign.start,
                              style: TextStyle(fontWeight: FontWeight.w700, color: Expressive.tiles[i].$2)),
                          Text(value, style: Expressive.bigNumber.copyWith(fontSize: 24, color: Expressive.tiles[i].$2)),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
            ),
        ]);
      }),
    ]);
  }

  /// "৳41L" — নমুনার ছোট রূপ (লাখ/কোটি), ইংরেজি অঙ্কে
  static String _short(double v) {
    final a = v.abs();
    final sign = v < 0 ? '-' : '';
    if (a >= 1e7) return '$sign৳${(a / 1e7).toStringAsFixed(a >= 1e8 ? 0 : 1)}Cr'; // l10n-ok: the taka sign is the currency itself
    if (a >= 1e5) return '$sign৳${(a / 1e5).toStringAsFixed(a >= 1e6 ? 0 : 1)}L'; // l10n-ok: the taka sign is the currency itself
    if (a >= 1e3) return '$sign৳${(a / 1e3).toStringAsFixed(0)}K'; // l10n-ok: the taka sign is the currency itself
    return '$sign৳${a.toStringAsFixed(0)}'; // l10n-ok: the taka sign is the currency itself
  }
}

/// নমুনার "কুকি" — ঢেউ-কিনারার গোল ছাপ
class _Cookie extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final c = size.center(Offset.zero);
    final r = size.width / 2;
    final path = Path();
    for (var i = 0; i <= 360; i += 3) {
      final t = i * math.pi / 180;
      final rr = r * (0.88 + 0.08 * math.cos(9 * t));
      final p = c + Offset(math.cos(t) * rr, math.sin(t) * rr);
      i == 0 ? path.moveTo(p.dx, p.dy) : path.lineTo(p.dx, p.dy);
    }
    canvas.drawPath(path..close(), Paint()..color = const Color(0xFFFFFFFF));
  }

  @override
  bool shouldRepaint(_Cookie old) => false;
}

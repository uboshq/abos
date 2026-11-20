import 'dart:math' as math;

import 'package:flutter/material.dart';

/// ⭐ Liquid Glass-এর পটভূমি (মালিক, ২০ নভেম্বর ২০২৬: "পটভূমি" বাছাই, বেগুনিটা বিশেষ করে সুন্দর)।
///
/// <p>⛔ সবগুলো ABOS-এর নিজের, কোডে আঁকা — নরম আলোর ঢাল, ঝাপসা গোল আভা আর ঢেউ। অন্য কোনো কোম্পানির ছবি নকল, ট্রেস বা
/// সাথে দেওয়া নয়, কোনো কোম্পানির নামও নয়।
///
/// <p>ⓘ সব স্থির — একবার আঁকা, ইঞ্জিন ছবিটা রেখে দেয় ([GlassWallpaperView]-এর RepaintBoundary, isComplex); নড়ে কেবল
/// "রঙিন বল" ([balls], আগের মতো, [GlassBackdrop]-এর নিজের)। ⓘ লেখা পড়া যায় — প্রতিটার ওপরে নিজের কালচে পর্দা ([scrim]);
/// test/app_0458/wallpaper_test.dart প্রতিটা রঙে সাদা লেখার তুলনা মেপে দেখে।
enum GlassWallpaper { balls, purple, blueWave, sunset, aurora, ocean, graphite, silver }

/// একটা গোল আভা — মাঝখান আর ব্যাসার্ধ পর্দার অনুপাতে (ব্যাসার্ধ লম্বা দিকের অনুপাতে)
@immutable
class WallpaperGlow {
  const WallpaperGlow(this.x, this.y, this.r, this.color, {this.alpha = 0.85});

  final double x, y, r;
  final Color color;
  final double alpha;
}

/// একটা ঢেউয়ের ফিতা — [y] উচ্চতায়, [amp] ঢেউয়ের উচ্চতা, [waves] পর্দা জুড়ে কয়টা ঢেউ
@immutable
class WallpaperWave {
  const WallpaperWave(this.y, this.amp, this.waves, this.from, this.to, {this.alpha = 0.55, this.phase = 0});

  final double y, amp, waves, phase;
  final Color from, to;
  final double alpha;
}

@immutable
class WallpaperSpec {
  const WallpaperSpec({required this.base, this.glows = const [], this.waves = const [], this.scrim = 0});

  /// ওপর থেকে নিচে পটের ঢাল
  final List<Color> base;
  final List<WallpaperGlow> glows;
  final List<WallpaperWave> waves;

  /// সবার ওপরে কালো পর্দা (০–১) — উজ্জ্বল রঙে লেখা পড়ার জন্য
  final double scrim;

  /// পর্দার সব রং — টেস্টে লেখার তুলনা মাপার জন্য (ঢাল, আভা, ঢেউ)
  List<Color> get colors => [
        ...base,
        for (final g in glows) g.color,
        for (final w in waves) ...[w.from, w.to],
      ];
}

extension GlassWallpaperSpec on GlassWallpaper {
  /// ⓘ "রঙিন বল" নিজে আঁকে না ([GlassBackdrop]) — এখানে কেবল নমুনার ছবির (বাছাইয়ের ঘর) জন্য
  WallpaperSpec get spec => switch (this) {
        GlassWallpaper.balls => const WallpaperSpec(base: [Color(0xFF0B1026), Color(0xFF0B1026)], glows: [
            WallpaperGlow(0.15, 0.25, 0.45, Color(0xFFFF2D6F)),
            WallpaperGlow(0.95, 0.55, 0.5, Color(0xFF2A5BFF)),
            WallpaperGlow(0.35, 0.95, 0.4, Color(0xFF18B26B)),
            WallpaperGlow(0.85, 0.05, 0.3, Color(0xFF6B2DFF)),
          ]),
        // ⭐ বেগুনি আভা — গভীর বেগুনি থেকে উজ্জ্বল বেগুনির আলো (মালিকের বিশেষ পছন্দ)
        GlassWallpaper.purple => const WallpaperSpec(
            base: [Color(0xFF0E0224), Color(0xFF240A52), Color(0xFF3A1078)],
            glows: [
              WallpaperGlow(0.78, 0.22, 0.62, Color(0xFF7C3AED)),
              WallpaperGlow(0.15, 0.72, 0.58, Color(0xFFA21CAF), alpha: 0.75),
              WallpaperGlow(0.55, 0.52, 0.34, Color(0xFF8B5CF6), alpha: 0.55),
              WallpaperGlow(0.92, 0.92, 0.42, Color(0xFF4C1D95), alpha: 0.8),
            ],
            scrim: 0.12),
        GlassWallpaper.blueWave => const WallpaperSpec(
            base: [Color(0xFF020A26), Color(0xFF071B4D), Color(0xFF0B2A6B)],
            glows: [WallpaperGlow(0.2, 0.15, 0.5, Color(0xFF1D4ED8), alpha: 0.6)],
            waves: [
              WallpaperWave(0.42, 0.06, 1.2, Color(0xFF1E3A8A), Color(0xFF2563EB), alpha: 0.7),
              WallpaperWave(0.6, 0.07, 0.9, Color(0xFF1D4ED8), Color(0xFF3B82F6), alpha: 0.55, phase: 1.4),
              WallpaperWave(0.78, 0.05, 1.4, Color(0xFF1E40AF), Color(0xFF2563EB), alpha: 0.6, phase: 2.6),
            ],
            scrim: 0.12),
        GlassWallpaper.sunset => const WallpaperSpec(
            base: [Color(0xFF1F0718), Color(0xFF4A0F2E), Color(0xFF6E1A35)],
            glows: [
              WallpaperGlow(0.5, 0.98, 0.7, Color(0xFFEA580C)),
              WallpaperGlow(0.15, 0.45, 0.5, Color(0xFFE11D48), alpha: 0.7),
              WallpaperGlow(0.9, 0.3, 0.45, Color(0xFFDB2777), alpha: 0.6),
              WallpaperGlow(0.65, 0.78, 0.3, Color(0xFFF97316), alpha: 0.7),
            ],
            scrim: 0.34),
        GlassWallpaper.aurora => const WallpaperSpec(
            base: [Color(0xFF010D0B), Color(0xFF02201B), Color(0xFF042F28)],
            glows: [WallpaperGlow(0.8, 0.85, 0.5, Color(0xFF065F46), alpha: 0.8)],
            waves: [
              WallpaperWave(0.28, 0.09, 0.8, Color(0xFF059669), Color(0xFF10B981), alpha: 0.6),
              WallpaperWave(0.4, 0.08, 1.1, Color(0xFF0D9488), Color(0xFF22D3EE), alpha: 0.4, phase: 1.1),
              WallpaperWave(0.52, 0.06, 0.7, Color(0xFF047857), Color(0xFF34D399), alpha: 0.4, phase: 2.2),
            ],
            scrim: 0.24),
        GlassWallpaper.ocean => const WallpaperSpec(
            base: [Color(0xFF021826), Color(0xFF03304A), Color(0xFF064663)],
            glows: [
              WallpaperGlow(0.2, 0.3, 0.55, Color(0xFF0D9488), alpha: 0.7),
              WallpaperGlow(0.85, 0.65, 0.55, Color(0xFF0284C7), alpha: 0.7),
            ],
            waves: [
              WallpaperWave(0.82, 0.04, 1.6, Color(0xFF0E7490), Color(0xFF0891B2), alpha: 0.6),
            ],
            scrim: 0.24),
        GlassWallpaper.graphite => const WallpaperSpec(
            base: [Color(0xFF0B0C0F), Color(0xFF15171C), Color(0xFF1E2128)],
            glows: [
              WallpaperGlow(0.8, 0.2, 0.55, Color(0xFF3F4656), alpha: 0.7),
              WallpaperGlow(0.2, 0.8, 0.5, Color(0xFF312E81), alpha: 0.35),
            ]),
        // ⓘ রুপালি — আলোয় দেখার মতো হালকা ধাতব, তবু সাদা লেখা পড়া যায় (কালচে পর্দা বেশি)
        GlassWallpaper.silver => const WallpaperSpec(
            base: [Color(0xFFB8BFC9), Color(0xFF8A929E), Color(0xFF5E6672)],
            glows: [
              WallpaperGlow(0.2, 0.15, 0.55, Color(0xFFE2E8F0), alpha: 0.5),
              WallpaperGlow(0.85, 0.7, 0.5, Color(0xFF94A3B8), alpha: 0.6),
            ],
            scrim: 0.6),
      };
}

/// একটা পটভূমি আঁকা — সব মাপে একই অনুপাতে
class WallpaperPainter extends CustomPainter {
  const WallpaperPainter(this.spec);

  final WallpaperSpec spec;

  @override
  void paint(Canvas canvas, Size size) {
    final rect = Offset.zero & size;
    canvas.drawRect(
        rect,
        Paint()
          ..shader = LinearGradient(begin: Alignment.topCenter, end: Alignment.bottomCenter, colors: spec.base)
              .createShader(rect));
    final long = math.max(size.width, size.height);
    for (final g in spec.glows) {
      final c = Offset(size.width * g.x, size.height * g.y);
      final r = long * g.r;
      canvas.drawCircle(
        c,
        r,
        Paint()
          ..shader = RadialGradient(colors: [
            g.color.withValues(alpha: g.alpha),
            g.color.withValues(alpha: g.alpha * 0.45),
            g.color.withValues(alpha: 0),
          ], stops: const [0, 0.45, 1])
              .createShader(Rect.fromCircle(center: c, radius: r)),
      );
    }
    for (final w in spec.waves) {
      final path = Path()..moveTo(0, size.height);
      const steps = 48;
      for (var i = 0; i <= steps; i++) {
        final x = size.width * i / steps;
        final y = size.height * (w.y + w.amp * math.sin(w.phase + 2 * math.pi * w.waves * i / steps));
        path.lineTo(x, y);
      }
      path
        ..lineTo(size.width, size.height)
        ..close();
      final top = size.height * (w.y - w.amp);
      canvas.drawPath(
        path,
        Paint()
          ..shader = LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [w.to.withValues(alpha: w.alpha), w.from.withValues(alpha: 0)],
          ).createShader(Rect.fromLTRB(0, top, size.width, size.height))
          ..maskFilter = MaskFilter.blur(BlurStyle.normal, size.shortestSide * 0.02),
      );
    }
    if (spec.scrim > 0) canvas.drawRect(rect, Paint()..color = Color.fromRGBO(0, 0, 0, spec.scrim));
  }

  @override
  bool shouldRepaint(WallpaperPainter old) => old.spec != spec;
}

/// ⭐ স্থির পটভূমি — একবার আঁকা, তারপর ইঞ্জিনের রাখা ছবি (নড়ে না, তাই খরচ নেই)
class GlassWallpaperView extends StatelessWidget {
  const GlassWallpaperView({super.key, required this.wallpaper});

  final GlassWallpaper wallpaper;

  @override
  Widget build(BuildContext context) => RepaintBoundary(
        child: CustomPaint(
          key: ValueKey('wallpaper-${wallpaper.name}'),
          isComplex: true,
          willChange: false,
          painter: WallpaperPainter(wallpaper.spec),
          child: const SizedBox.expand(),
        ),
      );
}

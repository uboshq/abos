import 'dart:math' as math;
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';

/// ⭐ Liquid Glass-এর পটভূমি (মালিক, ২০ নভেম্বর ২০২৬: "পটভূমি" বাছাই, বেগুনিটা বিশেষ করে সুন্দর)।
///
/// <p>⛔ সবগুলো ABOS-এর নিজের, কোডে আঁকা — নরম আলোর ঢাল, ঝাপসা গোল আভা আর ঢেউ। অন্য কোনো কোম্পানির ছবি নকল, ট্রেস বা
/// সাথে দেওয়া নয়, কোনো কোম্পানির নামও নয়।
///
/// <p>ⓘ সব স্থির — একবার আঁকা, ইঞ্জিন ছবিটা রেখে দেয় ([GlassWallpaperView]-এর RepaintBoundary, isComplex); নড়ে কেবল
/// "রঙিন বল" ([balls], আগের মতো, [GlassBackdrop]-এর নিজের)। ⓘ লেখা পড়া যায় — প্রতিটার ওপরে নিজের কালচে পর্দা ([scrim]);
/// test/app_0458/wallpaper_test.dart প্রতিটা রঙে সাদা লেখার তুলনা মেপে দেখে।
///
/// <p>⭐ [purpleWave] "বেগুনি ঢেউ" (মালিকের অনুমোদন, ২০ নভেম্বর ২০২৬) — বাছাইয়ে প্রথমে, তবে শুরুর পট নয় (শুরুতে রঙিন বল)।
/// ⓘ ক্রম = বাছাইয়ের ঘরের ক্রম; মনে রাখা হয় নাম ধরে, তাই নতুনটা সামনে বসালে আগের বাছাই বদলায় না।
enum GlassWallpaper { purpleWave, balls, purple, blueWave, sunset, aurora, ocean, graphite, silver }

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
        // ⭐ বেগুনি ঢেউ — আঁকে [PurpleWavePainter]; এখানে তার সব রং (প্রতিটা ঢালের প্রতিটা থামা, দুই আভা) আর কালচে পর্দা, যাতে
        // টেস্ট লেখার তুলনা মাপে। ⓘ পর্দা ০.৫ — ডান-ওপরের উজ্জ্বল গোলাপি (#F070FF) আর কিনারার আভায় সাদা লেখা ৪.৫:১ রাখতে
        GlassWallpaper.purpleWave => const WallpaperSpec(
            base: [
              ...PurpleWavePainter.bgStops, ...PurpleWavePainter.tlStops, ...PurpleWavePainter.trStops,
              ...PurpleWavePainter.midStops, ...PurpleWavePainter.diagStops, ...PurpleWavePainter.sStops,
              ...PurpleWavePainter.lowStops,
            ],
            glows: [
              WallpaperGlow(1, 0.8, 0.5, PurpleWavePainter.violet, alpha: 0.7),
              WallpaperGlow(0, 0, 0, PurpleWavePainter.rimGlow, alpha: PurpleWavePainter.rimGlowAlpha),
            ],
            scrim: PurpleWavePainter.scrim),
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

  /// যে আঁকে — বেগুনি ঢেউ নিজের পথ আর ঢালে, বাকিরা [WallpaperPainter]-এ
  CustomPainter get painter => this == GlassWallpaper.purpleWave ? const PurpleWavePainter() : WallpaperPainter(spec);
}

/// ⭐ "বেগুনি ঢেউ" — ABOS-এর নিজের আঁকা নকশা (৫৮৯×১২৮০ মাপের SVG), হুবহু Flutter-এ: একই রং, একই cubic পথ, একই ঢাল আর
/// জ্বলা কিনারা (ঝাপসা ৫px দাগের ওপর ১.৬px দাগ)। পর্দায় বসে BoxFit.cover-এর মতো — মাপ = max(w/589, h/1280), মাঝখানে।
///
/// <p>ⓘ SVG-র ঢাল নিজের পথের বাক্স ধরে (objectBoundingBox) — তাই প্রতিটা ঢাল ০–১ মাপে বানিয়ে পথের আঁটসাঁট বাক্সের ম্যাট্রিক্সে বসানো,
/// চওড়া-লম্বা আলাদা বাক্সে গোল আলো ডিম্বাকার হয় SVG-র মতোই। ⓘ পথ আর ঢাল একবারই বানানো (static), পর্দার মাপ বদলালেও একই —
/// বদলায় কেবল ক্যানভাসের মাপ। ⓘ স্থির — [GlassWallpaperView]-এর RepaintBoundary ছবিটা রেখে দেয়।
class PurpleWavePainter extends CustomPainter {
  const PurpleWavePainter();

  static const double viewW = 589, viewH = 1280;

  static const bgStops = [Color(0xFFA93AD0), Color(0xFF4A106A), Color(0xFF1C0428), Color(0xFF0B0112)];
  static const tlStops = [Color(0xFF16031F), Color(0xFF330948), Color(0xFF6A1B8C)];
  static const trStops = [Color(0xFFF070FF), Color(0xFFB237D8), Color(0xFF5A1379), Color(0xFF3A0A52)];
  static const midStops = [Color(0xFF6A2194), Color(0xFF43105F), Color(0xFF240633), Color(0xFF10021A)];
  static const diagStops = [Color(0xFF120219), Color(0xFF360C4F), Color(0xFF8A2DB5)];
  static const sStops = [Color(0xFFC24AE6), Color(0xFF5C177F), Color(0xFF1D0529)];
  static const lowStops = [Color(0xFF1E0530), Color(0xFF5B1D8E)];
  static const violet = Color(0xFF9A52E6);
  static const rimGlow = Color(0xFFFF7CFF);
  static const rimGlowAlpha = 0.65;
  static const rimLine = Color(0xFFFFB8FF);
  static const rimLineAlpha = 0.95;

  /// সবার ওপরে কালো পর্দা — লেখা পড়ার জন্য (test/app_0458/wallpaper_test.dart)
  static const double scrim = 0.5;

  static _Shape _tr() => _Shape(300, 0)
    ..line(589, 0)
    ..line(589, 470)
    ..cubic(520, 420, 470, 330, 440, 230)
    ..cubic(410, 130, 360, 50, 300, 0)
    ..close();
  static _Shape _tl() => _Shape(0, 0)
    ..line(330, 0)
    ..cubic(300, 110, 190, 270, 0, 450)
    ..close();
  static _Shape _mid() => _Shape(0, 475)
    ..cubic(130, 420, 300, 410, 430, 445)
    ..cubic(500, 465, 545, 470, 589, 455)
    ..line(589, 1280)
    ..line(0, 1280)
    ..close();
  static _Shape _s() => _Shape(360, 452)
    ..cubic(500, 600, 470, 900, 300, 1280)
    ..line(589, 1280)
    ..line(589, 600)
    ..cubic(540, 520, 470, 470, 430, 447)
    ..close();
  static _Shape _diag() => _Shape(0, 1060)
    ..cubic(130, 900, 330, 720, 589, 560)
    ..line(589, 700)
    ..cubic(420, 800, 230, 980, 90, 1280)
    ..line(0, 1280)
    ..close();
  static _Shape _low() => _Shape(589, 700)
    ..cubic(430, 800, 250, 1000, 170, 1280)
    ..line(589, 1280)
    ..close();

  /// জ্বলা কিনারার ছয়টা খোলা পথ
  static final List<Path> _rims = [
    (_Shape(330, 0)..cubic(300, 110, 190, 270, 0, 450)).path,
    (_Shape(589, 470)
          ..cubic(520, 420, 470, 330, 440, 230)
          ..cubic(410, 130, 360, 50, 300, 0))
        .path,
    (_Shape(0, 475)
          ..cubic(130, 420, 300, 410, 430, 445)
          ..cubic(500, 465, 545, 470, 589, 455))
        .path,
    (_Shape(360, 452)..cubic(500, 600, 470, 900, 300, 1280)).path,
    (_Shape(0, 1060)..cubic(130, 900, 330, 720, 589, 560)).path,
    (_Shape(589, 700)..cubic(430, 800, 250, 1000, 170, 1280)).path,
  ];

  static const _full = Rect.fromLTWH(0, 0, viewW, viewH);

  /// (পথ, রঙের তুলি) — SVG-র ক্রমে; একবারই বানানো
  static final List<(Path, Paint)> _fills = () {
    final tr = _tr(), tl = _tl(), mid = _mid(), s = _s(), diag = _diag(), low = _low();
    return <(Path, Paint)>[
      (Path()..addRect(_full), _paint(_radial(_full, const Offset(0.85, 0.1), 1, bgStops, const [0, 0.3, 0.7, 1]))),
      (tr.path, _paint(_linear(tr.bounds, const Offset(1, 0), const Offset(0, 1), trStops, const [0, 0.4, 0.8, 1]))),
      (tl.path, _paint(_linear(tl.bounds, Offset.zero, const Offset(1, 1), tlStops, const [0, 0.55, 1]))),
      (mid.path, _paint(_linear(mid.bounds, Offset.zero, const Offset(0, 1), midStops, const [0, 0.06, 0.4, 1]))),
      (s.path, _paint(_linear(s.bounds, Offset.zero, const Offset(1, 0), sStops, const [0, 0.25, 1]), 0.9)),
      (diag.path, _paint(_linear(diag.bounds, const Offset(0, 1), const Offset(1, 0), diagStops, const [0, 0.55, 1]), 0.92)),
      (low.path, _paint(_linear(low.bounds, Offset.zero, const Offset(1, 1), lowStops, const [0, 1]))),
      (
        Path()..addRect(_full),
        _paint(_radial(_full, const Offset(1, 0.8), 0.5, [violet.withValues(alpha: 0.7), violet.withValues(alpha: 0)],
            const [0, 1]))
      ),
    ];
  }();

  static Paint _paint(Shader shader, [double opacity = 1]) => Paint()
    ..shader = shader
    ..color = Color.fromRGBO(0, 0, 0, opacity);

  /// ০–১ মাপের বিন্দু [box]-এ বসানোর ম্যাট্রিক্স (SVG-র objectBoundingBox)
  static Float64List _boxMatrix(Rect box) => Float64List.fromList([
        box.width, 0, 0, 0, //
        0, box.height, 0, 0,
        0, 0, 1, 0,
        box.left, box.top, 0, 1,
      ]);

  static Shader _linear(Rect box, Offset from, Offset to, List<Color> colors, List<double> stops) =>
      ui.Gradient.linear(from, to, colors, stops, TileMode.clamp, _boxMatrix(box));

  static Shader _radial(Rect box, Offset centre, double r, List<Color> colors, List<double> stops) =>
      ui.Gradient.radial(centre, r, colors, stops, TileMode.clamp, _boxMatrix(box));

  @override
  void paint(Canvas canvas, Size size) {
    final rect = Offset.zero & size;
    final scale = math.max(size.width / viewW, size.height / viewH);
    canvas
      ..save()
      ..clipRect(rect)
      ..translate((size.width - viewW * scale) / 2, (size.height - viewH * scale) / 2)
      ..scale(scale);
    for (final (path, paint) in _fills) {
      canvas.drawPath(path, paint);
    }
    // ⭐ জ্বলা কিনারা — ঝাপসা ৫px দাগ (স্বচ্ছতা দলে, SVG-র <g opacity>-এর মতো), তার ওপর ১.৬px সরু দাগ
    final layer = _full.inflate(20);
    canvas.saveLayer(layer, Paint()..color = const Color.fromRGBO(0, 0, 0, rimGlowAlpha));
    final glow = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 5
      ..strokeCap = StrokeCap.round
      ..color = rimGlow
      ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 3.5);
    for (final p in _rims) {
      canvas.drawPath(p, glow);
    }
    canvas
      ..restore()
      ..saveLayer(layer, Paint()..color = const Color.fromRGBO(0, 0, 0, rimLineAlpha));
    final line = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.6
      ..strokeCap = StrokeCap.round
      ..color = rimLine;
    for (final p in _rims) {
      canvas.drawPath(p, line);
    }
    canvas
      ..restore()
      ..restore();
    canvas.drawRect(rect, Paint()..color = const Color.fromRGBO(0, 0, 0, scrim));
  }

  @override
  bool shouldRepaint(PurpleWavePainter old) => false;
}

/// একটা SVG পথ — Flutter-এর [Path] আর তার আঁটসাঁট বাক্স (SVG-র bbox: বাঁকের নিজের সীমা, নিয়ন্ত্রণ-বিন্দু নয়)
class _Shape {
  _Shape(double x, double y)
      : _x = x,
        _y = y {
    path.moveTo(x, y);
    _grow(x, y);
  }

  final Path path = Path();
  double _x, _y;
  double _l = double.infinity, _t = double.infinity, _r = -double.infinity, _b = -double.infinity;

  Rect get bounds => Rect.fromLTRB(_l, _t, _r, _b);

  void _grow(double x, double y) {
    _l = math.min(_l, x);
    _t = math.min(_t, y);
    _r = math.max(_r, x);
    _b = math.max(_b, y);
  }

  void line(double x, double y) {
    path.lineTo(x, y);
    _grow(x, y);
    _x = x;
    _y = y;
  }

  void cubic(double x1, double y1, double x2, double y2, double x, double y) {
    path.cubicTo(x1, y1, x2, y2, x, y);
    // ⓘ বাঁকের ওপর ঘন বিন্দু — বাক্সের ভুল এক পিক্সেলের ভগ্নাংশ
    const steps = 96;
    for (var i = 1; i <= steps; i++) {
      final t = i / steps, u = 1 - t;
      final a = u * u * u, b = 3 * u * u * t, c = 3 * u * t * t, d = t * t * t;
      _grow(a * _x + b * x1 + c * x2 + d * x, a * _y + b * y1 + c * y2 + d * y);
    }
    _x = x;
    _y = y;
  }

  void close() => path.close();
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
          painter: wallpaper.painter,
          child: const SizedBox.expand(),
        ),
      );
}

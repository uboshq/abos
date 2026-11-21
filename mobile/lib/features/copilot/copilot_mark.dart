import 'dart:io' show Platform;
import 'dart:math' as math;
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/foundation.dart' show kIsWeb, visibleForTesting;
import 'package:flutter/material.dart';

/// ⭐ কোপাইলটের চলন্ত "Ai" চিহ্ন — মালিকের বাছা নমুনা "★ সব মিলিয়ে একটায়" (২১ নভেম্বর ২০২৬):
/// খোলা কোণের গোল চারকোনা দাগ, বড় "A", ছোট "i" (খুঁটি আর সোনালি ফোঁটা), ডান-উপরে তিন তারা; সবুজ-নীল → নীল → বেগুনি
/// ঢাল (#2ef2e6 → #7aa2ff → #d36bff)। পেছনে কিছু নেই। "i"-এর ফোঁটা সোনালি (#fbbf24), হালকা আভায় (#fde68a, ~২px) —
/// মালিকের শেষ সিদ্ধান্ত।
///
/// <p>⭐ চলন — একটা ৮ সেকেন্ডের গল্প: দাগ নিজে আঁকা হয় → তারা ফোটে → "A" আর "i" লাফায়, ফোঁটা নাচে → দাগের উপর দিয়ে
/// সাদা আলো দৌড়ায় → তারা ঝিকমিক করে ভাসে → মুছে আবার শুরু। পুরো সময় ঢাল ঘোরে (৩ সেকেন্ডে এক পাক) আর চিহ্ন ধীরে
/// শ্বাস নেয় (৪ সেকেন্ড)। [busy] — Copilot উত্তর ভাবার সময় — সবকিছু দ্বিগুণ জোরে।
///
/// <p>ⓘ "কম নড়াচড়া" (disableAnimations) বা accessible navigation চালু থাকলে স্থির, পুরো আঁকা অবস্থায়। পর্দার বাইরে
/// (TickerMode বন্ধ) ঘড়ি থামে; প্রতিটা চিহ্নের নিজের একটাই ঘড়ি, নিজের RepaintBoundary-তে।
/// ⓘ পরীক্ষায় নিজে থেকে স্থির — অন্য পর্দার `pumpAndSettle` কখনো আটকায় না; চলন পরীক্ষা [animateInTests] চালু করে।
class CopilotMark extends StatefulWidget {
  const CopilotMark({super.key, this.size = 26, this.busy = false, this.animate = true});

  final double size;

  /// Copilot শুনছে বা উত্তর ভাবছে — একই চলন দ্বিগুণ জোরে
  final bool busy;

  /// false = স্থির, পুরো আঁকা — তালিকার সারিতে, যতক্ষণ সারি ফোকাস পায়নি ([row])
  final bool animate;

  /// ⭐ মেনু আর খোঁজার সারির চিহ্ন (`RowMark`) — তালিকায় সব সারি একসাথে নড়ে না: স্থির, সারি ফোকাস পেলে তবেই চলে
  /// (মালিক, ২১ নভেম্বর ২০২৬)
  static Widget row(double size) => _RowMark(size: size);

  /// ⓘ নমুনার ঢাল — সবুজ-নীল → নীল → বেগুনি
  static const colors = [Color(0xFF2EF2E6), Color(0xFF7AA2FF), Color(0xFFD36BFF)]; // dark-ok: ব্র্যান্ডের চিহ্ন, দুই মোডেই একই

  /// ⓘ "i"-এর সোনালি ফোঁটা আর তার আভা
  static const dotGold = Color(0xFFFBBF24); // dark-ok: ব্র্যান্ডের চিহ্ন
  static const dotGlow = Color(0xFFFDE68A); // dark-ok: ব্র্যান্ডের চিহ্ন

  /// পরীক্ষায় চলন চালু করে (নিজের tearDown-এ আবার false)
  @visibleForTesting
  static bool animateInTests = false;

  @override
  State<CopilotMark> createState() => _CopilotMarkState();
}

final bool _underTest = !kIsWeb && Platform.environment.containsKey('FLUTTER_TEST');

/// ⓘ ঘড়ির এক পাক = ২৪ সেকেন্ডের গল্প — ৮ (গল্প), ৩ (ঢাল) আর ৪ (শ্বাস) সবার ল.সা.গু., তাই পাক ঘোরার সময় কোনো লাফ নেই
const double _cycleSeconds = 24;

class _CopilotMarkState extends State<CopilotMark> with SingleTickerProviderStateMixin {
  late final AnimationController _clock = AnimationController(vsync: this, duration: _cycle);
  bool _still = true;

  Duration get _cycle => Duration(milliseconds: (_cycleSeconds * 1000 / (widget.busy ? 2 : 1)).round());

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _follow();
  }

  @override
  void didUpdateWidget(CopilotMark oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.busy != widget.busy || oldWidget.animate != widget.animate) _follow();
  }

  void _follow() {
    final still = !widget.animate ||
        (MediaQuery.maybeDisableAnimationsOf(context) ?? false) ||
        (MediaQuery.maybeAccessibleNavigationOf(context) ?? false) ||
        (_underTest && !CopilotMark.animateInTests);
    _still = still;
    if (still) {
      _clock.stop();
      return;
    }
    final cycle = _cycle;
    if (!_clock.isAnimating || _clock.duration != cycle) {
      _clock.duration = cycle;
      _clock.repeat(); // ⓘ যেখানে ছিল সেখান থেকেই, নতুন গতিতে
    }
  }

  @override
  void dispose() {
    _clock.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return ExcludeSemantics(
      child: RepaintBoundary(
        child: SizedBox.square(
          dimension: widget.size,
          child: CustomPaint(painter: CopilotMarkPainter(_clock, still: _still)),
        ),
      ),
    );
  }
}

/// ⓘ সারির চিহ্ন — কাছের Focus (সারির InkWell-এর) নিজে ফোকাস পেলে চলে, হারালে আবার স্থির
class _RowMark extends StatelessWidget {
  const _RowMark({required this.size});

  final double size;

  @override
  Widget build(BuildContext context) {
    final focused = Focus.maybeOf(context)?.hasPrimaryFocus ?? false;
    return CopilotMark(size: size, animate: focused);
  }
}

/// ⓘ CSS-এর মতো কী-ফ্রেম: প্রতিটা ফাঁকে নিজের ঢিমে-জোরে-ঢিমে (ease-in-out)
double _kf(double p, List<(double, double)> k, [Curve curve = Curves.easeInOut]) {
  for (var i = 0; i < k.length - 1; i++) {
    final (t0, v0) = k[i];
    final (t1, v1) = k[i + 1];
    if (p >= t0 && p <= t1) {
      if (t1 == t0) return v1;
      return v0 + (v1 - v0) * curve.transform((p - t0) / (t1 - t0));
    }
  }
  return k.last.$2;
}

double _phase(double seconds, double period, [double delay = 0]) {
  final p = ((seconds - delay) / period) % 1;
  return p < 0 ? p + 1 : p;
}

/// নমুনার `<svg viewBox="0 0 48 48">` — প্রতিটা অংশ নিজের মাপে ঢাল পায় (SVG-র objectBoundingBox-এর মতো)
class CopilotMarkPainter extends CustomPainter {
  CopilotMarkPainter(this.clock, {this.still = false}) : super(repaint: clock);

  final Animation<double> clock;
  final bool still;

  /// গল্পের কোন সেকেন্ডে (স্বাভাবিক গতির হিসাবে, ০–২৪); স্থির হলে null
  double? get storySeconds => still ? null : clock.value * _cycleSeconds;

  // ── নমুনার আকার (৪৮ ঘরে) ──
  static final Path _frame = Path()
    ..moveTo(26, 10)
    ..lineTo(16, 10)
    ..arcToPoint(const Offset(9, 17), radius: const Radius.circular(7), clockwise: false)
    ..lineTo(9, 32)
    ..arcToPoint(const Offset(16, 39), radius: const Radius.circular(7), clockwise: false)
    ..lineTo(31, 39)
    ..arcToPoint(const Offset(38, 32), radius: const Radius.circular(7), clockwise: false)
    ..lineTo(38, 24);
  static final ui.PathMetric _frameMetric = _frame.computeMetrics().first;

  static final Path _letterA = Path()
    ..fillType = PathFillType.evenOdd
    ..moveTo(14.6, 32.5)
    ..lineTo(19.6, 18)
    ..lineTo(22.6, 18)
    ..lineTo(27.6, 32.5)
    ..lineTo(24.3, 32.5)
    ..lineTo(23.2, 29.1)
    ..lineTo(17.9, 29.1)
    ..lineTo(16.8, 32.5)
    ..close()
    ..moveTo(19.8, 26.2)
    ..lineTo(23.3, 26.2)
    ..lineTo(21.55, 20.7)
    ..close();
  static final Path _stem =
      Path()..addRRect(RRect.fromRectAndRadius(const Rect.fromLTWH(29.4, 22.6, 3.2, 9.9), const Radius.circular(0.6)));
  static const Offset _dotCentre = Offset(31, 19.2);
  static const double _dotRadius = 1.9;

  /// তিন তারা — মাঝখান, ব্যাসার্ধ, দেরি (সেকেন্ড)
  static const List<(Offset, double, double)> _stars = [
    (Offset(36, 10), 5.5, 0),
    (Offset(43, 4.75), 2.75, 0.2),
    (Offset(42.5, 15.05), 3.05, 0.4),
  ];
  static final List<Path> _starPaths = [for (final (c, r, _) in _stars) _sparkle(c, r)];

  /// পুরো চিহ্নের মাঝ — শ্বাসের কেন্দ্র (দাগের রেখাসহ আর তারাসহ বাক্সের মাঝখান)
  static const Offset _markCentre = Offset(26.5, 21.4);

  /// চার-কোণা ঝিলিক — মাঝখান [c], ব্যাসার্ধ [r]
  static Path _sparkle(Offset c, double r) {
    const a = 0.0636, b = 0.6545, d = 0.345, e = 0.936;
    final top = c.translate(0, -r);
    return Path()
      ..moveTo(top.dx, top.dy)
      ..cubicTo(c.dx + a * r, c.dy - r + b * r, c.dx + d * r, c.dy - r + e * r, c.dx + r, c.dy)
      ..cubicTo(c.dx + r - b * r, c.dy + a * r, c.dx + r - e * r, c.dy + d * r, c.dx, c.dy + r)
      ..cubicTo(c.dx - a * r, c.dy + r - b * r, c.dx - d * r, c.dy + r - e * r, c.dx - r, c.dy)
      ..cubicTo(c.dx - r + b * r, c.dy - a * r, c.dx - r + e * r, c.dy - d * r, c.dx, c.dy - r)
      ..close();
  }

  /// নমুনার ঢাল (0,0)→(1,1), অংশের নিজের বাক্সে, (.5,.5)-এর চারপাশে [angle] ঘোরানো
  static Shader _shade(Rect r, double angle) {
    final c = math.cos(angle), s = math.sin(angle);
    Offset rot(double x, double y) {
      final dx = x - .5, dy = y - .5;
      return Offset(.5 + dx * c - dy * s, .5 + dx * s + dy * c);
    }

    return ui.Gradient.linear(
      rot(0, 0),
      rot(1, 1),
      CopilotMark.colors,
      const [0, .5, 1],
      TileMode.clamp,
      Float64List.fromList([r.width, 0, 0, 0, 0, r.height, 0, 0, 0, 0, 1, 0, r.left, r.top, 0, 1]),
    );
  }

  static Paint _fill(Rect r, double angle, [double opacity = 1]) => Paint()
    ..isAntiAlias = true
    ..shader = _shade(r, angle)
    ..color = Color.fromRGBO(0, 0, 0, opacity.clamp(0, 1));

  @override
  void paint(Canvas canvas, Size size) {
    final t = storySeconds;
    canvas.scale(size.width / 48, size.height / 48);

    // ── পুরো সময়: ঢাল ঘোরে, চিহ্ন শ্বাস নেয় ──
    final angle = t == null ? 0.0 : 2 * math.pi * _phase(t, 3);
    final breath = t == null ? 1.0 : _kf(_phase(t, 4), const [(0, 1), (.5, 1.07), (1, 1)]);
    final p = t == null ? null : _phase(t, 8);

    canvas.save();
    canvas.translate(_markCentre.dx, _markCentre.dy);
    canvas.scale(breath);
    canvas.translate(-_markCentre.dx, -_markCentre.dy);

    // ── ১. দাগ নিজে আঁকা হয় (০–১৫%), ৯২%-এর পরে শুরু থেকে মুছে যায় ──
    final len = _frameMetric.length;
    var from = 0.0, to = len;
    if (p != null) {
      final d = _kf(p, const [(0, 110), (.15, 0), (.92, 0), (1, -110)]);
      if (d >= 0) {
        to = math.min(len, 110 - d);
      } else {
        from = -d;
      }
    }
    if (to > from) {
      canvas.drawPath(
        _frameMetric.extractPath(from, to),
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = 3.6
          ..strokeCap = StrokeCap.round
          ..isAntiAlias = true
          ..shader = _shade(_frame.getBounds(), angle),
      );
    }

    // ── ৪. দাগের উপর দিয়ে সাদা আলো (৪৭–৬৮%) ──
    if (p != null) {
      final glow = _kf(p, const [(0, 0), (.47, 0), (.48, 1), (.68, 1), (.69, 0), (1, 0)], Curves.linear);
      if (glow > 0) {
        final d = _kf(p, const [(0, 18), (.47, 18), (.68, -92), (1, -92)], Curves.linear);
        final a = math.max(0.0, -d), b = math.min(len, 18 - d);
        if (b > a) {
          canvas.drawPath(
            _frameMetric.extractPath(a, b),
            Paint()
              ..style = PaintingStyle.stroke
              ..strokeWidth = 3.8
              ..strokeCap = StrokeCap.round
              ..isAntiAlias = true
              ..color = Colors.white.withValues(alpha: glow), // dark-ok: নমুনার সাদা আলো
          );
        }
      }
    }

    // ── ৩. "A" লাফায় (৩০–৪৫%) — নিচের মাঝখান থেকে ──
    void hop(double delay, Offset origin, void Function() draw) {
      canvas.save();
      if (t != null) {
        final q = _phase(t, 8, delay);
        final ty = _kf(q, const [(0, 0), (.30, 0), (.35, -3), (.40, 0), (.45, 0), (1, 0)]);
        final sy = _kf(q, const [(0, 1), (.30, 1), (.35, 1.1), (.40, .92), (.45, 1), (1, 1)]);
        canvas.translate(origin.dx, origin.dy + ty);
        canvas.scale(1, sy);
        canvas.translate(-origin.dx, -origin.dy);
      }
      draw();
      canvas.restore();
    }

    hop(0, const Offset(21.1, 32.5), () => canvas.drawPath(_letterA, _fill(_letterA.getBounds(), angle)));

    // ── "i" — খুঁটি আর সোনালি ফোঁটা, ০.২৫ সেকেন্ড পরে লাফায়; ফোঁটা নিজে নাচে (৪০–৫২%) ──
    hop(0.25, const Offset(31, 32.5), () {
      canvas.drawPath(_stem, _fill(_stem.getBounds(), angle));
      canvas.save();
      if (p != null) {
        final ty = _kf(p, const [(0, 0), (.40, 0), (.44, -4), (.48, 0), (.52, 0), (1, 0)]);
        final sc = _kf(p, const [(0, 1), (.40, 1), (.44, 1.2), (.48, .9), (.52, 1), (1, 1)]);
        canvas.translate(_dotCentre.dx, _dotCentre.dy + ty);
        canvas.scale(sc);
        canvas.translate(-_dotCentre.dx, -_dotCentre.dy);
      }
      // ⓘ হালকা সোনালি আভা (CSS drop-shadow(0 0 2px #fde68a) — ঝাপসা ব্যাসার্ধ ২ = sigma ১)
      canvas.drawCircle(
        _dotCentre,
        _dotRadius,
        Paint()
          ..isAntiAlias = true
          ..color = CopilotMark.dotGlow
          ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 1),
      );
      canvas.drawCircle(_dotCentre, _dotRadius, Paint()..isAntiAlias = true..color = CopilotMark.dotGold);
      canvas.restore();
    });

    // ── ২ আর ৫. তারা ফোটে (১৫–২৫%), ঝিকমিক করে ভাসে (৭০–৯২%), মিলিয়ে যায় ──
    for (var i = 0; i < _stars.length; i++) {
      final (c, _, delay) = _stars[i];
      final path = _starPaths[i];
      var scale = 1.0, rotate = 0.0, ty = 0.0, opacity = 1.0;
      if (t != null) {
        final q = _phase(t, 8, delay);
        const times = [0.0, .15, .20, .25, .70, .75, .80, .85, .92, 1.0];
        List<(double, double)> at(List<double> v) => [for (var k = 0; k < times.length; k++) (times[k], v[k])];
        scale = _kf(q, at(const [0, 0, 1.45, 1, 1, 1.3, .8, 1.25, 1, 0]));
        rotate = _kf(q, at(const [0, 0, 0, 0, 0, 45, 0, 90, 0, 0])) * math.pi / 180;
        ty = _kf(q, at(const [0, 0, 0, 0, 0, 0, -2, 0, 0, 0]));
        opacity = _kf(q, const [(0, 0), (.15, 0), (.20, 1), (.70, 1), (.80, .6), (.85, 1), (.92, 1), (1, 0)]);
      }
      if (scale <= 0.001 || opacity <= 0.001) continue;
      canvas.save();
      canvas.translate(c.dx, c.dy);
      canvas.scale(scale);
      canvas.rotate(rotate);
      canvas.translate(-c.dx, -c.dy + ty);
      canvas.drawPath(path, _fill(path.getBounds(), angle, opacity));
      canvas.restore();
    }

    canvas.restore();
  }

  @override
  bool shouldRepaint(CopilotMarkPainter oldDelegate) => oldDelegate.clock != clock || oldDelegate.still != still;
}

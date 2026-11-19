import 'dart:ui' as ui;

import 'package:flutter/material.dart';

import 'app_look.dart';

/// ⭐ Liquid Glass-এর কাচ — মালিকের নমুনা (BshQzFsr) আর ঝাপসার দাগ (BvqYK2tZ), ২০ নভেম্বর ২০২৬ "ok"।
///
/// <p>দাগের এক মান (0–100) থেকে কাচের সব গুণ: ঝাপসা, সাদার আভা, কিনারের আলো আর কোনার চকচক। 0-তে ঝাপসা নেই, আভা প্রায়
/// শূন্য — নিচের রং আর লেখা আয়নার মতো পরিষ্কার, কাচ চেনায় কেবল কিনারের আলো। 100-তে ঘষা কাচ। নমুনার পাতার একই সূত্র।
@immutable
class GlassSpec {
  const GlassSpec._(this.level, this.sigma, this.fill, this.edge, this.sheen);

  factory GlassSpec.of(int level) {
    final k = level.clamp(0, 100) / 100;
    return GlassSpec._(level, k * 20, 0.01 + k * 0.17, 0.22 + k * 0.12, 0.18 + k * 0.30);
  }

  /// এখনকার মানুষের বাছাই থেকে
  static GlassSpec get current => GlassSpec.of(AppLooks.instance.blur.value);

  final int level;

  /// ঝাপসার মাপ (Gaussian sigma) — 0 থেকে 20
  final double sigma;

  /// সাদার আভা — 0.01 থেকে 0.18
  final double fill;

  /// কিনারের রেখার উজ্জ্বলতা
  final double edge;

  /// বাঁ-উপরের কোনার চকচক
  final double sheen;
}

/// পেছনের রঙের চারটা ছোপ — কমলা-গোলাপি, নীল, সবুজ, বেগুনি; ধীরে নড়ে, তাই কাচের গুণ চোখে পড়ে।
/// ⓘ ফোনের "কম নড়াচড়া" চালু থাকলে, বা মানুষ নড়াচড়া বন্ধ রাখলে, স্থির।
class GlassBackdrop extends StatefulWidget {
  const GlassBackdrop({super.key, required this.child});

  final Widget child;

  static const Color ground = Color(0xFF0B1026);

  @override
  State<GlassBackdrop> createState() => _GlassBackdropState();
}

class _GlassBackdropState extends State<GlassBackdrop> with SingleTickerProviderStateMixin {
  late final AnimationController _drift =
      AnimationController(vsync: this, duration: const Duration(seconds: 14));

  @override
  void dispose() {
    _drift.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final still = MediaQuery.maybeDisableAnimationsOf(context) ?? false;
    return ValueListenableBuilder<bool>(
      valueListenable: AppLooks.instance.motion,
      builder: (context, moving, _) {
        if (moving && !still) {
          if (!_drift.isAnimating) _drift.repeat(reverse: true);
        } else {
          _drift.stop();
        }
        return ColoredBox(
          color: GlassBackdrop.ground,
          child: Stack(fit: StackFit.expand, children: [
            AnimatedBuilder(
              animation: _drift,
              builder: (context, _) => CustomPaint(painter: _BlobPainter(Curves.easeInOut.transform(_drift.value))),
            ),
            widget.child,
          ]),
        );
      },
    );
  }
}

class _BlobPainter extends CustomPainter {
  _BlobPainter(this.t);

  final double t;

  // ⓘ নমুনার চারটা ছোপ — ফোনের ৩০০ চওড়া পর্দার মাপকে অনুপাতে ধরে
  static const List<(double, double, double, Color, Color)> _blobs = [
    (-0.20, 0.06, 0.87, Color(0xFFFF8A3D), Color(0xFFFF2D6F)),
    (0.70, 0.33, 1.00, Color(0xFF33D1FF), Color(0xFF2A5BFF)),
    (0.03, 0.70, 0.80, Color(0xFF9DFF8A), Color(0xFF18B26B)),
    (0.60, -0.05, 0.60, Color(0xFFC86BFF), Color(0xFF6B2DFF)),
  ];

  @override
  void paint(Canvas canvas, Size size) {
    for (final (i, (x, y, d, a, b)) in _blobs.indexed) {
      // প্রতিটা ছোপের নিজের গতি — নমুনায় 12–18 সেকেন্ড
      final phase = (t * (1 + i * 0.13)) % 1;
      final dx = 40 * phase, dy = 60 * phase, scale = 1 + 0.15 * phase;
      final r = size.width * d / 2 * scale;
      final c = Offset(size.width * x + r + dx, size.height * y + r + dy);
      final paint = Paint()
        ..shader = RadialGradient(colors: [a, b, b.withValues(alpha: 0)], stops: const [0, 0.6, 0.72])
            .createShader(Rect.fromCircle(center: c, radius: r))
        ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 2);
      canvas.drawCircle(c, r, paint);
    }
  }

  @override
  bool shouldRepaint(_BlobPainter old) => old.t != t;
}

/// একটা কাচের অংশ — কার্ড, ক্যাপসুল বা গোল বোতাম। ঝাপসা মানুষের দাগ থেকে, সাথে সাথে বদলায়।
class GlassPanel extends StatelessWidget {
  const GlassPanel({super.key, required this.child, this.radius = 26, this.padding, this.circle = false});

  final Widget child;
  final double radius;
  final EdgeInsetsGeometry? padding;

  /// গোল বল (খোঁজা, ≡, ＋)
  final bool circle;

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<int>(
      valueListenable: AppLooks.instance.blur,
      builder: (context, level, _) {
        final spec = GlassSpec.of(level);
        final shape = circle ? null : BorderRadius.circular(radius);
        final inner = Container(
          padding: padding,
          decoration: BoxDecoration(
            shape: circle ? BoxShape.circle : BoxShape.rectangle,
            borderRadius: shape,
            color: Colors.white.withValues(alpha: spec.fill),
            border: Border.all(color: Colors.white.withValues(alpha: spec.edge)),
            // ⓘ কোনার চকচক আর কিনারের আলো — নমুনার `::before`-এর একই ঢাল
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              stops: const [0, 0.28, 0.55, 1],
              colors: [
                Colors.white.withValues(alpha: spec.sheen),
                Colors.white.withValues(alpha: spec.fill),
                Colors.white.withValues(alpha: spec.fill * 0.6),
                Colors.white.withValues(alpha: 0.12),
              ],
            ),
          ),
          child: DefaultTextStyle.merge(
            // ⓘ খুব স্বচ্ছ কাচেও লেখা পড়া যায় — হালকা ছায়া সবসময়
            style: const TextStyle(color: Colors.white, shadows: [Shadow(color: Color(0x59000000), blurRadius: 6)]),
            child: IconTheme.merge(data: const IconThemeData(color: Colors.white), child: child),
          ),
        );
        final clipped = circle
            ? ClipOval(child: _blur(spec, inner))
            : ClipRRect(borderRadius: shape!, child: _blur(spec, inner));
        return DecoratedBox(
          decoration: BoxDecoration(
            shape: circle ? BoxShape.circle : BoxShape.rectangle,
            borderRadius: shape,
            boxShadow: const [BoxShadow(color: Color(0x47000000), blurRadius: 30, offset: Offset(0, 12))],
          ),
          child: clipped,
        );
      },
    );
  }

  static Widget _blur(GlassSpec spec, Widget child) => spec.sigma <= 0.05
      ? child
      : BackdropFilter(filter: ui.ImageFilter.blur(sigmaX: spec.sigma, sigmaY: spec.sigma), child: child);
}

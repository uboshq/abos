import 'dart:io' show Platform;
import 'dart:math' as math;

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';

/// ⭐ কোপাইলটের চিহ্ন — মালিকের বাছা AI চিহ্ন (২০ নভেম্বর ২০২৬, বারের নমুনা; আগের মগজ "অ্যাপসের জন্য সুন্দর না"):
/// খোলা কোণের গোল চারকোনা, ভেতরে "AI", ডান-উপরে তিন ঝিলিক; সবুজ-নীল → নীল → বেগুনি ঢাল। ABOS-এর নিজের আঁকা (নমুনার
/// SVG-র ৪৮ ঘরের মাপে হুবহু), তাই যেকোনো মাপে ধারালো, কারো ছবির স্বত্বের ঝামেলা নেই।
///
/// <p>ⓘ রংগুলো ব্র্যান্ডের — আলো আর অন্ধকার দুই মোডেই একই। শোনার সময় ([listening]) একটু বড়-ছোট হয়; "কম নড়াচড়া"
/// চালু থাকলে, বা পরীক্ষায়, স্থির।
class CopilotMark extends StatefulWidget {
  const CopilotMark({super.key, this.size = 26, this.listening = false});

  final double size;

  /// শোনার সময় একটু বড়-ছোট হয় — Siri-র আলোর মতো
  final bool listening;

  /// ⓘ নমুনার ঢাল — সবুজ-নীল → নীল → বেগুনি (#14e0d6 → #3b5bdb → #9b2fd0)
  static const colors = [Color(0xFF14E0D6), Color(0xFF3B5BDB), Color(0xFF9B2FD0)]; // dark-ok: ব্র্যান্ডের চিহ্ন, দুই মোডেই একই

  @override
  State<CopilotMark> createState() => _CopilotMarkState();
}

final bool _underTest = !kIsWeb && Platform.environment.containsKey('FLUTTER_TEST');

class _CopilotMarkState extends State<CopilotMark> with SingleTickerProviderStateMixin {
  late final AnimationController _pulse = AnimationController(vsync: this, duration: const Duration(milliseconds: 1400));

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _follow();
  }

  @override
  void didUpdateWidget(CopilotMark oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.listening != widget.listening) _follow();
  }

  /// ⓘ কেবল শোনার সময় নড়ে — বারে চিহ্ন স্থির, তাই `pumpAndSettle` কখনো আটকায় না
  void _follow() {
    final still = (MediaQuery.maybeDisableAnimationsOf(context) ?? false) || _underTest || !widget.listening;
    if (still) {
      _pulse
        ..stop()
        ..value = 0;
    } else if (!_pulse.isAnimating) {
      _pulse.repeat();
    }
  }

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final s = widget.size;
    return ExcludeSemantics(
      child: SizedBox.square(
        dimension: s,
        child: AnimatedBuilder(
          animation: _pulse,
          builder: (context, child) =>
              Transform.scale(scale: 1 + 0.08 * math.sin(_pulse.value * 2 * math.pi), child: child),
          child: const CustomPaint(painter: CopilotMarkPainter()),
        ),
      ),
    );
  }
}

/// নমুনার `<symbol id="copilot" viewBox="0 0 48 48">` — প্রতিটা অংশ নিজের মাপে ঢাল পায় (SVG-র objectBoundingBox-এর মতো)
class CopilotMarkPainter extends CustomPainter {
  const CopilotMarkPainter();

  static Shader _shade(Rect r) => const LinearGradient(
        begin: Alignment.topLeft,
        end: Alignment.bottomRight,
        colors: CopilotMark.colors,
      ).createShader(r);

  /// চার-কোণা ঝিলিক — মাঝখান [c], ব্যাসার্ধ [r] (নমুনার তিনটার একই অনুপাত)
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

  @override
  void paint(Canvas canvas, Size size) {
    canvas.scale(size.width / 48, size.height / 48);
    const corner = Radius.circular(7);

    // খোলা কোণের গোল চারকোনা — ডান-উপরের কোণ খোলা, সেখানে ঝিলিক
    final frame = Path()
      ..moveTo(26, 10)
      ..lineTo(16, 10)
      ..arcToPoint(const Offset(9, 17), radius: corner, clockwise: false)
      ..lineTo(9, 32)
      ..arcToPoint(const Offset(16, 39), radius: corner, clockwise: false)
      ..lineTo(31, 39)
      ..arcToPoint(const Offset(38, 32), radius: corner, clockwise: false)
      ..lineTo(38, 24);
    canvas.drawPath(
      frame,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 3.6
        ..strokeCap = StrokeCap.round
        ..isAntiAlias = true
        ..shader = _shade(frame.getBounds()),
    );

    // "A" — ভেতরের ত্রিভুজ ফাঁকা
    final letterA = Path()
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
    // "I"
    final letterI = Path()..addRRect(RRect.fromRectAndRadius(const Rect.fromLTWH(29.4, 18, 3.2, 14.5), const Radius.circular(0.4)));
    for (final p in [
      letterA,
      letterI,
      _sparkle(const Offset(36, 10), 5.5),
      _sparkle(const Offset(43, 4.75), 2.75),
      _sparkle(const Offset(42.5, 15.05), 3.05),
    ]) {
      canvas.drawPath(p, Paint()..isAntiAlias = true..shader = _shade(p.getBounds()));
    }
  }

  @override
  bool shouldRepaint(CopilotMarkPainter oldDelegate) => false;
}

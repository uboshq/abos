import 'package:flutter/material.dart';

/// ⭐ "জমা"-র আইকন, নিচের বারে — মালিকের বাছা "ক" (২০ নভেম্বর ২০২৬, বারের নমুনা; আগেরটা "স্পষ্ট বোঝা যায় না"):
/// ভেতরে ৳ লেখা নোট, ওপর থেকে নিচে-মুখো তীর নোটে ঢুকছে — ব্যাংকে টাকা জমা দিয়ে খবর দেওয়া।
///
/// <p>ⓘ নমুনার `<symbol id="deposit" viewBox="0 0 24 24">` হুবহু, রেখা ১.৯–২ (পাশের Material Symbols-এর ওজন); রং
/// [IconTheme]-এর, তাই পাশের আইকনের সাথে একই রঙে বদলায়। [filled] — বাছা ঘরে ভরাট নোট, ৳ কেটে ফাঁকা (Material Symbols-এর
/// fill 1-এর মতো; মালিক, ২০ নভেম্বর ২০২৬ "ok": Facebook-এর মতো বাছা ঘর)।
class DepositIcon extends StatelessWidget {
  const DepositIcon({super.key, this.size, this.color, this.filled = false});

  final double? size;
  final Color? color;
  final bool filled;

  @override
  Widget build(BuildContext context) {
    final icon = IconTheme.of(context);
    final s = size ?? icon.size ?? 24;
    final ink = color ?? icon.color ?? DefaultTextStyle.of(context).style.color ?? const Color(0xFF000000); // dark-ok: IconTheme না থাকলেই কেবল
    final taka = DefaultTextStyle.of(context).style.copyWith(fontSize: 8.6, fontWeight: FontWeight.w700, color: ink, height: 1);
    return ExcludeSemantics(
      child: SizedBox.square(dimension: s, child: CustomPaint(painter: _DepositPainter(ink, taka, filled))),
    );
  }
}

class _DepositPainter extends CustomPainter {
  _DepositPainter(this.ink, this.taka, this.filled);

  final Color ink;
  final TextStyle taka;
  final bool filled;

  @override
  void paint(Canvas canvas, Size size) {
    canvas.saveLayer(Offset.zero & size, Paint());
    canvas.scale(size.width / 24, size.height / 24);
    Paint line(double w) => Paint()
      ..color = ink
      ..style = PaintingStyle.stroke
      ..strokeWidth = w
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round
      ..isAntiAlias = true;

    // নিচে-মুখো তীর
    canvas.drawPath(
      Path()
        ..moveTo(12, 1.8)
        ..lineTo(12, 8.2)
        ..moveTo(9.2, 5.6)
        ..lineTo(12, 8.4)
        ..lineTo(14.8, 5.6),
      line(2),
    );
    // নোট — ভরাট রূপে ভেতরটাও
    final note = RRect.fromRectAndRadius(const Rect.fromLTWH(2.5, 10.5, 19, 11), const Radius.circular(2.4));
    canvas.drawRRect(note, line(1.9));
    if (filled) canvas.drawRRect(note, Paint()..color = ink..isAntiAlias = true);
    // ৳ — নোটের মাঝখানে; ভরাট রূপে কেটে ফাঁকা (পেছনের বার দেখা যায়)
    final text = TextPainter(
      text: TextSpan(text: '৳', style: filled ? taka.copyWith(foreground: Paint()..blendMode = BlendMode.dstOut, color: null) : taka), // l10n-ok: the taka sign is the icon itself
      textDirection: TextDirection.ltr,
      textScaler: TextScaler.noScaling,
    )..layout();
    text.paint(canvas, Offset(12 - text.width / 2, 16 - text.height / 2));
    text.dispose();
    canvas.restore();
  }

  @override
  bool shouldRepaint(_DepositPainter old) => old.ink != ink || old.taka != taka || old.filled != filled;
}

import 'dart:math' as math;

import 'package:flutter/material.dart';

import 'role_tabs.dart';

/// ⭐ নিচের বারের রঙিন আইকন — মালিকের অনুমোদিত নমুনা ৩ (১৪ নভেম্বর ২০২৬: "ওকে, ডান, শুরু করো"):
/// হোম নীল ঘর আর সোনালি দরজা · অনুমোদন নীল কাগজে সবুজ টিক · মডিউল নীল-সবুজ-গোলাপি টালি আর সোনালি হেলানো টালি ·
/// রিপোর্ট হলুদ-নীল-সবুজ খুঁটি; একই ধাঁচে অর্ডার, আদায়, চালান, মজুদ আর ভাউচার।
///
/// ⓘ ২৪ ঘরের মাপে আঁকা (নমুনার SVG-র হুবহু), যেকোনো আকারে বড় হয়। অন্ধকার থিমে উজ্জ্বলতর রং, যাতে গাঢ় পটে পড়া যায়।
/// ⓘ রং একা অর্থ বহন করে না — বাছা ঘর পেছনের পটিতে, নাম TalkBack-এ আর লম্বা চাপে ([Tooltip])।
class ColourTabIcon extends StatelessWidget {
  const ColourTabIcon({super.key, required this.tab, this.size = 36});

  final BarTab tab;
  final double size;

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    final ring = Theme.of(context).colorScheme.surface;
    return SizedBox.square(
      dimension: size,
      child: CustomPaint(painter: _TabPainter(tab, _Ink(dark), ring)),
    );
  }
}

class _Ink {
  _Ink(bool dark)
      : blue = dark ? const Color(0xFF5B8EF0) : const Color(0xFF2F6FD6),
        navy = dark ? const Color(0xFF7C9BFF) : const Color(0xFF1D4FA8),
        gold = dark ? const Color(0xFFF0B93A) : const Color(0xFFE0A526),
        green = dark ? const Color(0xFF22C55E) : const Color(0xFF16A34A),
        pink = dark ? const Color(0xFFF472B6) : const Color(0xFFDB2777),
        amber = dark ? const Color(0xFFFBBF24) : const Color(0xFFF59E0B),
        teal = dark ? const Color(0xFF2DD4BF) : const Color(0xFF0B7F95);

  final Color blue, navy, gold, green, pink, amber, teal;
  static const Color paperLine = Color(0xFFDBEAFE);
  static const Color white = Color(0xFFFFFFFF);
}

class _TabPainter extends CustomPainter {
  _TabPainter(this.tab, this.ink, this.ring);

  final BarTab tab;
  final _Ink ink;
  final Color ring;

  Paint _fill(Color c) => Paint()..color = c..isAntiAlias = true;

  Paint _stroke(Color c, double w) => Paint()
    ..color = c
    ..style = PaintingStyle.stroke
    ..strokeWidth = w
    ..strokeCap = StrokeCap.round
    ..strokeJoin = StrokeJoin.round
    ..isAntiAlias = true;

  void _rr(Canvas c, double x, double y, double w, double h, double r, Color col) =>
      c.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(x, y, w, h), Radius.circular(r)), _fill(col));

  void _line(Canvas c, List<Offset> pts, Color col, double w) {
    final p = Path()..moveTo(pts.first.dx, pts.first.dy);
    for (final o in pts.skip(1)) {
      p.lineTo(o.dx, o.dy);
    }
    c.drawPath(p, _stroke(col, w));
  }

  /// সবুজ গোলের ভেতর সাদা টিক, চারপাশে বারের রঙের সরু দাগ (নিচের জিনিস থেকে আলাদা করে)
  void _tickBadge(Canvas c, Offset at, double r, Color col) {
    c.drawCircle(at, r, _fill(col));
    c.drawCircle(at, r, _stroke(ring, 1.5));
    _line(c, [at + const Offset(-2.2, 0), at + const Offset(-0.7, 1.5), at + const Offset(2.2, -1.5)], _Ink.white, 1.8);
  }

  @override
  void paint(Canvas canvas, Size size) {
    canvas.scale(size.width / 24, size.height / 24);
    switch (tab) {
      case BarTab.home:
        final body = Path()
          ..moveTo(5.5, 10.5)
          ..lineTo(12, 5.5)
          ..lineTo(18.5, 10.5)
          ..lineTo(18.5, 20)
          ..quadraticBezierTo(18.5, 21, 17.5, 21)
          ..lineTo(6.5, 21)
          ..quadraticBezierTo(5.5, 21, 5.5, 20)
          ..close();
        canvas.drawPath(body, _fill(ink.blue));
        _line(canvas, const [Offset(3.5, 11), Offset(12, 4), Offset(20.5, 11)], ink.navy, 2.2);
        _rr(canvas, 10, 14, 4, 7, 1, ink.gold);
      case BarTab.approvals:
        _rr(canvas, 4, 3, 14, 18, 2.5, ink.blue);
        _line(canvas, const [Offset(7.5, 8), Offset(14.5, 8)], _Ink.paperLine, 1.8);
        _line(canvas, const [Offset(7.5, 11.5), Offset(11.5, 11.5)], _Ink.paperLine, 1.8);
        _tickBadge(canvas, const Offset(17, 17), 5, ink.green);
      case BarTab.modules:
        _rr(canvas, 3, 3, 8, 8, 2.2, ink.blue);
        _rr(canvas, 3, 13, 8, 8, 2.2, ink.green);
        _rr(canvas, 13, 13, 8, 8, 2.2, ink.pink);
        canvas.save();
        canvas.translate(17, 7);
        canvas.rotate(math.pi / 4);
        _rr(canvas, -3.4, -3.4, 6.8, 6.8, 1.8, ink.gold);
        canvas.restore();
      case BarTab.reports:
        _rr(canvas, 4, 12, 4, 9, 1.3, ink.amber);
        _rr(canvas, 10, 4, 4, 17, 1.3, ink.blue);
        _rr(canvas, 16, 8, 4, 13, 1.3, ink.green);
      case BarTab.orders:
        final basket = Path()
          ..moveTo(6, 6.5)
          ..lineTo(21, 6.5)
          ..lineTo(19.3, 13.5)
          ..lineTo(7.6, 13.5)
          ..close();
        canvas.drawPath(basket, _fill(ink.blue));
        _line(canvas, const [Offset(2.5, 3.5), Offset(5, 3.5), Offset(7.6, 16), Offset(19, 16)], ink.navy, 2);
        canvas.drawCircle(const Offset(9, 19.5), 1.9, _fill(ink.gold));
        canvas.drawCircle(const Offset(17.5, 19.5), 1.9, _fill(ink.gold));
      case BarTab.collections:
        _rr(canvas, 2.5, 5.5, 18, 12, 2, ink.green);
        canvas.drawCircle(const Offset(11.5, 11.5), 3, _fill(const Color(0xFFBBF7D0)));
        canvas.drawCircle(const Offset(18.5, 17.5), 4, _fill(ink.gold));
        canvas.drawCircle(const Offset(18.5, 17.5), 4, _stroke(ring, 1.5));
      case BarTab.challans:
        _rr(canvas, 1.5, 5.5, 12.5, 10.5, 1.5, ink.blue);
        final cab = Path()
          ..moveTo(14, 8.5)
          ..lineTo(18.6, 8.5)
          ..lineTo(22, 12.2)
          ..lineTo(22, 16)
          ..lineTo(14, 16)
          ..close();
        canvas.drawPath(cab, _fill(ink.teal));
        for (final x in [6.0, 17.5]) {
          canvas.drawCircle(Offset(x, 17.5), 2.4, _fill(ink.gold));
          canvas.drawCircle(Offset(x, 17.5), 2.4, _stroke(ring, 1.4));
        }
      case BarTab.stock:
        _rr(canvas, 4, 8.5, 16, 12, 1.6, ink.amber);
        _rr(canvas, 3, 4.5, 18, 4.5, 1.3, ink.gold);
        _rr(canvas, 10.5, 4.5, 3, 9, 0.8, ink.blue);
      case BarTab.vouchers:
        _rr(canvas, 5, 3, 14, 18, 2, ink.teal);
        _line(canvas, const [Offset(8, 8), Offset(16, 8)], _Ink.white, 1.6);
        _line(canvas, const [Offset(8, 11.5), Offset(16, 11.5)], _Ink.white, 1.6);
        _line(canvas, const [Offset(8, 15), Offset(12, 15)], _Ink.white, 1.6);
        canvas.drawCircle(const Offset(17, 17.5), 3.2, _fill(ink.pink));
        canvas.drawCircle(const Offset(17, 17.5), 3.2, _stroke(ring, 1.4));
      // ⭐ জমা (0.4.58) — সবুজ নোট আর নিচে নামা তীর: টাকা ব্যাংকে গেল
      case BarTab.deposit:
        _rr(canvas, 2.5, 4, 15, 10, 2, ink.green);
        canvas.drawCircle(const Offset(10, 9), 2.6, _fill(const Color(0xFFBBF7D0)));
        _rr(canvas, 12, 12, 10, 9, 2, ink.blue);
        _line(canvas, const [Offset(17, 13.5), Offset(17, 19)], _Ink.white, 1.8);
        _line(canvas, const [Offset(14.6, 16.8), Offset(17, 19.2), Offset(19.4, 16.8)], _Ink.white, 1.8);
      case BarTab.me:
        break;
    }
  }

  @override
  bool shouldRepaint(_TabPainter old) => old.tab != tab || old.ring != ring || old.ink.blue != ink.blue;
}

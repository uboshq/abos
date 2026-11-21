import 'dart:math' as math;

import 'package:flutter/material.dart';

/// ⭐ নমুনা 3nuwRUdn v5-এর নিজের আইকন (`<symbol>`), হুবহু — SVG পথ পড়ে আঁকা, যাতে চ্যাটের পাতা নমুনার মতোই দেখায়।
///
/// <p>ⓘ ছোট SVG-পথ পাঠক: M L H V C S Q A Z (বড়/ছোট হাতের), ধারাবাহিক সংখ্যা, `.5`/`-3.3` ধরনের সংখ্যা। কোনো বাইরের প্যাকেজ নয়।
Path parseSvgPath(String d) {
  final path = Path();
  final tokens = RegExp(r'[MmLlHhVvCcSsQqAaZz]|[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?').allMatches(d).map((m) => m.group(0)!).toList();
  var i = 0;
  var cmd = 'M';
  double x = 0, y = 0, sx = 0, sy = 0;
  double? lcx, lcy; // শেষ বাঁকের নিয়ন্ত্রণ বিন্দু (S-এর জন্য)
  bool isCmd(String t) => RegExp(r'^[A-Za-z]$').hasMatch(t);
  double n() => double.parse(tokens[i++]);
  while (i < tokens.length) {
    if (isCmd(tokens[i])) {
      cmd = tokens[i++];
    }
    final rel = cmd == cmd.toLowerCase();
    switch (cmd.toUpperCase()) {
      case 'M':
        final nx = n() + (rel ? x : 0), ny = n() + (rel ? y : 0);
        path.moveTo(nx, ny);
        x = sx = nx;
        y = sy = ny;
        cmd = rel ? 'l' : 'L';
        lcx = null;
      case 'L':
        x = n() + (rel ? x : 0);
        y = n() + (rel ? y : 0);
        path.lineTo(x, y);
        lcx = null;
      case 'H':
        x = n() + (rel ? x : 0);
        path.lineTo(x, y);
        lcx = null;
      case 'V':
        y = n() + (rel ? y : 0);
        path.lineTo(x, y);
        lcx = null;
      case 'C':
        final x1 = n() + (rel ? x : 0), y1 = n() + (rel ? y : 0);
        final x2 = n() + (rel ? x : 0), y2 = n() + (rel ? y : 0);
        final ex = n() + (rel ? x : 0), ey = n() + (rel ? y : 0);
        path.cubicTo(x1, y1, x2, y2, ex, ey);
        lcx = x2;
        lcy = y2;
        x = ex;
        y = ey;
      case 'S':
        final x1 = lcx == null ? x : 2 * x - lcx;
        final y1 = lcy == null || lcx == null ? y : 2 * y - lcy;
        final x2 = n() + (rel ? x : 0), y2 = n() + (rel ? y : 0);
        final ex = n() + (rel ? x : 0), ey = n() + (rel ? y : 0);
        path.cubicTo(x1, y1, x2, y2, ex, ey);
        lcx = x2;
        lcy = y2;
        x = ex;
        y = ey;
      case 'Q':
        final x1 = n() + (rel ? x : 0), y1 = n() + (rel ? y : 0);
        final ex = n() + (rel ? x : 0), ey = n() + (rel ? y : 0);
        path.quadraticBezierTo(x1, y1, ex, ey);
        x = ex;
        y = ey;
        lcx = null;
      case 'A':
        final rx = n(), ry = n(), rot = n(), large = n() != 0, sweep = n() != 0;
        final ex = n() + (rel ? x : 0), ey = n() + (rel ? y : 0);
        path.arcToPoint(Offset(ex, ey), radius: Radius.elliptical(rx, ry), rotation: rot, largeArc: large, clockwise: sweep);
        x = ex;
        y = ey;
        lcx = null;
      case 'Z':
        path.close();
        x = sx;
        y = sy;
        lcx = null;
        // ⓘ Z-এর পরে সংখ্যা এলে সেটা নতুন রেখা
        cmd = rel ? 'l' : 'L';
        if (i < tokens.length && !isCmd(tokens[i])) continue;
      default:
        i++;
    }
  }
  return path;
}

/// একটা আইকনের এক টুকরো — রেখা বা ভরাট
@immutable
class GlyphPart {
  const GlyphPart(this.d, {this.width = 1.9, this.fill = false});

  /// বৃত্ত, SVG-পথ হিসেবে
  factory GlyphPart.circle(double cx, double cy, double r, {double width = 1.9, bool fill = false}) =>
      GlyphPart(_join(['M', cx - r, cy, 'A', r, r, 0, 1, 0, cx + r, cy, 'A', r, r, 0, 1, 0, cx - r, cy, 'Z']), width: width, fill: fill);

  /// গোল-কোণা আয়তক্ষেত্র
  factory GlyphPart.rect(double x, double y, double w, double h, {double rx = 0, double width = 1.9, bool fill = false}) => GlyphPart(
      rx == 0
          ? _join(['M', x, y, 'h', w, 'v', h, 'h', -w, 'Z'])
          : _join([
              'M', x + rx, y, 'h', w - 2 * rx, 'a', rx, rx, 0, 0, 1, rx, rx, 'v', h - 2 * rx, 'a', rx, rx, 0, 0, 1, -rx, rx, //
              'h', -(w - 2 * rx), 'a', rx, rx, 0, 0, 1, -rx, -rx, 'v', -(h - 2 * rx), 'a', rx, rx, 0, 0, 1, rx, -rx, 'Z',
            ]),
      width: width,
      fill: fill);

  static String _join(List<Object> bits) => bits.join(' ');

  final String d;
  final double width;
  final bool fill;
}

/// নমুনার একটা `<symbol>` — [view] তার viewBox-এর মাপ
@immutable
class Glyph {
  const Glyph(this.parts, {this.view = const Size(24, 24)});

  final List<GlyphPart> parts;
  final Size view;
}

/// নমুনার আইকনগুলো — নাম নমুনার `id`-র মতো
class WaGlyphs {
  const WaGlyphs._();

  static const back = Glyph([GlyphPart('M19.5 12h-15M10.5 5.5 4 12l6.5 6.5', width: 2.1)]);
  static final video = Glyph([GlyphPart.rect(2.5, 6, 13, 12, rx: 2.5), const GlyphPart('m15.5 10.2 6-3.4v10.4l-6-3.4z')]);
  static const phone = Glyph([
    GlyphPart('M5 3.5h3.5l1.5 4-2 1.5a11 11 0 0 0 7 7l1.5-2 4 1.5V19a1.5 1.5 0 0 1-1.6 1.5A16.5 16.5 0 0 1 3.5 5.1 1.5 1.5 0 0 1 5 3.5z')
  ]);
  static final kebab = Glyph([
    GlyphPart.circle(12, 5, 1.9, fill: true),
    GlyphPart.circle(12, 12, 1.9, fill: true),
    GlyphPart.circle(12, 19, 1.9, fill: true),
  ]);
  static final search = Glyph([GlyphPart.circle(11, 11, 7, width: 2), const GlyphPart('m20 20-3.5-3.5', width: 2)]);
  static final image = Glyph([
    GlyphPart.rect(3, 4.5, 18, 15, rx: 2.5),
    GlyphPart.circle(8.5, 9.5, 1.8),
    const GlyphPart('m3.5 17 5-5 4 4 3-3 5 5'),
  ]);
  static const belloff = Glyph([
    GlyphPart('M6 9a6 6 0 0 1 12 0c0 6.5 2.5 8 2.5 8h-17S6 15.5 6 9z'),
    GlyphPart('M10 20.5a2.2 2.2 0 0 0 4 0M3 3l18 18'),
  ]);
  static const bell = Glyph([
    GlyphPart('M6 9a6 6 0 0 1 12 0c0 6.5 2.5 8 2.5 8h-17S6 15.5 6 9z'),
    GlyphPart('M10 20.5a2.2 2.2 0 0 0 4 0'),
  ]);
  static final users = Glyph([
    GlyphPart.circle(9, 8, 3.5),
    const GlyphPart('M2.5 20c.8-3.5 3.4-5.5 6.5-5.5s5.7 2 6.5 5.5M16 4.8a3.5 3.5 0 0 1 0 6.4M18.5 14.8c1.6.8 2.6 2.6 3 5.2'),
  ]);
  static final calc = Glyph([
    GlyphPart.rect(5, 2.5, 14, 19, rx: 2),
    const GlyphPart('M8 6.5h8v3H8z'),
    const GlyphPart('M8.6 13.5h.01M12 13.5h.01M15.4 13.5h.01M8.6 17.2h.01M12 17.2h.01M15.4 17.2h.01', width: 2.6),
  ]);
  static final tag = Glyph([const GlyphPart('M3.5 12.5v-8a1 1 0 0 1 1-1h8l8 8-9 9z'), GlyphPart.circle(8, 8, 1.6)]);
  static final truck = Glyph([
    const GlyphPart('M2.5 6.5h11v10h-11zM13.5 10h4l3 3.5v3h-7'),
    GlyphPart.circle(7, 18, 1.8),
    GlyphPart.circle(17, 18, 1.8),
  ]);
  static final smile = Glyph([
    GlyphPart.circle(12, 12, 9, width: 1.8),
    const GlyphPart('M8 14.2a4.6 4.6 0 0 0 8 0', width: 1.8),
    GlyphPart.circle(9, 9.8, 1.2, fill: true),
    GlyphPart.circle(15, 9.8, 1.2, fill: true),
  ]);
  static const clip = Glyph([GlyphPart('m20 11.5-8.2 8.2a5 5 0 0 1-7.1-7.1l8.5-8.5a3.3 3.3 0 0 1 4.7 4.7l-8.5 8.5a1.7 1.7 0 0 1-2.4-2.4L15 7')]);
  static final camera = Glyph([
    const GlyphPart('M4 8h3l1.6-2.4h6.8L17 8h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z'),
    GlyphPart.circle(12, 13.5, 3.6),
  ]);
  static final micf = Glyph([
    GlyphPart.rect(8.5, 2, 7, 12.5, rx: 3.5, fill: true),
    const GlyphPart('M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21', width: 2),
  ]);
  static const send = Glyph([GlyphPart('M3.5 11.2 20.5 4l-6.8 16.5-2.9-6.8z'), GlyphPart('m10.8 13.7 9.7-9.7')]);
  static const doc = Glyph([GlyphPart('M6 3h8l5 5v12a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z'), GlyphPart('M14 3v5h5M8.5 13h7M8.5 16.5h5')]);
  static const paper = Glyph([
    GlyphPart('M6 3h8l5 5v12a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z'),
    GlyphPart('M14 3v5h5M8.5 12.5h7M8.5 16h4.5'),
    GlyphPart('M9.5 3v5.5a1.5 1.5 0 0 1-3 0V5', width: 1.6),
  ]);
  static final pin = Glyph([const GlyphPart('M12 21s-6.5-6-6.5-11a6.5 6.5 0 0 1 13 0c0 5-6.5 11-6.5 11z'), GlyphPart.circle(12, 10, 2.4)]);
  static const play = Glyph([GlyphPart('M7 4.5v15l12.5-7.5z', fill: true)]);

  /// ⓘ নমুনায় নেই (নমুনা কেবল থামা অবস্থা আঁকে) — চলার সময়ের চিহ্ন, play-এর একই ওজনে
  static final pause = Glyph([GlyphPart.rect(6.5, 4.5, 4, 15, rx: 1, fill: true), GlyphPart.rect(13.5, 4.5, 4, 15, rx: 1, fill: true)]);
  static const download = Glyph([GlyphPart('M12 4v11M7.5 10.5 12 15l4.5-4.5M5 19.5h14', width: 2)]);
  static final lock = Glyph([GlyphPart.rect(4.5, 10.5, 15, 10.5, rx: 2), const GlyphPart('M8 10.5V7.5a4 4 0 0 1 8 0v3')]);
  static final clock = Glyph([GlyphPart.circle(12, 12, 9), const GlyphPart('M12 7.5V12l3 2')]);
  static const x = Glyph([GlyphPart('M6 6l12 12M18 6 6 18', width: 2.1)]);

  /// ৳-এর জমা চিহ্ন (নমুনার `deposit`) — ৳ লেখাটা [GlyphIcon.label] দিয়ে
  static final deposit = Glyph([
    const GlyphPart('M12 1.8v6.4M9.2 5.6 12 8.4l2.8-2.8', width: 2),
    GlyphPart.rect(2.5, 10.5, 19, 11, rx: 2.4),
  ]);
  static const tk1 = Glyph([GlyphPart('m3.5 6.5 3 3 6.5-7.5', width: 1.8)], view: Size(18, 12));
  static const tk2 = Glyph([GlyphPart('m1 6.5 3 3L10.5 2M8 9.5 14.5 2M6.3 7.8l1.7 1.7', width: 1.8)], view: Size(18, 12));

  // ⭐ নমুনা v7 — বার্তা চেপে ধরলে, ইমোজি, বলে লেখা
  static const reply = Glyph([GlyphPart('M10 6 4 12l6 6M4.5 12H14a6 6 0 0 1 6 6v1', width: 2)]);
  static const fwd = Glyph([GlyphPart('m14 6 6 6-6 6M19.5 12H10a6 6 0 0 0-6 6v1', width: 2)]);
  static const trash = Glyph([GlyphPart('M4 6.5h16M9.5 6.5V4.5h5v2M6 6.5l1 13a1.5 1.5 0 0 0 1.5 1.4h7a1.5 1.5 0 0 0 1.5-1.4l1-13M10 10.5v6M14 10.5v6')]);
  static final info = Glyph([GlyphPart.circle(12, 12, 9), const GlyphPart('M12 11v5.5'), GlyphPart.circle(12, 7.8, 1.2, fill: true)]);
  static final copy = Glyph([
    GlyphPart.rect(8.5, 8.5, 12, 12, rx: 2),
    const GlyphPart('M15.5 8.5V5a1.5 1.5 0 0 0-1.5-1.5H5A1.5 1.5 0 0 0 3.5 5v9A1.5 1.5 0 0 0 5 15.5h3.5'),
  ]);
  static final kbd = Glyph([
    GlyphPart.rect(2.5, 6, 19, 12, rx: 2, width: 1.8),
    const GlyphPart('M6 9.5h.01M9.3 9.5h.01M12.6 9.5h.01M15.9 9.5h.01M18 9.5h.01M6 12.5h.01M9.3 12.5h.01M12.6 12.5h.01M15.9 12.5h.01M18 12.5h.01M8 15.2h8', width: 2),
  ]);
  static final vtype = Glyph([
    GlyphPart.rect(9.6, 1.8, 4.8, 8.2, rx: 2.4, fill: true),
    const GlyphPart('M7.2 7.2a4.8 4.8 0 0 0 9.6 0M12 12v1.6', width: 1.7),
    GlyphPart.rect(3, 15, 18, 7, rx: 1.6, width: 1.6),
    const GlyphPart('M6.2 17.6h.01M9.4 17.6h.01M12.6 17.6h.01M15.8 17.6h.01M8 19.8h8', width: 1.7),
  ]);
  static const heart = Glyph([GlyphPart('M12 20s-7.5-4.6-7.5-10A4.3 4.3 0 0 1 12 7.2 4.3 4.3 0 0 1 19.5 10c0 5.4-7.5 10-7.5 10z')]);
  static const leaf = Glyph([GlyphPart('M5 19c0-9 5.5-14 15-14 0 9.5-5.5 14-15 14zM5 19l8-8')]);
  static const cup = Glyph([GlyphPart('M4.5 9h12v5.5a5 5 0 0 1-5 5h-2a5 5 0 0 1-5-5zM16.5 10.5h1.5a2.5 2.5 0 0 1 0 5h-1.7M8 3.5v2.5M12 3.5v2.5')]);
  static const flag = Glyph([GlyphPart('M5 21V4M5 4.5h11.5l-2.5 4 2.5 4H5')]);

  /// গ্রুপের চিহ্ন — calc · tag · truck · users (অচেনা হলে users)
  static Glyph group(String name) => switch (name) {
        'calc' => calc,
        'tag' => tag,
        'truck' => truck,
        _ => users,
      };
}

/// ⭐ নমুনার একটা আইকন, [color]-এ (না দিলে চারপাশের IconTheme-এর রং)
class GlyphIcon extends StatelessWidget {
  const GlyphIcon(this.glyph, {super.key, this.size = 24, this.height, this.color, this.label});

  final Glyph glyph;
  final double size;

  /// viewBox চৌকো না হলে (টিক 18×12) উচ্চতা আলাদা
  final double? height;
  final Color? color;

  /// ভেতরে লেখা (নমুনার `deposit`-এর ৳)
  final String? label;

  @override
  Widget build(BuildContext context) {
    final c = color ?? IconTheme.of(context).color ?? DefaultTextStyle.of(context).style.color!;
    return ExcludeSemantics(
      child: CustomPaint(size: Size(size, height ?? size * glyph.view.height / glyph.view.width), painter: GlyphPainter(glyph, c, label: label)),
    );
  }
}

class GlyphPainter extends CustomPainter {
  GlyphPainter(this.glyph, this.color, {this.label});

  final Glyph glyph;
  final Color color;
  final String? label;

  static final Map<String, Path> _cache = {};

  static Path pathOf(String d) => _cache.putIfAbsent(d, () => parseSvgPath(d));

  @override
  void paint(Canvas canvas, Size size) {
    final s = math.min(size.width / glyph.view.width, size.height / glyph.view.height);
    canvas.save();
    canvas.translate((size.width - glyph.view.width * s) / 2, (size.height - glyph.view.height * s) / 2);
    canvas.scale(s);
    for (final p in glyph.parts) {
      final paint = Paint()
        ..color = color
        ..isAntiAlias = true;
      if (p.fill) {
        paint.style = PaintingStyle.fill;
      } else {
        paint
          ..style = PaintingStyle.stroke
          ..strokeWidth = p.width
          ..strokeCap = StrokeCap.round
          ..strokeJoin = StrokeJoin.round;
      }
      canvas.drawPath(pathOf(p.d), paint);
    }
    final l = label;
    if (l != null) {
      final tp = TextPainter(
        text: TextSpan(text: l, style: TextStyle(fontSize: 8.6, fontWeight: FontWeight.w700, color: color, height: 1)),
        textDirection: TextDirection.ltr,
      )..layout();
      tp.paint(canvas, Offset(12 - tp.width / 2, 19.6 - tp.height * .82));
    }
    canvas.restore();
  }

  @override
  bool shouldRepaint(GlyphPainter old) => old.glyph != glyph || old.color != color || old.label != label;
}

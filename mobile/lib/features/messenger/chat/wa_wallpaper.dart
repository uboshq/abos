import 'package:flutter/material.dart';

import 'wa_glyphs.dart';

/// ⭐ চ্যাটের নিজের পটের নকশা — রসিদ, পয়সা, বাক্স, ট্রাক, ক্যালকুলেটর, পাতা (নমুনা 3nuwRUdn v5-এর `<pattern id="doodle">`, হুবহু)।
///
/// <p>১৮০×১৮০-এর টালি, রেখা 1.7, গোল মাথা; `--wa-wall`-এর ওপর `--wa-doodle` রঙে। পট নড়ে না — বার্তা তার ওপর দিয়ে চলে।
class WaWallpaper extends StatelessWidget {
  const WaWallpaper({super.key, required this.wall, required this.doodle});

  final Color wall;
  final Color doodle;

  @override
  Widget build(BuildContext context) => ExcludeSemantics(
        child: RepaintBoundary(child: CustomPaint(painter: DoodlePainter(wall: wall, doodle: doodle), child: const SizedBox.expand())),
      );
}

class DoodlePainter extends CustomPainter {
  DoodlePainter({required this.wall, required this.doodle});

  final Color wall;
  final Color doodle;

  static const double tile = 180;

  static const List<String> _strokes = [
    'M14 12h20v26l-3.3-2.2-3.3 2.2-3.4-2.2-3.3 2.2-3.4-2.2L14 38z',
    'M18.5 20h11M18.5 25.5h11M18.5 31h6',
    'M78 24A10 10 0 1 0 58 24A10 10 0 1 0 78 24Z',
    'M74.5 24A6.5 6.5 0 1 0 61.5 24A6.5 6.5 0 1 0 74.5 24Z',
    'M108 16l13-6.5 13 6.5v14l-13 6.5-13-6.5z',
    'M108 16l13 6.5 13-6.5M121 22.5v14',
    'm160 16 2.2 4.6 5 .7-3.6 3.5.9 5-4.5-2.4-4.5 2.4.9-5-3.6-3.5 5-.7z',
    'M12 74h20v13H12zM32 78h7l4.5 4.5V87H32',
    'M20.6 89.5A2.6 2.6 0 1 0 15.4 89.5A2.6 2.6 0 1 0 20.6 89.5Z',
    'M39.6 89.5A2.6 2.6 0 1 0 34.4 89.5A2.6 2.6 0 1 0 39.6 89.5Z',
    'M66 66h26a4.5 4.5 0 0 1 4.5 4.5v10A4.5 4.5 0 0 1 92 85H77l-7 5.5V85h-4a4.5 4.5 0 0 1-4.5-4.5v-10A4.5 4.5 0 0 1 66 66z',
    'M70 74h16M70 79h10',
    'M120 96c0-15 10.5-24 26-24 0 15-10.5 24-26 24z',
    'M120 96l15-13',
    'M163 70h10a3 3 0 0 1 3 3v16a3 3 0 0 1-3 3h-10a3 3 0 0 1-3-3v-16a3 3 0 0 1 3-3z',
    'M163.5 74h9v4.5h-9z',
    'M14 130l16-7-5.5 16-3.5-5.5z',
    'M21 133.5 30 123',
    'M82 134A10 10 0 1 0 62 134A10 10 0 1 0 82 134Z',
    'M72 128.5v5.5l3.5 2.2',
    'M124 136c-3.2-4.2-9.5-2.2-9.5 3.2 0 4.4 9.5 9.6 9.5 9.6s9.5-5.2 9.5-9.6c0-5.4-6.3-7.4-9.5-3.2z',
    'M150 150c4-4 8 4 12 0s8 4 12 0',
    'M40 160h22v12H40zM45 160v-4h12v4',
    'M96 158l4 8 8 1.2-6 5.6 1.5 8',
  ];

  static const List<Offset> _dots = [Offset(48, 52), Offset(148, 48), Offset(98, 112), Offset(170, 122), Offset(6, 166)];

  static final Path _tile = () {
    final p = Path();
    for (final d in _strokes) {
      p.addPath(GlyphPainter.pathOf(d), Offset.zero);
    }
    return p;
  }();

  @override
  void paint(Canvas canvas, Size size) {
    canvas.drawRect(Offset.zero & size, Paint()..color = wall);
    final line = Paint()
      ..color = doodle
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.7
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round
      ..isAntiAlias = true;
    final dot = Paint()..color = doodle;
    canvas.save();
    canvas.clipRect(Offset.zero & size);
    for (var y = 0.0; y < size.height; y += tile) {
      for (var x = 0.0; x < size.width; x += tile) {
        canvas.save();
        canvas.translate(x, y);
        canvas.drawPath(_tile, line);
        for (final o in _dots) {
          canvas.drawCircle(o, 2, dot);
        }
        canvas.restore();
      }
    }
    canvas.restore();
  }

  @override
  bool shouldRepaint(DoodlePainter old) => old.wall != wall || old.doodle != doodle;
}

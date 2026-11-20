import 'dart:math' as math;

import 'package:flutter/material.dart';

/// ⭐ Material 3 Expressive — মালিকের নমুনা (BshQzFsr, ২০ নভেম্বর ২০২৬ "ok"; NEXUS নমুনা ২-এর "খ")।
/// রং আর আকার নমুনার হুবহু: বেগুনি মূল রং, গাঢ় ভাসমান টুলবার, গোলাপি চারকোনা ＋, চার আকারের ঘর।
class Expressive {
  const Expressive._();

  static const Color background = Color(0xFFFDF7FF);
  static const Color ink = Color(0xFF1D1B20);
  static const Color inkMuted = Color(0xFF625B71);
  static const Color primary = Color(0xFF6750A4);
  static const Color primaryDeep = Color(0xFF4F378B);
  static const Color secondaryBox = Color(0xFFE8DEF8);
  static const Color surfaceBox = Color(0xFFF3EDF7);
  static const Color toolbar = Color(0xFF2B2930);
  static const Color toolbarInk = Color(0xFFE6E0E9);

  /// টুলবারের না-বাছা আইকন — মলিন ধূসর (Material 3 dark onSurfaceVariant)
  static const Color toolbarMuted = Color(0xFFCAC4D0);

  /// উপরের বারের ＋ তৈরি করুন — গোলাপি গোল-চারকোনা (বারের নমুনা, ২০ নভেম্বর ২০২৬)
  static const Color create = Color(0xFFFFD8E4);
  static const Color onCreate = Color(0xFF31111D);

  /// চার ঘরের রং আর আকার — পাতা, উল্টো পাতা, ক্যাপসুল, গোল কোনা
  static const List<(Color, Color, BorderRadius)> tiles = [
    (Color(0xFFFFD8E4), Color(0xFF31111D), BorderRadius.only(
        topLeft: Radius.circular(48), topRight: Radius.circular(16), bottomRight: Radius.circular(48), bottomLeft: Radius.circular(16))),
    (Color(0xFFC4EED0), Color(0xFF072711), BorderRadius.only(
        topLeft: Radius.circular(16), topRight: Radius.circular(48), bottomRight: Radius.circular(16), bottomLeft: Radius.circular(48))),
    (Color(0xFFFFDCBE), Color(0xFF2C1600), BorderRadius.all(Radius.circular(999))),
    (Color(0xFFD3E3FD), Color(0xFF041E49), BorderRadius.all(Radius.circular(28))),
  ];

  /// মোটা, চওড়া অঙ্ক
  static const TextStyle bigNumber = TextStyle(fontWeight: FontWeight.w900, letterSpacing: -0.5, height: 1.05);

  /// মূল কার্ডের আকার — তিন কোনা গোল, বাঁ-নিচ সরু
  static const BorderRadius heroShape = BorderRadius.only(
      topLeft: Radius.circular(36), topRight: Radius.circular(36), bottomRight: Radius.circular(36), bottomLeft: Radius.circular(8));
}

/// লক্ষ্যের ঢেউ-রেখা — যতটা পূরণ ততটা ঢেউ, বাকিটা সোজা ম্লান দাগ
class ExpressiveWave extends StatelessWidget {
  const ExpressiveWave({super.key, required this.progress, this.color = Colors.white});

  /// 0–1
  final double progress;
  final Color color;

  @override
  Widget build(BuildContext context) =>
      SizedBox(height: 14, child: CustomPaint(painter: _WavePainter(progress.clamp(0, 1), color), size: Size.infinite));
}

class _WavePainter extends CustomPainter {
  _WavePainter(this.progress, this.color);

  final double progress;
  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final mid = size.height / 2;
    final end = size.width * progress;
    final wave = Path()..moveTo(0, mid);
    for (double x = 0; x <= end; x += 1) {
      wave.lineTo(x, mid + math.sin(x / 15 * math.pi) * (mid - 2));
    }
    canvas.drawPath(
        wave,
        Paint()
          ..color = color
          ..strokeWidth = 4
          ..style = PaintingStyle.stroke
          ..strokeCap = StrokeCap.round);
    if (end + 10 < size.width) {
      canvas.drawLine(
          Offset(end + 10, mid),
          Offset(size.width, mid),
          Paint()
            ..color = color.withValues(alpha: 0.35)
            ..strokeWidth = 4
            ..strokeCap = StrokeCap.round);
    }
  }

  @override
  bool shouldRepaint(_WavePainter old) => old.progress != progress || old.color != color;
}

/// একসাথে জোড়া বোতামের দল — দুই প্রান্ত গোল, মাঝেরগুলো সরু কোনা, বাছাটা ভরা বেগুনি
class ExpressiveButtonGroup extends StatelessWidget {
  const ExpressiveButtonGroup({super.key, required this.labels, required this.selected, required this.onSelect});

  final List<String> labels;
  final int selected;
  final ValueChanged<int> onSelect;

  @override
  Widget build(BuildContext context) {
    return Row(children: [
      for (final (i, label) in labels.indexed) ...[
        if (i > 0) const SizedBox(width: 4),
        Expanded(
          child: Material(
            color: i == selected ? Expressive.primary : Expressive.secondaryBox,
            borderRadius: BorderRadius.horizontal(
              left: Radius.circular(i == 0 ? 22 : 8),
              right: Radius.circular(i == labels.length - 1 ? 22 : 8),
            ),
            child: InkWell(
              onTap: () => onSelect(i),
              borderRadius: BorderRadius.circular(22),
              child: SizedBox(
                height: 44,
                child: Center(
                  child: Text(label,
                      style: TextStyle(
                          fontWeight: FontWeight.w600, color: i == selected ? Colors.white : const Color(0xFF1D192B))),
                ),
              ),
            ),
          ),
        ),
      ],
    ]);
  }
}

/// ভাসমান গাঢ় টুলবার — বাছা আইকন চওড়া বেগুনি বড়িতে
class ExpressiveToolbar extends StatelessWidget {
  const ExpressiveToolbar({super.key, required this.items, required this.selected, required this.onSelect});

  final List<(IconData, String)> items;
  final int selected;
  final ValueChanged<int> onSelect;

  @override
  Widget build(BuildContext context) {
    return Container(
      height: 64,
      padding: const EdgeInsets.symmetric(horizontal: 6),
      decoration: const BoxDecoration(
        color: Expressive.toolbar,
        borderRadius: BorderRadius.all(Radius.circular(32)),
        boxShadow: [BoxShadow(color: Color(0x40000000), blurRadius: 20, offset: Offset(0, 8))],
      ),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        for (final (i, (icon, label)) in items.indexed)
          Tooltip(
            message: label,
            child: Semantics(
              button: true,
              selected: i == selected,
              label: label,
              child: InkWell(
                onTap: () => onSelect(i),
                borderRadius: BorderRadius.circular(22),
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 180),
                  width: i == selected ? 64 : 48,
                  height: 44,
                  margin: const EdgeInsets.symmetric(horizontal: 2),
                  decoration: BoxDecoration(
                    color: i == selected ? Expressive.primaryDeep : Colors.transparent,
                    borderRadius: BorderRadius.circular(22),
                  ),
                  child: Icon(icon, size: 22, color: i == selected ? Colors.white : Expressive.toolbarInk),
                ),
              ),
            ),
          ),
      ]),
    );
  }
}

/// বড় চারকোনা ＋ — নমুনার গোলাপি 72×72, কোনা 24
class ExpressiveFab extends StatelessWidget {
  const ExpressiveFab({super.key, required this.onPressed, required this.label, this.icon = Icons.add});

  final VoidCallback onPressed;
  final String label;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: label,
      child: Material(
        color: const Color(0xFFFFD8E4),
        elevation: 6,
        shadowColor: const Color(0x33000000),
        borderRadius: BorderRadius.circular(24),
        child: InkWell(
          onTap: onPressed,
          borderRadius: BorderRadius.circular(24),
          child: SizedBox(width: 72, height: 72, child: Icon(icon, size: 32, color: const Color(0xFF31111D))),
        ),
      ),
    );
  }
}

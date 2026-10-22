import 'package:flutter/material.dart';

/// ⭐ টাকার অঙ্ক সারির লেখার চেয়ে ছোট হয় না, ফোনের লেখার মাপের সাথে বাড়ে, অঙ্ক সমান চওড়া (tabular), আর মাঝখানে কখনো
/// ভাঙে না (WCAG 1.4.4; fe, ২২ অক্টোবর ২০২৬)। ⛔ আগে সারির ডানের ঘরে FittedBox(scaleDown) অঙ্ক অর্ধেক মাপে ছোট করত, আর
/// ListTile-এর ডানের ঘর নিজে ১১-র লেখা দিত — মালিক মোট আর বকেয়া পড়তে পারতেন না।
///
/// <p>নিয়ম: অঙ্ক নিজের মাপের ঘর পায়, কথার ঘর বাকিটা (Expanded)। অঙ্ক সারির [share]-এর বেশি চাইলে ([fitsBeside] মিথ্যা)
/// সারি ছোট করে না — ভেঙে দুই তলা হয়: ওপরে কথা, নিচে ডানে অঙ্ক, পুরো মাপে।
abstract final class Amounts {
  static const List<FontFeature> tabular = [FontFeature.tabularFigures()];

  /// সারির মূল অঙ্ক — bodyLarge-এর মাপ (১৬), মোটা। ⓘ কেবল মাপ নেয়, থিমের অক্ষরের ফাঁক নয় — সারির বাকি লেখার মতোই আঁকা
  static TextStyle main(BuildContext context, {Color? color, double? size}) => TextStyle(
      fontSize: size ?? Theme.of(context).textTheme.bodyLarge?.fontSize ?? 16,
      fontWeight: FontWeight.w700,
      color: color,
      fontFeatures: tabular);

  /// অঙ্কের নিচের লাইন (জের, বাকির দিন) — bodyMedium-এর মাপ (১৪), সারির দ্বিতীয় লাইনের সমান
  static TextStyle second(BuildContext context, {Color? color}) => TextStyle(
      fontSize: Theme.of(context).textTheme.bodyMedium?.fontSize ?? 14, color: color, fontFeatures: tabular);

  /// এই লাইনগুলোর সবচেয়ে চওড়াটা, ফোনের লেখার মাপে, এক লাইনে
  static double widthOf(BuildContext context, Iterable<(String, TextStyle)> lines) {
    final base = DefaultTextStyle.of(context).style;
    final scaler = MediaQuery.textScalerOf(context);
    final direction = Directionality.of(context);
    var widest = 0.0;
    for (final (text, style) in lines) {
      final painter = TextPainter(
        text: TextSpan(text: text, style: base.merge(style)),
        textDirection: direction,
        textScaler: scaler,
        maxLines: 1,
      )..layout();
      if (painter.width > widest) widest = painter.width;
      painter.dispose();
    }
    return widest;
  }

  /// অঙ্কগুলো সারির ([rowWidth]) [share]-এর মধ্যে ধরে কি না — না ধরলে সারি দুই তলা হয়
  static bool fitsBeside(BuildContext context, double rowWidth, Iterable<(String, TextStyle)> lines, {double share = 0.4}) =>
      widthOf(context, lines) <= rowWidth * share;
}

/// অঙ্কের লেখা — কখনো ছোট হয় না, কাটাও পড়ে না। সারি [Amounts.fitsBeside] মেপে এমন জায়গা দেয় যাতে এক লাইনে ধরে; তবু
/// না ধরলে (খুব সরু পর্দা) কেবল ফাঁকে মোড়ে — "বকেয়া" আর "৳১২,৪৮,১২৫.৫০"-এর মাঝে, অঙ্কের ভিতরে নয় (৳, অঙ্ক আর কমা
/// লাইন-ভাঙার নিয়মে একসাথে থাকে)।
class AmountText extends StatelessWidget {
  const AmountText(this.text, {super.key, required this.style, this.semanticsLabel, this.textAlign = TextAlign.end});

  final String text;
  final TextStyle style;
  final String? semanticsLabel;
  final TextAlign textAlign;

  @override
  Widget build(BuildContext context) => Text(text,
      semanticsLabel: semanticsLabel, textAlign: textAlign, style: style);
}

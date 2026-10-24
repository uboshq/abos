import 'package:flutter/material.dart';

/// ⭐ গ্রাহকের লাইন — "নাম · পয়েন্ট · শাখা", এক লাইনে (মালিক, ১৯ অক্টোবর ২০২৬, স্থায়ী নিয়ম: *"গ্রাহকের নামের পাশে পয়েন্ট দিতে হবে
/// অবশ্যই, সব জায়গায়, আর শাখার নামও দিতে হবে।"*)।
///
/// <p>লেখাটা আসে [withPoint] থেকে (`customer_record.dart`); এই উইজেট কেবল দেখায়: লম্বা হলে "…"-এ কাটে। ⭐ কাটা নাম কখনো
/// হারায় না (fe, ২৪ অক্টোবর ২০২৬, ০.৪.৪৮): TalkBack পুরো লেখা পড়ে, আর ছুঁয়ে ধরে রাখলে [Tooltip]-এ পুরোটা। [full]: যে সারি
/// নামটা ভেঙে দেখায় (যেমন প্রথম লাইনে "নম্বর · নাম", জায়গা নিচে), সে পুরো "নাম · পয়েন্ট · শাখা"-সহ লেখা এখানে দেয় — টুলটিপ
/// আর TalkBack সেটাই বলে। ⓘ ফাঁকা লেখায় টুলটিপ নেই।
class CustomerLine extends StatelessWidget {
  const CustomerLine(this.text, {super.key, this.style, this.full});

  final String text;
  final TextStyle? style;

  /// পুরো লেখা, যদি [text] তার একটা অংশ হয়; null হলে [text]-ই পুরো
  final String? full;

  @override
  Widget build(BuildContext context) {
    final line = Text(text, style: style, maxLines: 1, overflow: TextOverflow.ellipsis, softWrap: false, semanticsLabel: full);
    return text.trim().isEmpty ? line : Tooltip(message: full ?? text, child: line);
  }
}

/// ⭐ "নম্বর · নাম" এক লাইনে — কাগজের নম্বর (RCV-0193, SO-…, DO-…) কখনো কাটা পড়ে না, ভাঙেও না: নিজের লেখা, নিজের পুরো
/// চওড়া; কাটা যায় কেবল নাম ([CustomerLine], "…", পুরোটা TalkBack আর টুলটিপে) — fe, ২৪ অক্টোবর ২০২৬, ০.৪.৪৮। ⛔ আগে দুটো এক
/// লেখায় ছিল, পুরোটা একসাথে "…"-এ কাটত — সরু পর্দায় বা লম্বা নম্বরে কাট নম্বরের ভেতরে পড়তে পারত।
class NumberAndName extends StatelessWidget {
  const NumberAndName({super.key, required this.number, required this.name, this.full, this.style});

  final String number;
  final String name;

  /// নামের পুরো লেখা ([CustomerLine.full])
  final String? full;
  final TextStyle? style;

  @override
  Widget build(BuildContext context) {
    if (number.isEmpty) return CustomerLine(name, full: full, style: style);
    final numberText = Text(number, key: const ValueKey('row-number'), softWrap: false, style: style);
    if (name.isEmpty) return numberText;
    return LayoutBuilder(builder: (context, box) {
      // ⓘ নম্বর, " · " আর নামের অন্তত দুই অক্ষর এক লাইনে না ধরলে (সরু সারি, ২০০% অক্ষর) নম্বর নিজের লাইনে, নাম নিচে
      final painter = TextPainter(
          text: TextSpan(text: '$number · ${name.characters.take(2)}…', style: DefaultTextStyle.of(context).style.merge(style)),
          textDirection: Directionality.of(context),
          textScaler: MediaQuery.textScalerOf(context),
          maxLines: 1)
        ..layout();
      final fits = painter.width <= box.maxWidth;
      painter.dispose();
      if (!fits) {
        return Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          numberText,
          CustomerLine(name, full: full, style: style),
        ]);
      }
      return Row(children: [
        numberText,
        Text(' · ', softWrap: false, style: style),
        Flexible(child: CustomerLine(name, full: full, style: style)),
      ]);
    });
  }
}

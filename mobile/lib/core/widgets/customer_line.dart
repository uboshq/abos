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

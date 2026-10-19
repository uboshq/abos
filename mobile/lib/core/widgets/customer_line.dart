import 'package:flutter/material.dart';

/// ⭐ গ্রাহকের লাইন — "নাম · পয়েন্ট · শাখা", এক লাইনে (মালিক, ১৯ অক্টোবর ২০২৬, স্থায়ী নিয়ম: *"গ্রাহকের নামের পাশে পয়েন্ট দিতে হবে
/// অবশ্যই, সব জায়গায়, আর শাখার নামও দিতে হবে।"*)।
///
/// <p>লেখাটা আসে [withPoint] থেকে (`customer_record.dart`); এই উইজেট কেবল দেখায়: লম্বা হলে "…"-এ কাটে, পুরোটা ছুঁয়ে ধরে রাখলে
/// ([Tooltip])। ⓘ ফাঁকা লেখায় টুলটিপ নেই।
class CustomerLine extends StatelessWidget {
  const CustomerLine(this.text, {super.key, this.style});

  final String text;
  final TextStyle? style;

  @override
  Widget build(BuildContext context) {
    final line = Text(text, style: style, maxLines: 1, overflow: TextOverflow.ellipsis, softWrap: false);
    return text.trim().isEmpty ? line : Tooltip(message: text, child: line);
  }
}

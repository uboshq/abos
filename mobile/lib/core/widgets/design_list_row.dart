import 'package:flutter/material.dart';

import '../records/money.dart';
import 'customer_line.dart';
import 'row_icon.dart';

/// ⭐ নকশা ৩-এর তালিকার সারি (নকশার নিয়ম ০.৪.৩৮ §৪, a4, ১৯ অক্টোবর ২০২৬):
///
/// ```
/// [RowIcon 40dp]  নাম · পয়েন্ট · শাখা (16 w600)        ৳48,500.00 (15 w700)
///                 নম্বর · তারিখ/ধরন (13, onSurfaceVariant)      [চিপ]
/// ```
/// পুরো সারিতে চাপলে খোলে; সারি অন্তত ৭২dp; বড় অক্ষরে (২০০%) নিজের মাপে বাড়ে — ডানের টাকা সারির ৪৫%-এর বেশি নেয় না,
/// লম্বা হলে মোড়ে, ছাপিয়ে ভাঙে না। রং কেবল `colorScheme` থেকে।
/// ⓘ a5 বানিয়েছে আদায়, বকেয়া, কাউন্টারের খসড়া আর ফেরতের বিলের জন্য; ২০ অক্টোবর ২০২৬ থেকে a4-এর সাধারণ widget — বদল লাগলে a4-কে বলো।
class DesignListRow extends StatelessWidget {
  const DesignListRow({
    super.key,
    required this.icon,
    this.tone,
    required this.name,
    required this.second,
    this.amount,
    this.amountColor,
    this.pill,
    this.onTap,
    this.below,
    this.secondKey,
  });

  final IconData icon;
  final Color? tone;

  /// গ্রাহক হলে "নাম · পয়েন্ট · শাখা" (`CustomerRecord.label` বা সার্ভারের `withPoint` লেখা)
  final String name;

  /// দ্বিতীয় লাইন — কাগজের নম্বর আগে
  final String second;
  /// টাকা নেই এমন সারিতে (যেমন ট্র্যাকিং) null — ডানে তখন কেবল চিপ
  final num? amount;
  final Color? amountColor;

  /// দ্বিতীয় লাইনের নিচে আরেক লাইন — যেমন DO-র ওয়েবের চিপ (ব্যাক অর্ডার …)
  final Widget? below;

  /// দ্বিতীয় লাইনের লেখার key — টেস্ট "নম্বর দ্বিতীয় লাইনে" ধরতে পারে
  final Key? secondKey;
  final Widget? pill;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return Card(
      child: InkWell(
        onTap: onTap,
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 72),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
            child: LayoutBuilder(
              builder: (context, box) => Row(children: [
                RowIcon(icon, tone: tone),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      CustomerLine(name,
                          style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600, color: cs.onSurface)),
                      const SizedBox(height: 2),
                      Text(second, key: secondKey, style: TextStyle(fontSize: 13, color: cs.onSurfaceVariant)),
                      if (below != null) ...[const SizedBox(height: 4), below!],
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                ConstrainedBox(
                  constraints: BoxConstraints(maxWidth: box.maxWidth * 0.45),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      if (amount != null)
                        Text(Money.taka(amount!),
                            textAlign: TextAlign.end,
                            style: TextStyle(fontSize: 15, fontWeight: FontWeight.w700, color: amountColor ?? cs.onSurface)),
                      if (pill != null) ...[if (amount != null) const SizedBox(height: 4), pill!],
                    ],
                  ),
                ),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}

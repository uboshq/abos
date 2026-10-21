import 'package:flutter/material.dart';

import '../../core/theme/app_palette.dart';

/// ⭐ প্রত্যাখ্যানের আগে শেষ প্রশ্ন — "চূড়ান্ত, আর ফেরানো যাবে না" (fe, ২১ অক্টোবর ২০২৬; ওয়েবের মতো দুই আলাদা কাজ)।
/// সংশোধনে ফেরত লেখককে ঠিক করার সুযোগ দেয়; প্রত্যাখ্যান কাগজটা শেষ করে দেয়। ০.৪.৪২ পর্যন্ত "সংশোধনে ফেরত দিন"
/// বোতামই ভুল করে প্রত্যাখ্যান করত — তাই প্রত্যাখ্যান এখন আলাদা বোতাম, আর এই ধাপ ছাড়া কিছু যায় না।
/// `true` কেবল "হ্যাঁ, প্রত্যাখ্যান" চাপলে।
Future<bool> confirmFinalReject(BuildContext context, {required String what}) async {
  final yes = await showDialog<bool>(
    context: context,
    builder: (context) => AlertDialog(
      key: const ValueKey('reject-final'),
      icon: Icon(Icons.block, color: context.palette.danger),
      title: const Text('চূড়ান্ত প্রত্যাখ্যান?'),
      content: Text('$what\n\nএটা চূড়ান্ত — আর ফেরানো যাবে না। লেখককে ঠিক করার সুযোগ দিতে চাইলে "সংশোধনে ফেরত দিন" বেছে নিন।'),
      actions: [
        TextButton(
          key: const ValueKey('reject-final-cancel'),
          onPressed: () => Navigator.of(context).pop(false),
          child: const Text('থাক'),
        ),
        FilledButton(
          key: const ValueKey('reject-final-confirm'),
          style: FilledButton.styleFrom(backgroundColor: context.palette.danger),
          onPressed: () => Navigator.of(context).pop(true),
          child: const Text('হ্যাঁ, প্রত্যাখ্যান'),
        ),
      ],
    ),
  );
  return yes ?? false;
}

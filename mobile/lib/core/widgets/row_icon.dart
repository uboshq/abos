import 'package:flutter/material.dart';

import '../theme/app_colors.dart';

/// ⭐ তালিকার সারির বাঁয়ের আইকন — ৪০dp গোল, হালকা পটে সেই রঙেরই গাঢ় সুর, অন্তত ৩:১ (WCAG 2.1 1.4.11; মোবাইলের নকশা ৩,
/// পাতা ২ আর ৩)। [module] দিলে মডিউলের রং, [tone] দিলে সেই রং (যেমন আদায় সবুজ, খরচ ধূসর); কিছু না দিলে অ্যাপের মূল রং।
///
/// <p>শুধু চোখের জন্য — স্ক্রিন-রিডার সারির লেখাই পড়ে, আইকন নয়।
class RowIcon extends StatelessWidget {
  const RowIcon(this.icon, {super.key, this.module, this.tone});

  final IconData icon;
  final String? module;
  final Color? tone;

  /// (পট, আইকন)
  (Color, Color) get colours {
    if (module != null) return (AppColors.moduleTint(module!), AppColors.moduleInk(module!));
    final base = tone ?? AppColors.primary;
    final tint = Color.alphaBlend(base.withValues(alpha: 0.14), const Color(0xFFFFFFFF));
    return (tint, AppColors.deepen(base, tint, 3));
  }

  @override
  Widget build(BuildContext context) {
    final (bg, ink) = colours;
    return ExcludeSemantics(
      child: Container(
        width: 40,
        height: 40,
        decoration: BoxDecoration(color: bg, shape: BoxShape.circle),
        child: Icon(icon, size: 22, color: ink),
      ),
    );
  }
}

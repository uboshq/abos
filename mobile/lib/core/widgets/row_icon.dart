import 'package:flutter/material.dart';

import '../theme/app_colors.dart';
import '../theme/app_look.dart';
import '../theme/expressive.dart';
import '../theme/look_widgets.dart';

/// ⭐ সারির আইকনের জায়গায় নিজের আঁকা চিহ্ন — [size] মাপে (যেমন Copilot-এর চলন্ত "Ai", [CopilotMark.row])।
/// ⓘ তালিকায় চিহ্ন স্থির থাকে, সারি ফোকাস পেলে তবেই চলে — চিহ্ন নিজে তা দেখে।
typedef RowMark = Widget Function(double size);

/// ⭐ তালিকার সারির বাঁয়ের আইকন — ৪০dp গোল, হালকা পটে সেই রঙেরই গাঢ় সুর, অন্তত ৩:১ (WCAG 2.1 1.4.11; মোবাইলের নকশা ৩,
/// পাতা ২ আর ৩)। [module] দিলে মডিউলের রং, [tone] দিলে সেই রং (যেমন আদায় সবুজ, খরচ ধূসর); কিছু না দিলে অ্যাপের মূল রং।
///
/// <p>শুধু চোখের জন্য — স্ক্রিন-রিডার সারির লেখাই পড়ে, আইকন নয়।
class RowIcon extends StatelessWidget {
  const RowIcon(this.icon, {super.key, this.module, this.tone, this.mark});

  final IconData icon;

  /// দিলে [icon]-এর বদলে এই চিহ্ন, একই গোল পটে (মালিক, ২১ নভেম্বর ২০২৬: মেনু আর খোঁজায় Copilot-এর "Ai")
  final RowMark? mark;
  final String? module;
  final Color? tone;

  /// (পট, আইকন) — আলোতে
  (Color, Color) get colours => coloursOn(Brightness.light);

  /// (পট, আইকন) — অন্ধকারে পট অন্ধকার পটের উপর রঙের ছোঁয়া, আইকন সেই রঙেরই হালকা সুর; দুই মোডেই অন্তত ৩:১
  /// (a5-এর অনুরোধ: অন্ধকারে আগে হালকা পট থেকে যেত)।
  (Color, Color) coloursOn(Brightness brightness) {
    final base = module != null ? AppColors.moduleColor(module!) : (tone ?? AppColors.primary);
    if (brightness == Brightness.dark) {
      final tint = Color.alphaBlend(base.withValues(alpha: 0.24), darkPage);
      return (tint, _lighten(base, tint, 3));
    }
    if (module != null) return (AppColors.moduleTint(module!), AppColors.moduleInk(module!));
    final tint = Color.alphaBlend(base.withValues(alpha: 0.14), const Color(0xFFFFFFFF));
    return (tint, AppColors.deepen(base, tint, 3));
  }

  /// অন্ধকার থিমের কার্ডের মোটামুটি রং — ছোঁয়ার ভিত্তি
  static const Color darkPage = Color(0xFF1B2023);

  static Color _lighten(Color c, Color background, double target) {
    var hsl = HSLColor.fromColor(c);
    while (AppColors.contrast(hsl.toColor(), background) < target && hsl.lightness < 0.98) {
      hsl = hsl.withLightness((hsl.lightness + 0.01).clamp(0.0, 1.0));
    }
    return hsl.toColor();
  }

  /// ⭐ রূপের রং (০.৪.৬০): Liquid Glass-এ সেই রঙের স্বচ্ছ ছোঁয়া আর হালকা আইকন (কাচের উপর); Material 3-এ মূল-রঙের আইকন
  /// বেগুনি সুরের পটে (মডিউল বা অবস্থার রং থাকলে আলোর রংই)। ABOS-এ [coloursOn] — আগের মতো।
  (Color, Color) coloursFor(AppLook look, Brightness brightness) {
    final base = module != null ? AppColors.moduleColor(module!) : (tone ?? AppColors.primary);
    return switch (look) {
      AppLook.glass => (base.withValues(alpha: 0.32), _lighten(base, darkPage, 6)),
      AppLook.expressive when module == null && tone == null => (Expressive.secondaryBox, Expressive.primaryDeep),
      _ => coloursOn(brightness),
    };
  }

  @override
  Widget build(BuildContext context) {
    final (bg, ink) = coloursFor(context.look, Theme.of(context).brightness);
    return ExcludeSemantics(
      child: Container(
        width: 40,
        height: 40,
        decoration: BoxDecoration(color: bg, shape: BoxShape.circle),
        alignment: Alignment.center,
        child: mark?.call(26) ?? Icon(icon, size: 22, color: ink),
      ),
    );
  }
}

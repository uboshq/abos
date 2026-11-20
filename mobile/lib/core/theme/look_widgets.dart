import 'package:flutter/material.dart';

import 'app_look.dart';
import 'expressive.dart';
import 'glass.dart';

/// ⭐ এই পর্দার রূপ (০.৪.৬০) — থিমের উপর নির্ভর রাখে, তাই রূপ বদলালে (থিম বদলায়) widget নতুন করে আঁকা হয়।
/// ⓘ ABOS-এ সব widget আগের মতো — রূপ-নির্ভর শাখা কেবল [AppLook.glass] আর [AppLook.expressive]-এ।
extension LookOf on BuildContext {
  AppLook get look {
    Theme.of(this);
    return AppLooks.instance.look.value;
  }

  /// ABOS-এর বাঁধা মূল রং ([abos], যেমন `AppColors.primary`) — নতুন দুই রূপে থিমের মূল রং (কাচে হালকা নীল, Material 3-এ বেগুনি)
  Color lookAccent(Color abos) => look == AppLook.abos ? abos : Theme.of(this).colorScheme.primary;
}

/// ⭐ পর্দার অংশ রূপ ধরে (0.4.58; মালিকের নমুনা, ২০ নভেম্বর ২০২৬ "ok") — ABOS-এ কিছুই যোগ হয় না (একটুও বদলায় না), Liquid
/// Glass-এ কাচের প্যানেল, Material 3-এ গোল কোনার হালকা বেগুনি বাক্স।
class LookSurface extends StatelessWidget {
  const LookSurface({super.key, required this.child, this.padding = const EdgeInsets.all(12)});

  final Widget child;
  final EdgeInsetsGeometry padding;

  @override
  Widget build(BuildContext context) {
    Theme.of(context); // রূপ বদলালে থিম বদলায় — এটাও নতুন করে আঁকা হয়
    return switch (AppLooks.instance.look.value) {
      AppLook.abos => child,
      AppLook.glass => GlassPanel(padding: padding, child: child),
      AppLook.expressive => Container(
          padding: padding,
          decoration: const BoxDecoration(color: Expressive.surfaceBox, borderRadius: BorderRadius.all(Radius.circular(28))),
          child: Material(type: MaterialType.transparency, child: child),
        ),
    };
  }
}

/// ⭐ তালিকার সারির দল — ABOS-এ সারিগুলো যেমন ছিল তেমনই (আলাদা কার্ড); Liquid Glass-এ এক কাচের প্যানেলে, মাঝে সরু রেখা
/// (নমুনার `.list.glass`); Material 3-এ জোড়া কার্ড — প্রথমটার উপরের কোনা বড় গোল, শেষটার নিচের, মাঝেরগুলো সরু
/// (নমুনার `.it`)। ⓘ সারি নিজে [Card] আঁকে; এখানে কেবল কার্ডের থিম বদলায়, সারির কাজ বা লেখা নয়।
class LookGroup extends StatelessWidget {
  const LookGroup({super.key, required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    switch (AppLooks.instance.look.value) {
      case AppLook.abos:
        return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: children);
      case AppLook.glass:
        if (children.isEmpty) return const SizedBox.shrink();
        final flat = theme.copyWith(
          cardTheme: theme.cardTheme.copyWith(
            color: Colors.transparent,
            elevation: 0,
            margin: EdgeInsets.zero,
            shape: const RoundedRectangleBorder(),
          ),
        );
        return GlassPanel(
          radius: 26,
          child: Theme(
            data: flat,
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              for (final (i, child) in children.indexed) ...[
                if (i > 0) Divider(height: 1, thickness: 1, color: Colors.white.withValues(alpha: 0.14)),
                child,
              ],
            ]),
          ),
        );
      case AppLook.expressive:
        final last = children.length - 1;
        return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          for (final (i, child) in children.indexed)
            Padding(
              padding: EdgeInsets.only(bottom: i == last ? 0 : 4),
              child: Theme(
                data: theme.copyWith(
                  cardTheme: theme.cardTheme.copyWith(
                    color: Expressive.surfaceBox,
                    elevation: 0,
                    margin: EdgeInsets.zero,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.vertical(
                        top: Radius.circular(i == 0 ? 28 : 8),
                        bottom: Radius.circular(i == last ? 28 : 8),
                      ),
                    ),
                  ),
                ),
                child: child,
              ),
            ),
        ]);
    }
  }
}

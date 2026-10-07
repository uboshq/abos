import 'package:flutter/material.dart';

import '../theme/app_colors.dart';

enum AppStatusKind { success, warning, danger, pending, neutral }

/// Colour as meaning: the same four colours mean the same four things on
/// every screen — a screen with its own bespoke coloured chip is exactly the
/// drift this widget exists to prevent.
class AppStatusPill extends StatelessWidget {
  const AppStatusPill({super.key, required this.label, required this.kind});

  final String label;
  final AppStatusKind kind;

  @override
  Widget build(BuildContext context) {
    final (Color bg, Color fg) = switch (kind) {
      AppStatusKind.success => (AppColors.successSurface, AppColors.success),
      AppStatusKind.warning => (AppColors.warningSurface, AppColors.warning),
      AppStatusKind.danger => (AppColors.dangerSurface, AppColors.danger),
      AppStatusKind.pending => (AppColors.pendingSurface, AppColors.pending),
      AppStatusKind.neutral => (AppColors.surfaceMuted, AppColors.onSurfaceMuted),
    };

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        label,
        style: TextStyle(color: fg, fontSize: 12, fontWeight: FontWeight.w600),
      ),
    );
  }
}

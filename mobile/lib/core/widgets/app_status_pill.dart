import 'package:flutter/material.dart';

import '../theme/app_palette.dart';

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
      AppStatusKind.success => (context.palette.successSurface, context.palette.success),
      AppStatusKind.warning => (context.palette.warningSurface, context.palette.warning),
      AppStatusKind.danger => (context.palette.dangerSurface, context.palette.danger),
      AppStatusKind.pending => (context.palette.pendingSurface, context.palette.pending),
      AppStatusKind.neutral => (context.palette.surfaceMuted, context.palette.onSurfaceMuted),
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

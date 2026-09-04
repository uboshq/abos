/// Spacing tokens — an 8-point scale, named rather than typed as raw numbers
/// so a screen reads `AppSpacing.md` instead of a bare `16.0` nobody can tell
/// apart from a one-off measurement.
class AppSpacing {
  const AppSpacing._();

  static const double xs = 4;
  static const double sm = 8;
  static const double md = 16;
  static const double lg = 24;
  static const double xl = 32;
  static const double xxl = 48;
}

part of '../me_page.dart';

/// ⭐ রূপের রং — নমুনার CSS-এর `--ink`, `--mut`, `--card`, `--cardB`, `--acc`, `--soft`, `--softInk`, `--ring` (`.m3`, `.lg`, `.cl`)
@immutable
class _Tok {
  const _Tok({
    required this.look,
    required this.ink,
    required this.mut,
    required this.card,
    required this.cardB,
    required this.acc,
    required this.accInk,
    required this.soft,
    required this.softInk,
    required this.ring,
    required this.danger,
  });

  final AppLook look;
  final Color ink;
  final Color mut;
  final Color card;
  final Color cardB;
  final Color acc;
  final Color accInk;
  final Color soft;
  final Color softInk;
  final Color ring;
  final Color danger;

  bool get smart => look == AppLook.expressive;
  bool get glass => look == AppLook.glass;
  bool get classic => look == AppLook.abos;

  static const Color _white = Color(0xFFFFFFFF);

  factory _Tok.of(BuildContext context, AppLook look) {
    switch (look) {
      case AppLook.expressive:
        return const _Tok(
          look: AppLook.expressive,
          ink: Expressive.ink,
          mut: Expressive.inkMuted,
          card: Expressive.surfaceBox,
          cardB: Color(0xFFE7E0EC),
          acc: Expressive.primary,
          accInk: _white,
          soft: Expressive.secondaryBox,
          softInk: Color(0xFF1D192B),
          ring: Expressive.background,
          danger: Color(0xFFB3261E),
        );
      case AppLook.glass:
        return const _Tok(
          look: AppLook.glass,
          ink: _white,
          mut: Color(0xB8FFFFFF),
          card: Color(0x17FFFFFF),
          cardB: Color(0x3DFFFFFF),
          acc: Color(0xFF8B5CF6),
          accInk: _white,
          soft: Color(0x21FFFFFF),
          softInk: _white,
          ring: Color(0x8CFFFFFF),
          danger: Color(0xFFFFB3C1),
        );
      case AppLook.abos:
        final theme = Theme.of(context);
        if (theme.brightness == Brightness.dark) {
          // ⓘ ক্লাসিক অন্ধকারে — নমুনা কেবল আলোর; থিমের নিজের রং, যাতে সাদা কার্ড কালো পটে না ফোটে
          final cs = theme.colorScheme;
          final p = context.palette;
          return _Tok(
            look: AppLook.abos,
            ink: cs.onSurface,
            mut: p.onSurfaceMuted,
            card: p.surface,
            cardB: p.border,
            acc: cs.primary,
            accInk: cs.onPrimary,
            soft: p.primarySoft,
            softInk: cs.onSurface,
            ring: theme.scaffoldBackgroundColor,
            danger: cs.error,
          );
        }
        return const _Tok(
          look: AppLook.abos,
          ink: Color(0xFF1D2D3E),
          mut: Color(0xFF5B6B7D),
          card: _white,
          cardB: Color(0xFFE1E6EC),
          acc: Color(0xFF1D4ED8),
          accInk: _white,
          soft: Color(0xFFE7EEFB),
          softInk: Color(0xFF163A7A),
          ring: Color(0xFFF1F4F7),
          danger: Color(0xFFE11D48),
        );
    }
  }

  /// `.sec`-এর কোনা — স্মার্ট 24, গ্লাস 18, ক্লাসিক 10
  double get secRadius => smart ? 24 : (glass ? 18 : 10);

  /// `.stat` · `.btn` · `.seg`-এর কোনা
  double get statRadius => classic ? 8 : 14;
}

/// "#RRGGBB" → রং; ভুল হলে null
Color? _hex(String? s) {
  if (s == null || !RegExp(r'^#[0-9a-fA-F]{6}$').hasMatch(s)) return null;
  return Color(0xFF000000 | int.parse(s.substring(1), radix: 16));
}

/// সার্ভারের চিহ্নের নাম → চিহ্ন (tag · approve · doc · cart · chart · deposit · undo)
Widget _glyph(String name, {double size = 16, Color color = const Color(0xFFFFFFFF)}) => switch (name) {
      'deposit' => DepositIcon(size: size, color: color),
      'tag' => Icon(Symbols.sell, size: size, color: color),
      'approve' => Icon(Symbols.check_circle, size: size, color: color),
      'cart' => Icon(Symbols.shopping_cart, size: size, color: color),
      'chart' => Icon(Symbols.bar_chart, size: size, color: color),
      'undo' => Icon(Symbols.undo, size: size, color: color),
      _ => Icon(Symbols.description, size: size, color: color),
    };

/// মাসের সংখ্যার রং — সার্ভার না দিলে ধরন ধরে
Color _statTone(MeStat s) =>
    _hex(s.tone) ??
    switch (s.kind) {
      'sales' => const Color(0xFF16A34A),
      'approvals' => const Color(0xFFE11D48),
      'orders' => const Color(0xFFEA580C),
      'target' => const Color(0xFF4F46E5),
      'deposits' => const Color(0xFF7C3AED),
      _ => const Color(0xFF2563EB),
    };

String _statIcon(MeStat s) => s.icon.isNotEmpty
    ? s.icon
    : switch (s.kind) {
        'sales' => 'tag',
        'approvals' => 'approve',
        'orders' => 'cart',
        'target' => 'chart',
        'deposits' => 'deposit',
        _ => 'doc',
      };

/// শেষ কাজের চিহ্ন আর রং — আগে সার্ভারের `verb` (অনুমোদন, ফেরত, বাতিল …), না মিললে কাগজের `kind`; নমুনার মতো: টাকা প্রাপ্তি
/// সবুজ, জার্নাল নীল, বিক্রয় আদেশ কমলা, ফেরত লাল
(String, Color) _recentLook(MeRecent r) => switch (r.verb) {
      'returned_to_draft' => ('undo', const Color(0xFFE11D48)),
      'rejected' || 'cancelled' => ('undo', const Color(0xFFE11D48)),
      'reopened' => ('undo', const Color(0xFFEA580C)),
      _ => _kindLook(r),
    };

(String, Color) _kindLook(MeRecent r) => switch (r.kind) {
      'receipt' || 'deposit' => ('deposit', const Color(0xFF16A34A)),
      'sales_order' || 'sales' => ('tag', const Color(0xFFEA580C)),
      'returned' => ('undo', const Color(0xFFE11D48)),
      'approval' => ('approve', const Color(0xFFE11D48)),
      _ => ('doc', const Color(0xFF2563EB)),
    };

/// "2 ঘণ্টা আগে" · "গতকাল" · "2 দিন আগে" — অঙ্ক ইংরেজিতে
String _ago(AppL10n l, DateTime at, DateTime now) {
  final day = DateTime(now.year, now.month, now.day);
  final atDay = DateTime(at.year, at.month, at.day);
  final days = day.difference(atDay).inDays;
  if (days >= 2) return l.syncAgoDays(days, '$days');
  if (days == 1) return l.mePageYesterday;
  final d = now.difference(at);
  if (d.inMinutes < 1) return l.syncAgoJustNow;
  if (d.inHours < 1) return l.syncAgoMinutes(d.inMinutes, '${d.inMinutes}');
  return l.syncAgoHours(d.inHours, '${d.inHours}');
}

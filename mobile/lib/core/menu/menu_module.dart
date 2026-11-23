/// One row inside a `/me` menu group — see [MenuModule]'s own doc comment
/// for the shape this comes from.
class MenuRouteEntry {
  const MenuRouteEntry({
    required this.label,
    required this.route,
    required this.planned,
    this.web,
    this.module,
  });

  final String label;

  /// A server-side route **name**, e.g. `sales.invoice.index` — never a URL.
  /// See [RouteRegistry]'s own doc comment for why this app resolves it
  /// itself rather than navigating to whatever string arrives.
  final String route;

  /// True means "coming soon" — the web side shows the row dimmed and
  /// unclickable rather than omitting it, and this app follows the same
  /// convention (see [MenuRouteEntry] usage in home_shell.dart).
  final bool planned;

  /// ⭐ The in-app web path (`/…`) for this row, or null — the server sends it only where this person may open the
  /// row's web screen inside the app (owner, 13 Oct 2026; [WebPaths]). An older server sends nothing, and then the
  /// row stays what it was: native or absent.
  final String? web;

  /// ⭐ The row's own module — inside a merged module (হিসাব ও অর্থ, বিক্রয়, ক্রয়, যোগাযোগ, সিস্টেম ও প্রশাসন; web
  /// 20 and 23 Nov 2026) a guest's row says its own code (`finance`) while it stands in the host's module (`accounts`).
  /// Null from an older server, which never merged.
  final String? module;

  factory MenuRouteEntry.fromJson(Map<String, dynamic> json) => MenuRouteEntry(
        label: json['label'] as String? ?? '',
        route: json['route'] as String? ?? '',
        planned: json['planned'] as bool? ?? false,
        web: switch (json['web']) {
          final String path when path.startsWith('/') && !path.startsWith('//') => path,
          _ => null,
        },
        module: switch (json['module']) {
          final String code when code.trim().isNotEmpty => code.trim(),
          _ => null,
        },
      );
}

/// One top-level module from `GET /me`'s `menu` array (e.g. "বিক্রয়"),
/// containing named groups of rows ("transactions", ...).
///
/// <p>The server sends every row it has, permission-filtered already — but
/// **not** filtered by what this particular app build can actually open. A
/// row naming a route this app has no screen for yet is real and correct on
/// the server's side; showing it anyway would be a menu tile that does
/// nothing when tapped. [ApiMenuRepository] is where that second filter
/// happens — see its own doc comment.
class MenuModule {
  const MenuModule({
    required this.code,
    required this.label,
    required this.section,
    required this.order,
    required this.groups,
  });

  final String code;
  final String label;
  final String section;
  final int order;
  final Map<String, List<MenuRouteEntry>> groups;

  factory MenuModule.fromJson(Map<String, dynamic> json) => MenuModule(
        code: json['code'] as String? ?? '',
        label: json['label'] as String? ?? '',
        section: json['section'] as String? ?? '',
        order: (json['order'] as num?)?.toInt() ?? 0,
        groups: ((json['groups'] as Map?) ?? const {}).map(
          (key, value) => MapEntry(
            key.toString(),
            ((value as List?) ?? const [])
                .map((e) => MenuRouteEntry.fromJson(e as Map<String, dynamic>))
                .toList(),
          ),
        ),
      );

  /// Every row in this module, groups flattened — the home screen shows one
  /// flat grid today rather than the web's own module/group hierarchy (there
  /// is no room for three navigation levels on a phone this early); a
  /// grouped drill-down is a later, deliberate design step, not an oversight.
  List<MenuRouteEntry> get allEntries =>
      groups.values.expand((entries) => entries).toList(growable: false);
}

/// ⭐ What `/me` says about the merged modules (owner, 20 and 23 Nov 2026): which guest stands inside which host, and
/// the host's merged name ("হিসাব ও অর্থ", "যোগাযোগ", "সিস্টেম ও প্রশাসন"). The server merges a guest into its host
/// only while both are there for this person, and renames the host only while a guest sits inside — so a module here
/// has a merged name exactly when one of its rows names another module.
///
/// <p>ⓘ An older server sends no row module: both maps stay empty, and the app falls back to its own names.
class ServerModules {
  const ServerModules({this.mergedLabels = const {}, this.hosts = const {}});

  /// Host code → the server's merged name for it.
  final Map<String, String> mergedLabels;

  /// Guest code → host code, as the server folded them.
  final Map<String, String> hosts;

  bool get isEmpty => mergedLabels.isEmpty && hosts.isEmpty;

  factory ServerModules.of(List<MenuModule> menu) {
    final labels = <String, String>{};
    final hosts = <String, String>{};
    for (final module in menu) {
      final code = module.code.trim();
      if (code.isEmpty) continue;
      for (final entry in module.allEntries) {
        final own = entry.module;
        if (own == null || own == code) continue;
        hosts[own] = code;
        if (module.label.trim().isNotEmpty) labels[code] = module.label.trim();
      }
    }
    return ServerModules(mergedLabels: labels, hosts: hosts);
  }
}

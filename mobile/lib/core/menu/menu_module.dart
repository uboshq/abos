/// One row inside a `/me` menu group — see [MenuModule]'s own doc comment
/// for the shape this comes from.
class MenuRouteEntry {
  const MenuRouteEntry({
    required this.label,
    required this.route,
    required this.planned,
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

  factory MenuRouteEntry.fromJson(Map<String, dynamic> json) => MenuRouteEntry(
        label: json['label'] as String? ?? '',
        route: json['route'] as String? ?? '',
        planned: json['planned'] as bool? ?? false,
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

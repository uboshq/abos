import 'package:flutter/material.dart';

/// One tile in the role-based home menu — built from `GET /me`'s `menu` tree
/// via [ApiMenuRepository], filtered to what [RouteRegistry] can actually
/// open. See that repository's own doc comment for the two filters a row
/// passes through before it becomes one of these.
class MenuItem {
  const MenuItem({
    required this.key,
    required this.label,
    required this.icon,
    required this.routeName,
    this.planned = false,
  });

  final String key;
  final String label;
  final IconData icon;

  /// The `/home/<routeName>` path segment — see app_router.dart.
  final String routeName;

  /// True means the server itself calls this "coming soon" — shown dimmed
  /// and unclickable rather than omitted, matching the web side's own
  /// convention (see the `/me` briefing's point ৪).
  final bool planned;
}

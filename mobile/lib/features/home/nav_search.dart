import 'package:flutter/material.dart';

import '../../core/navigation/nav_catalog.dart';
import '../../core/records/customer_record.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';

/// One customer the search found — from the customers already synced to this
/// phone, never a server call.
class CustomerHit {
  const CustomerHit({required this.id, required this.label, this.detail});

  final String id;
  final String label;
  final String? detail;
}

/// What a search result opens: `/home/<path>`, remembered as [label].
class NavPick {
  const NavPick(this.path, this.label);

  final String path;
  final String label;
}

/// The synced customers matching [query] — the same match the customer list's
/// own search box uses ([CustomerRecord.matches]). Wrapped: a cache that is
/// not open yet finds nobody rather than breaking the search.
List<CustomerHit> findSyncedCustomers(String query) {
  if (query.trim().isEmpty) return const [];
  try {
    return CustomerRecord.all()
        .where((c) => c.isActive && c.matches(query))
        .take(8)
        .map((c) => CustomerHit(id: c.id, label: c.label, detail: c.phone ?? c.code))
        .toList();
  } catch (_) {
    return const [];
  }
}

/// The current name of a synced customer — null when this phone (this
/// company's cache) does not know them, and then a recent line about them is
/// not shown: the cache is the tenant boundary.
String? syncedCustomerName(String id) {
  try {
    return CustomerRecord.byId(id)?.label;
  } catch (_) {
    return null;
  }
}

/// ⭐ খোঁজার বাক্স — হোমের আর সব মডিউলের মাথায় (মালিকের নমুনা)। চাপলে খোঁজার পাতা খোলে।
class NavSearchBox extends StatelessWidget {
  const NavSearchBox({super.key, required this.onTap});

  final VoidCallback onTap;

  static const String hint = 'খুঁজুন: "চালান", "রহিম ট্রেডার্স"…';

  @override
  Widget build(BuildContext context) {
    return Material(
      color: AppColors.surface,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(24),
        side: const BorderSide(color: AppColors.border),
      ),
      child: InkWell(
        key: const ValueKey('nav-search'),
        borderRadius: BorderRadius.circular(24),
        onTap: onTap,
        child: const Padding(
          padding: EdgeInsets.symmetric(horizontal: AppSpacing.md, vertical: 12),
          child: Row(children: [
            Icon(Icons.search, color: AppColors.onSurfaceMuted),
            SizedBox(width: AppSpacing.sm),
            Expanded(
              child: Text(hint,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(color: AppColors.onSurfaceMuted, fontSize: 13.5)),
            ),
          ]),
        ),
      ),
    );
  }
}

/// The search page: screens by their Bengali or English names, and — for
/// somebody who may open the customer list — the synced customers by name or
/// mobile. A tap closes the page with what to open.
class NavSearchDelegate extends SearchDelegate<NavPick?> {
  NavSearchDelegate({required this.tree, required this.findCustomers})
      : super(searchFieldLabel: NavSearchBox.hint);

  final NavTree tree;

  /// Null when this person has no door to the customer list.
  final List<CustomerHit> Function(String query)? findCustomers;

  @override
  ThemeData appBarTheme(BuildContext context) {
    final theme = Theme.of(context);
    return theme.copyWith(
      appBarTheme: theme.appBarTheme.copyWith(
        backgroundColor: AppColors.surface,
        foregroundColor: AppColors.onSurface,
      ),
      inputDecorationTheme: const InputDecorationTheme(border: InputBorder.none),
    );
  }

  @override
  List<Widget> buildActions(BuildContext context) => [
        if (query.isNotEmpty)
          IconButton(
            tooltip: 'মুছুন',
            icon: const Icon(Icons.close),
            onPressed: () => query = '',
          ),
      ];

  @override
  Widget buildLeading(BuildContext context) => IconButton(
        tooltip: 'ফিরে যান',
        icon: const Icon(Icons.arrow_back),
        onPressed: () => close(context, null),
      );

  @override
  Widget buildResults(BuildContext context) => _results(context);

  @override
  Widget buildSuggestions(BuildContext context) => _results(context);

  Widget _results(BuildContext context) {
    final q = query.trim();
    if (q.isEmpty) {
      return const Padding(
        padding: EdgeInsets.all(AppSpacing.lg),
        child: Text('পর্দার নাম ("অর্ডার", "চালান", "stock") বা গ্রাহকের নাম/মোবাইল লিখুন।',
            style: TextStyle(color: AppColors.onSurfaceMuted)),
      );
    }
    final screens = tree.search(q);
    final customers = findCustomers?.call(q) ?? const <CustomerHit>[];
    if (screens.isEmpty && customers.isEmpty) {
      return Padding(
        padding: const EdgeInsets.all(AppSpacing.lg),
        child: Text('"$q" — কিছু পাওয়া গেল না।',
            style: const TextStyle(color: AppColors.onSurfaceMuted)),
      );
    }
    return ListView(
      key: const ValueKey('nav-search-results'),
      children: [
        if (screens.isNotEmpty) const _Heading('পর্দা'),
        for (final row in screens)
          ListTile(
            key: ValueKey('hit-${row.path}'),
            leading: _Dot(color: AppColors.moduleColor(row.module), icon: row.icon),
            title: Text(row.label),
            subtitle: Text(NavCatalog.moduleDef(row.module)?.label ?? 'আমি',
                style: const TextStyle(fontSize: 12)),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => close(context, NavPick(row.path!, row.label)),
          ),
        if (customers.isNotEmpty) const _Heading('গ্রাহক'),
        for (final c in customers)
          ListTile(
            key: ValueKey('hit-customer-${c.id}'),
            leading: _Dot(color: AppColors.moduleColor('customer'), icon: Icons.storefront_outlined),
            title: Text(c.label),
            subtitle: c.detail == null ? null : Text(c.detail!, style: const TextStyle(fontSize: 12)),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => close(context, NavPick('customers/${c.id}', c.label)),
          ),
      ],
    );
  }
}

class _Heading extends StatelessWidget {
  const _Heading(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(AppSpacing.md, AppSpacing.md, AppSpacing.md, AppSpacing.xs),
        child: Text(text,
            style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: AppColors.onSurfaceMuted)),
      );
}

class _Dot extends StatelessWidget {
  const _Dot({required this.color, required this.icon});

  final Color color;
  final IconData icon;

  @override
  Widget build(BuildContext context) => Container(
        width: 36,
        height: 36,
        decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(10)),
        child: Icon(icon, color: Colors.white, size: 20),
      );
}

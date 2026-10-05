import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/records/today_record.dart';
import 'today_panel.dart';

/// How the day is going, on one page — docs/Contract §৮.
///
/// <p>The same cards the home tab shows under its greeting, on a page of
/// their own for the "আজকের হিসাব" tile and for anyone who wants nothing
/// else on screen. All of the behaviour — the cached figures, the stale
/// notice, the absent-is-not-zero rule — lives in [TodayPanel]; this file
/// only gives it an app bar.
class TodayScreen extends StatelessWidget {
  const TodayScreen({super.key, this.fetch, this.lastKnown});

  /// Seams — the real ones need a server.
  final Future<TodayRecord> Function()? fetch;
  final TodayRecord? Function()? lastKnown;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('আজকের হিসাব')),
      body: TodayPanel(
        fetch: fetch,
        lastKnown: lastKnown,
        // Each destination exists only for somebody the server sent that
        // figure to: sales.order.view for sales, customer.view for dues,
        // approval.decide for approvals — the same permissions the screens
        // behind these routes are built for.
        onOpenSales: () => context.go('/home/orders'),
        onOpenDues: () => context.go('/home/dues'),
        onOpenApprovals: () => context.go('/home/approvals'),
      ),
    );
  }
}

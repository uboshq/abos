import 'package:flutter/material.dart';

import '../../core/sync_engine/reference_cache.dart';
import '../../core/sync_engine/reference_sync.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';

/// Hand-on-shelf quantities — reachable only from a menu tile gated on
/// `inventory.stock.view` (see menu_repository.dart), so a role without that
/// permission never lands here to find an empty list and wonder if the app
/// is broken.
class StockListScreen extends StatefulWidget {
  const StockListScreen({super.key});

  @override
  State<StockListScreen> createState() => _StockListScreenState();
}

class _StockListScreenState extends State<StockListScreen> {
  bool _refreshing = false;

  Future<void> _refresh() async {
    setState(() => _refreshing = true);
    try {
      await ReferenceSync.syncAll();
    } catch (_) {
      // See CustomerListScreen's own comment on the same catch.
    } finally {
      if (mounted) setState(() => _refreshing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final all = ReferenceCache.instance.allOf('StockOnHand');

    return Scaffold(
      appBar: AppBar(title: const Text('হাতে থাকা মজুদ')),
      body: Column(
        children: [
          if (_refreshing) const LinearProgressIndicator(),
          Expanded(
            // Always a RefreshIndicator, never a bare EmptyState — see
            // CustomerListScreen's own comment: without it, pulling down on
            // an empty first launch does nothing, which is exactly the one
            // moment this screen tells someone to do that.
            child: RefreshIndicator(
              onRefresh: _refresh,
              child: all.isEmpty
                  ? ListView(
                      children: const [
                        EmptyState(
                          icon: Icons.warehouse_outlined,
                          title: 'এখনো কোনো মজুদ সিঙ্ক হয়নি',
                          message: 'নিচে টেনে আবার চেষ্টা করুন।',
                        ),
                      ],
                    )
                  : ListView.separated(
                      padding:
                          const EdgeInsets.symmetric(horizontal: AppSpacing.md),
                      itemCount: all.length,
                      separatorBuilder: (_, __) =>
                          const SizedBox(height: AppSpacing.xs),
                      itemBuilder: (context, index) {
                        final row = all[index];
                        final name =
                            (row['productName'] ?? row['name'] ?? 'নাম নেই')
                                .toString();
                        final warehouse = row['warehouseName']?.toString();
                        final qty = row['quantity'] ?? row['qty'] ?? 0;
                        final unit = row['unit']?.toString() ?? '';
                        return Card(
                          child: ListTile(
                            title: Text(name),
                            subtitle:
                                warehouse != null ? Text(warehouse) : null,
                            trailing: Text(
                              '$qty $unit',
                              style:
                                  const TextStyle(fontWeight: FontWeight.w700),
                            ),
                          ),
                        );
                      },
                    ),
                  ),
          ),
        ],
      ),
    );
  }
}

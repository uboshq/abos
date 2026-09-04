import 'package:flutter/material.dart';

import '../../core/sync_engine/reference_cache.dart';
import '../../core/sync_engine/reference_sync.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';

/// The product catalogue, with the price a person is allowed to see.
///
/// <p><b>`purchasePrice` will not be in the payload for most roles</b> — see
/// docs/Contract, §৩ rule ঙ: the server omits the field entirely rather than
/// sending `null` or a masked value, for anyone without `inventory.cost.view`.
/// This screen never assumes the key exists; a product card simply has no
/// cost line when it is missing; see the '_CostLine' widget below for where
/// that shows up.
class ProductListScreen extends StatefulWidget {
  const ProductListScreen({super.key});

  @override
  State<ProductListScreen> createState() => _ProductListScreenState();
}

class _ProductListScreenState extends State<ProductListScreen> {
  bool _refreshing = false;
  String _query = '';

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
    final all = ReferenceCache.instance.allOf('Product');
    final filtered = _query.isEmpty
        ? all
        : all
            .where((p) => (p['name'] ?? '')
                .toString()
                .toLowerCase()
                .contains(_query.toLowerCase()))
            .toList();

    return Scaffold(
      appBar: AppBar(title: const Text('পণ্যের তালিকা')),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: TextField(
              decoration: const InputDecoration(
                hintText: 'পণ্যের নাম দিয়ে খুঁজুন',
                prefixIcon: Icon(Icons.search),
              ),
              onChanged: (value) => setState(() => _query = value),
            ),
          ),
          if (_refreshing) const LinearProgressIndicator(),
          Expanded(
            child: all.isEmpty && !_refreshing
                ? const EmptyState(
                    icon: Icons.inventory_2_outlined,
                    title: 'এখনো কোনো পণ্য সিঙ্ক হয়নি',
                    message: 'নিচে টেনে আবার চেষ্টা করুন।',
                  )
                : RefreshIndicator(
                    onRefresh: _refresh,
                    child: ListView.separated(
                      padding: const EdgeInsets.symmetric(
                          horizontal: AppSpacing.md),
                      itemCount: filtered.length,
                      separatorBuilder: (_, __) =>
                          const SizedBox(height: AppSpacing.xs),
                      itemBuilder: (context, index) =>
                          _ProductTile(product: filtered[index]),
                    ),
                  ),
          ),
        ],
      ),
    );
  }
}

class _ProductTile extends StatelessWidget {
  const _ProductTile({required this.product});

  final Map<String, dynamic> product;

  @override
  Widget build(BuildContext context) {
    final name = (product['name'] ?? 'নাম নেই').toString();
    final unit = product['unit']?.toString();
    final salesPrice = product['salesPrice'] ?? product['price'];
    // Absent, not null and not zero — see this file's own class comment.
    final hasCost = product.containsKey('purchasePrice');
    final cost = product['purchasePrice'];

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(name,
                      style: const TextStyle(fontWeight: FontWeight.w600)),
                  if (unit != null)
                    Text(unit,
                        style: Theme.of(context).textTheme.bodySmall),
                ],
              ),
            ),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                if (salesPrice != null)
                  Text('৳$salesPrice',
                      style: const TextStyle(fontWeight: FontWeight.w700)),
                if (hasCost)
                  Text('ক্রয়: ৳$cost',
                      style: Theme.of(context).textTheme.bodySmall),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

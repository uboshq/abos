import 'package:flutter/material.dart';

import '../../core/sync_engine/reference_cache.dart';
import '../../core/sync_engine/reference_sync.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';

/// Every customer this device has pulled — see reference_cache.dart's own
/// doc comment: this reads whatever `Customer` payloads have landed, and
/// asks nothing about their shape beyond the handful of keys a list row
/// needs.
class CustomerListScreen extends StatefulWidget {
  const CustomerListScreen({super.key});

  @override
  State<CustomerListScreen> createState() => _CustomerListScreenState();
}

class _CustomerListScreenState extends State<CustomerListScreen> {
  bool _refreshing = false;
  String _query = '';

  Future<void> _refresh() async {
    setState(() => _refreshing = true);
    try {
      await ReferenceSync.syncAll();
    } catch (_) {
      // A failed pull leaves whatever was already cached on screen — see
      // reference_sync.dart's own doc comment on why this is left to throw
      // rather than swallowed there; this caller's answer to that failure is
      // simply "the list on screen is whatever it already was".
    } finally {
      if (mounted) setState(() => _refreshing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final all = ReferenceCache.instance.allOf('Customer');
    final filtered = _query.isEmpty
        ? all
        : all.where((c) {
            final name = (c['name'] ?? '').toString().toLowerCase();
            final phone = (c['phone'] ?? c['mobile'] ?? '').toString();
            return name.contains(_query.toLowerCase()) || phone.contains(_query);
          }).toList();

    return Scaffold(
      appBar: AppBar(title: const Text('গ্রাহক')),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: TextField(
              decoration: const InputDecoration(
                hintText: 'নাম বা মোবাইল দিয়ে খুঁজুন',
                prefixIcon: Icon(Icons.search),
              ),
              onChanged: (value) => setState(() => _query = value),
            ),
          ),
          if (_refreshing) const LinearProgressIndicator(),
          Expanded(
            // Always a RefreshIndicator, never a bare EmptyState — a
            // RefreshIndicator needs a scrollable descendant to recognise the
            // pull gesture at all. Confirmed on a real device: the earlier
            // version showed "নিচে টেনে আবার চেষ্টা করুন" on first launch (an
            // empty cache) with no RefreshIndicator wrapping it, so the one
            // instruction on screen did nothing when followed.
            child: RefreshIndicator(
              onRefresh: _refresh,
              child: all.isEmpty
                  ? ListView(
                      children: const [
                        EmptyState(
                          icon: Icons.people_outline,
                          title: 'এখনো কোনো গ্রাহক সিঙ্ক হয়নি',
                          message: 'নিচে টেনে আবার চেষ্টা করুন।',
                        ),
                      ],
                    )
                  : filtered.isEmpty
                      ? ListView(
                          children: const [
                            EmptyState(
                              icon: Icons.search_off,
                              title: 'কোনো মিল পাওয়া যায়নি',
                            ),
                          ],
                        )
                      : ListView.separated(
                          padding:
                              const EdgeInsets.symmetric(horizontal: AppSpacing.md),
                          itemCount: filtered.length,
                          separatorBuilder: (_, __) =>
                              const SizedBox(height: AppSpacing.xs),
                          itemBuilder: (context, index) {
                            final customer = filtered[index];
                            return _CustomerTile(customer: customer);
                          },
                        ),
            ),
          ),
        ],
      ),
    );
  }
}

class _CustomerTile extends StatelessWidget {
  const _CustomerTile({required this.customer});

  final Map<String, dynamic> customer;

  @override
  Widget build(BuildContext context) {
    final name = (customer['name'] ?? 'নাম নেই').toString();
    final phone = (customer['phone'] ?? customer['mobile'])?.toString();
    final address = customer['address']?.toString();

    return Card(
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: AppColors.primary.withValues(alpha: 0.12),
          child: Text(
            name.isNotEmpty ? name[0].toUpperCase() : '?',
            style: const TextStyle(
                color: AppColors.primary, fontWeight: FontWeight.w700),
          ),
        ),
        title: Text(name, style: const TextStyle(fontWeight: FontWeight.w600)),
        subtitle: Text(
          [if (phone != null && phone.isNotEmpty) phone, if (address != null) address]
              .join(' · '),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
        ),
      ),
    );
  }
}

import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/approvals/approvals_api.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../books/books_widgets.dart';

/// ⭐ আমার অনুরোধ — যা আমি সইয়ের জন্য পাঠিয়েছি, কোথায় দাঁড়িয়ে আর কে কী বললেন (মালিক, ১৭ অক্টোবর ২০২৬: *"mobile er ta
/// mobile app style e zate bujazay sei vabe koro, direct webpage app e bujajayna"*)।
///
/// <p>মেনুর "আমার অনুরোধ" আগে ওয়েব-পাতা খুলত। ⓘ সারি ওয়েবের পাতার একই প্রশ্ন ([[ApprovalApiController::mine()]]), নতুন আগে;
/// মাথায় পুরো তালিকার গোনা আর কয়টা এখনো অপেক্ষায়। ⭐ ফেরত বা সংশোধনে ফেরত হলে কারণটা কার্ডেই — লেখককে ওটাই জানতে হয়।
class MyRequestsScreen extends StatefulWidget {
  const MyRequestsScreen({super.key, this.load = _server, this.title});

  static Future<MyRequestsPage> _server(String? cursor) => ApprovalsApi.mine(cursor: cursor);

  /// পরীক্ষার জন্য — না দিলে সার্ভার
  final Future<MyRequestsPage> Function(String? cursor) load;

  /// মেনুর নিজের নাম — না দিলে "আমার অনুরোধ"
  final String? title;

  @override
  State<MyRequestsScreen> createState() => _MyRequestsScreenState();
}

class _MyRequestsScreenState extends State<MyRequestsScreen> {
  final List<MyRequest> _rows = [];
  MyRequestsPage? _page;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load({bool more = false}) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final page = await widget.load(more ? _page?.nextCursor : null);
      if (!mounted) return;
      setState(() {
        if (!more) _rows.clear();
        _rows.addAll(page.rows);
        _page = page;
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: 'আমার অনুরোধের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।',
            whenAbsent: 'এই তালিকা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final page = _page;

    return Scaffold(
      appBar: AppBar(title: Text(widget.title ?? 'আমার অনুরোধ')),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (_busy && page == null) const LinearProgressIndicator(),
            if (_error != null) BooksError(_error!),
            if (page != null && page.count != null && _rows.isNotEmpty)
              TotalStrip(
                key: const ValueKey('my-requests-total'),
                label: 'মোট অনুরোধ',
                value: '${page.count} টি',
                count: null,
              ),
            if (page != null && page.pendingCount != null && _rows.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                child: Text('এখনো সইয়ের অপেক্ষায়: ${page.pendingCount} টি',
                    key: const ValueKey('my-requests-pending'),
                    style: const TextStyle(color: AppColors.onSurfaceMuted)),
              ),
            if (page != null && _rows.isEmpty)
              const EmptyState(
                icon: Icons.outbox_outlined,
                title: 'আপনার পাঠানো কোনো অনুরোধ নেই',
                message: 'কোনো কাগজ সইয়ের জন্য পাঠালে এখানে দেখা যাবে।',
              ),
            for (final r in _rows) _RequestCard(r),
            if (page?.nextCursor != null)
              Center(
                child: TextButton.icon(
                  key: const ValueKey('my-requests-more'),
                  onPressed: _busy ? null : () => _load(more: true),
                  icon: const Icon(Icons.expand_more),
                  label: const Text('আরও'),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _RequestCard extends StatelessWidget {
  const _RequestCard(this.r);

  final MyRequest r;

  /// অবস্থার রং — অপেক্ষা হলুদ, হ্যাঁ সবুজ, না বা ফেরত লাল
  Color get _tone => switch (r.status) {
        'approved' => AppColors.success,
        'rejected' || 'returned' => AppColors.danger,
        'pending' => AppColors.warning,
        _ => AppColors.onSurfaceMuted,
      };

  /// সার্ভারের সময় (ISO, অঞ্চলসহ) → ফোনের নিজের দিনের তারিখ; ⚠️ সরাসরি কাটলে রাতের অনুরোধ আগের দিনে পড়ত
  static String _day(String? iso) {
    final at = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
    return at == null ? '' : isoOf(at).split('-').reversed.join('/');
  }

  @override
  Widget build(BuildContext context) {
    final said = [
      if ((r.lastLabel ?? '').isNotEmpty) r.lastLabel!,
      if ((r.lastBy ?? '').isNotEmpty) r.lastBy!,
    ].join(' · ');

    return Card(
      key: ValueKey('my-request-${r.id}'),
      margin: const EdgeInsets.only(bottom: AppSpacing.xs),
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              Expanded(
                child: Text(r.documentNo ?? r.documentType,
                    style: const TextStyle(fontWeight: FontWeight.w700)),
              ),
              if (r.amount != null) Text(Money.taka(r.amount), style: const TextStyle(fontWeight: FontWeight.w700)),
            ]),
            if ((r.summary ?? '').isNotEmpty) Text(r.summary!),
            const SizedBox(height: AppSpacing.xs),
            Text([r.statusLabel, _day(r.requestedAt)].where((s) => s.isNotEmpty).join(' · '),
                style: TextStyle(color: _tone, fontWeight: FontWeight.w600)),
            if (said.isNotEmpty) Text('শেষ কথা: $said', style: const TextStyle(color: AppColors.onSurfaceMuted)),
            if ((r.lastRemarks ?? '').isNotEmpty)
              Text('কারণ: ${r.lastRemarks}', key: ValueKey('my-request-remarks-${r.id}')),
          ],
        ),
      ),
    );
  }
}

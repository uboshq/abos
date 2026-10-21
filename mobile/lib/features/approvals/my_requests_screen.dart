import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/approvals/approvals_api.dart';
import '../../core/records/money.dart';
import '../../core/status/app_status_chip.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/number_with_party.dart';
import '../books/books_widgets.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/locale/l10n.dart';

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
            fallback: context.l10n.approvalsMyRequestsLoadFailed,
            whenAbsent: context.l10n.approvalsMyRequestsServerMissing));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final page = _page;

    return Scaffold(
      appBar: AbosScreenBar(title: widget.title ?? context.l10n.approvalsMyRequestsTitle),
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
                label: context.l10n.approvalsMyRequestsTotalLabel,
                value: context.l10n.approvalsMyRequestsTotalValue(page.count!),
                count: null,
              ),
            if (page != null && page.pendingCount != null && _rows.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                child: Text(context.l10n.approvalsMyRequestsPending(page.pendingCount!),
                    key: const ValueKey('my-requests-pending'),
                    style: TextStyle(color: context.palette.onSurfaceMuted)),
              ),
            if (page != null && _rows.isEmpty)
              EmptyState(
                icon: Icons.outbox_outlined,
                title: context.l10n.approvalsMyRequestsEmptyTitle,
                message: context.l10n.approvalsMyRequestsEmptyMessage,
              ),
            for (final r in _rows) _RequestCard(r),
            if (page?.nextCursor != null)
              Center(
                child: TextButton.icon(
                  key: const ValueKey('my-requests-more'),
                  onPressed: _busy ? null : () => _load(more: true),
                  icon: const Icon(Icons.expand_more),
                  label: Text(context.l10n.approvalsMyRequestsMore),
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
  Color _tone(BuildContext context) => switch (r.status) {
        'approved' => context.palette.success,
        'rejected' || 'returned' => context.palette.danger,
        'pending' => context.palette.warning,
        _ => context.palette.onSurfaceMuted,
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
              // ⭐ নম্বরের পাশে পক্ষ, এক লাইনে (মালিক, ১৯ অক্টোবর ২০২৬); পুরনো সার্ভারে কেবল নম্বর
              Expanded(
                child: NumberWithParty(
                    key: ValueKey('my-request-number-${r.id}'),
                    number: r.documentNo ?? r.documentType,
                    party: r.party,
                    style: const TextStyle(fontWeight: FontWeight.w700)),
              ),
              if (r.amount != null) Text(Money.taka(r.amount), style: const TextStyle(fontWeight: FontWeight.w700)),
            ]),
            // ⓘ পক্ষ নম্বরের লাইনে — সারাংশে আর দুইবার নয় ([[summaryAfterParty]])
            if (summaryAfterParty(r.summary, r.party) case final rest?) Text(rest),
            const SizedBox(height: AppSpacing.xs),
            // ⭐ অবস্থা — অ্যাপের এক চিপ (নকশা ৩, অংশ ৫): অপেক্ষায় · মঞ্জুর · প্রত্যাখ্যাত · ফেরত; পাশে পাঠানোর দিন
            Wrap(spacing: AppSpacing.xs, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
              AppStatusChip.forServer(r.status,
                  key: ValueKey('my-request-state-${r.id}'),
                  fallbackLabel: r.statusLabel,
                  fallbackColour: _tone(context),
                  dense: true),
              if (_day(r.requestedAt).isNotEmpty)
                Text(_day(r.requestedAt), style: TextStyle(color: context.palette.onSurfaceMuted)),
            ]),
            if (said.isNotEmpty) Text(context.l10n.approvalsMyRequestsLastWord(said), style: TextStyle(color: context.palette.onSurfaceMuted)),
            if ((r.lastRemarks ?? '').isNotEmpty)
              Text(context.l10n.approvalsMyRequestsReason(r.lastRemarks!), key: ValueKey('my-request-remarks-${r.id}')),
          ],
        ),
      ),
    );
  }
}

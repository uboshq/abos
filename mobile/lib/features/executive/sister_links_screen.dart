import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/locale/l10n.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../books/books_widgets.dart';
import 'owner_page_frame.dart';
import 'owner_pages_api.dart';

/// ⭐ মালিকের কেন্দ্র — ভাই-কোম্পানির পক্ষ, কেবল পড়া (ওয়েবের `/owner/sister-links`; মালিক, ১৭ অক্টোবর ২০২৬)।
///
/// <p>"এই কোম্পানির এই ক্রেতা বা সরবরাহকারী আসলে আমাদের ঐ কোম্পানি" — জোড়া থাকলে গ্রুপের মোট থেকে দুইয়ের মাঝের বিক্রি, পাওনা
/// আর দেনা বাদ যায়। ⛔ জোড়া দেওয়া আর তোলা ফোনে নেই: একটা জোড়া গ্রুপের বিক্রির সংখ্যা বদলায়, তাই ওয়েবেই।
/// ⓘ মাথায় গোটা তালিকার গোনা (সার্ভারের, কেবল এই পাতার নয়); ৫০টা করে পাতা।
class SisterLinksScreen extends StatefulWidget {
  const SisterLinksScreen({super.key, this.api = const ServerOwnerPagesApi()});

  final OwnerPagesApi api;

  @override
  State<SisterLinksScreen> createState() => _SisterLinksScreenState();
}

class _SisterLinksScreenState extends State<SisterLinksScreen> {
  final List<SisterLinkRow> _rows = [];
  int? _total;
  int? _next;
  bool _busy = false;
  bool _loaded = false;
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
      final page = await widget.api.links(more ? (_next ?? 1) : 1);
      if (!mounted) return;
      setState(() {
        if (!more) _rows.clear();
        _rows.addAll(page.links);
        _total = page.total;
        _next = page.nextPage;
        _loaded = true;
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: context.l10n.ownerLinksFailed,
            whenAbsent: context.l10n.ownerPageAbsent));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final w = context.l10n;
    return Scaffold(
        appBar: AbosScreenBar(title: w.ownerLinksTitle),
        body: RefreshIndicator(
          onRefresh: _load,
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.all(AppSpacing.md),
            children: [
              OwnerNote(w.ownerLinksExplain),
              OwnerNote(w.ownerLinksWebOnly, colour: context.palette.warning),
              // ⛔ পুরনো সার্ভার গোনা না পাঠালে পট্টিই নেই — এক পাতার গোনা দেখানো মিথ্যা মোট
              if (_total != null) OwnerTotal(key: const ValueKey('links-total'), label: w.ownerLinksTotal, value: w.ownerCount(_total!)),
              if (_busy) const LinearProgressIndicator(),
              if (_error != null) BooksError(_error!),
              if (_loaded && _rows.isEmpty && _error == null)
                EmptyState(
                  icon: Icons.link_off,
                  title: w.ownerLinksNoneTitle,
                  message: w.ownerLinksNoneMessage,
                ),
              for (final link in _rows)
                Card(
                  key: ValueKey('link-${link.id}'),
                  margin: const EdgeInsets.only(bottom: AppSpacing.xs),
                  child: ListTile(
                    leading: Icon(link.type == 'supplier' ? Icons.local_shipping_outlined : Icons.storefront_outlined),
                    title: Text('${link.party} · ${_type(w, link.type)}'),
                    subtitle: Text(w.ownerLinksIsSister(link.company, link.sister)),
                  ),
                ),
              if (_next != null) MoreButton(busy: _busy, onPressed: () => _load(more: true)),
            ],
          ),
        ),
      );
  }

  /// ⓘ সার্ভারের `type_label` মানুষটার ভাষায় — ফোন নিজে বলে, অ্যাপের ভাষায়
  static String _type(AppL10n w, String type) => type == 'supplier' ? w.ownerPartySupplier : w.ownerPartyCustomer;
}

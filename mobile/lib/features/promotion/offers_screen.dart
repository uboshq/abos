import 'package:flutter/material.dart';

import '../../core/locale/l10n.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/promotion/offers_api.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/paged_list.dart';
import '../../core/widgets/search_debouncer.dart';
import '../books/books_widgets.dart';

/// ⭐ চলতি অফার — SR-এর হাতে, কেবল পড়া (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬)।
///
/// <p>প্রতিটা কার্ডে: কোন মালে, কী শর্তে কী (ছাড় বা ফ্রি), কার জন্য, কবে পর্যন্ত। খোঁজার ঘরে পণ্যের নাম লিখলে কেবল সেই
/// মালের অফার — দোকানে দাঁড়িয়ে প্রশ্নটা প্রায় সবসময় "এই মালে কিছু আছে?"।
class OffersScreen extends StatefulWidget {
  const OffersScreen({super.key, this.api = const ServerOffersApi(), this.today});

  final OffersApi api;

  /// পরীক্ষার জন্য — না দিলে আজ
  final DateTime? today;

  @override
  State<OffersScreen> createState() => _OffersScreenState();
}

class _OffersScreenState extends State<OffersScreen> {
  bool _enabled = true;
  String _q = '';
  late final _typed = SearchDebouncer(onSearch: (q) => setState(() => _q = q));

  @override
  void dispose() {
    _typed.dispose();
    super.dispose();
  }

  /// ফোনে দেখানো অফারের গোনা — সার্ভার গোটা চলতি তালিকা একবারে দেয় (২০০ পর্যন্ত), খোঁজ ফোনেই ছাঁকে
  int _shown = 0;

  @override
  Widget build(BuildContext context) {
    final l = context.l10n;
    return Scaffold(
        appBar: AbosScreenBar(title: l.promoOffersTitle),
        body: PagedList<Offer>(
          reloadKey: _q,
          load: (_) async {
            final page = await widget.api.running();
            if (mounted && page.enabled != _enabled) setState(() => _enabled = page.enabled);
            final q = _q.toLowerCase();
            final rows = q.isEmpty
                ? page.rows
                : page.rows
                    .where((o) => '${o.name} ${o.code} ${o.goods}'.toLowerCase().contains(q))
                    .toList();
            if (mounted && rows.length != _shown) setState(() => _shown = rows.length);
            return (rows, null);
          },
          failed: l.promoOffersLoadFailed,
          whenAbsent: l.promoOffersNotOnServer,
          emptyIcon: Icons.local_offer_outlined,
          emptyTitle: _enabled ? l.promoOffersNoneRunning : l.promoOffersOffHere,
          emptyMessage: _enabled && _q.isNotEmpty ? l.promoOffersNoMatch(_q) : null,
          header: [
            TextField(
              key: const ValueKey('offer-search'),
              textInputAction: TextInputAction.search,
              decoration: InputDecoration(prefixIcon: const Icon(Icons.search), hintText: l.promoOffersSearchHint),
              onChanged: _typed.onChanged,
              onSubmitted: _typed.onSubmitted,
            ),
            const SizedBox(height: AppSpacing.sm),
            // ⭐ গোটা তালিকার গোনা — পাতা ভাঙে না, তাই ফোনের দেখানো সারিই সব (পূর্ণ নিরীক্ষা §৯ ⚠️৭, ১৮ অক্টোবর ২০২৬)
            if (_shown > 0) TotalStrip(label: l.promoOffersTitle, value: l.promoOffersCountValue(_shown)),
          ],
          item: (context, o) => _OfferCard(offer: o, today: widget.today),
        ),
      );
  }
}

class _OfferCard extends StatelessWidget {
  const _OfferCard({required this.offer, this.today});

  final Offer offer;
  final DateTime? today;

  @override
  Widget build(BuildContext context) {
    final end = offer.endsOn == null ? null : DateTime.tryParse(offer.endsOn!);
    final now = today ?? DateTime.now();
    final left = end?.difference(DateTime(now.year, now.month, now.day)).inDays;
    final whom = offer.forWhom;
    final l = context.l10n;

    return Card(
      key: ValueKey('offer-${offer.id}'),
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(Icons.local_offer, color: context.palette.success, size: 20),
                const SizedBox(width: AppSpacing.xs),
                Expanded(child: Text(offer.name, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16))),
              ],
            ),
            if (offer.summary.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 2),
                child: Text(offer.summary, style: TextStyle(color: context.palette.onSurfaceMuted)),
              ),
            const SizedBox(height: AppSpacing.xs),
            FactRow(l.promoOffersGoods, offer.goods),
            for (final rule in offer.rules)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 2),
                child: Text('• $rule', style: TextStyle(fontWeight: FontWeight.w600, color: context.palette.success)),
              ),
            if (whom != null) FactRow(l.promoOffersForWhom, whom),
            FactRow(
              l.promoOffersUntil,
              [
                dayOf(offer.endsOn),
                if (offer.endsAt != null) offer.endsAt!,
                if (left != null) left == 0 ? l.promoOffersEndsToday : l.promoOffersDaysLeft(left),
              ].join(' · '),
              colour: left != null && left <= 3 ? context.palette.danger : null,
            ),
          ],
        ),
      ),
    );
  }
}

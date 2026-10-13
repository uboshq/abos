import 'package:flutter/material.dart';

import '../../core/promotion/offers_api.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/paged_list.dart';
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

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('চলতি অফার')),
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
            return (rows, null);
          },
          failed: 'অফারের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।',
          whenAbsent: 'সার্ভারে অফারের তালিকা এখনো আসেনি — অফিসে জানান।',
          emptyIcon: Icons.local_offer_outlined,
          emptyTitle: _enabled ? 'এখন কোনো চলতি অফার নেই' : 'এই শাখায় অফার এখন বন্ধ',
          emptyMessage: _enabled && _q.isNotEmpty ? '"$_q" — এই নামে কোনো অফার মেলেনি।' : null,
          header: [
            TextField(
              key: const ValueKey('offer-search'),
              textInputAction: TextInputAction.search,
              decoration: const InputDecoration(prefixIcon: Icon(Icons.search), hintText: 'পণ্যের নাম লিখুন'),
              onSubmitted: (v) => setState(() => _q = v.trim()),
            ),
            const SizedBox(height: AppSpacing.sm),
          ],
          item: (context, o) => _OfferCard(offer: o, today: widget.today),
        ),
      );
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
                const Icon(Icons.local_offer, color: AppColors.success, size: 20),
                const SizedBox(width: AppSpacing.xs),
                Expanded(child: Text(offer.name, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16))),
              ],
            ),
            if (offer.summary.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 2),
                child: Text(offer.summary, style: const TextStyle(color: AppColors.onSurfaceMuted)),
              ),
            const SizedBox(height: AppSpacing.xs),
            FactRow('কোন মালে', offer.goods),
            for (final rule in offer.rules)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 2),
                child: Text('• $rule', style: const TextStyle(fontWeight: FontWeight.w600, color: AppColors.success)),
              ),
            if (whom != null) FactRow('কার জন্য', whom),
            FactRow(
              'কবে পর্যন্ত',
              [
                dayOf(offer.endsOn),
                if (offer.endsAt != null) offer.endsAt!,
                if (left != null) left == 0 ? 'আজই শেষ' : 'আর $left দিন',
              ].join(' · '),
              colour: left != null && left <= 3 ? AppColors.danger : null,
            ),
          ],
        ),
      ),
    );
  }
}

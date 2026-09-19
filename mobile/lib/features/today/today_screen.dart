import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/records/money.dart';
import '../../core/records/today_record.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';

/// How the day is going, on one page — docs/Contract §৮.
///
/// <p>The owner's own words: *বিক্রি · আদায় · নগদ, এক পাতায়*. It is the one
/// question asked away from a desk, which is why it belongs on a phone rather
/// than beside the web dashboard that already answers it for anyone sitting
/// down.
class TodayScreen extends StatefulWidget {
  const TodayScreen({super.key, this.fetch, this.lastKnown});

  /// Seams — the real ones need a server.
  final Future<TodayRecord> Function()? fetch;
  final TodayRecord? Function()? lastKnown;

  @override
  State<TodayScreen> createState() => _TodayScreenState();
}

class _TodayScreenState extends State<TodayScreen> {
  TodayRecord? _today;
  String? _error;
  bool _busy = false;

  /// True while showing figures that came from the phone rather than the
  /// server just now — see [_AsOfLine] for why that must be visible.
  bool _stale = false;

  @override
  void initState() {
    super.initState();
    // Whatever the phone already knows, drawn immediately. An owner opening
    // this in a car sees the morning's numbers before the request finishes,
    // and the line underneath says they are the morning's.
    _today = (widget.lastKnown ?? TodayApi.lastKnown)();
    _stale = _today != null;
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final fresh = await (widget.fetch ?? TodayApi.fetch)();
      if (!mounted) return;
      setState(() {
        _today = fresh;
        _stale = false;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() => _error = errorMessageFor(error,
          fallback: 'আজকের হিসাব আনা গেল না।',
          // See the approvals screen: a 404 on a route that names no row
          // means the server is older than this build, not that today has no
          // figures.
          whenAbsent: 'আজকের হিসাব এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের '
              'চেয়ে নতুন। অফিসে জানান।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final today = _today;

    return Scaffold(
      appBar: AppBar(title: const Text('আজকের হিসাব')),
      body: Column(
        children: [
          if (_busy) const LinearProgressIndicator(),
          Expanded(
            child: RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.all(AppSpacing.md),
                children: [
                  if (today == null) ...[
                    const SizedBox(height: AppSpacing.xxl),
                    Icon(
                      _error == null
                          ? Icons.hourglass_empty
                          : Icons.cloud_off_outlined,
                      size: 48,
                      color: AppColors.onSurfaceMuted,
                    ),
                    const SizedBox(height: AppSpacing.md),
                    Text(
                      _error ?? 'আনা হচ্ছে…',
                      textAlign: TextAlign.center,
                      style: const TextStyle(color: AppColors.onSurfaceMuted),
                    ),
                  ] else ...[
                    _Heading(today: today),
                    const SizedBox(height: AppSpacing.md),
                    // ⚠️ An absent figure draws no card at all. See
                    // TodayRecord's own doc comment: zero is a number somebody
                    // acts on, and "no cash today" is not "you may not see the
                    // cash".
                    if (today.sales != null)
                      _Figure(
                        label: 'আজকের বিক্রি',
                        figure: today.sales!,
                        countLabel: 'টি বিক্রয়',
                        colour: AppColors.primary,
                      ),
                    if (today.collections != null)
                      _Figure(
                        label: 'আজকের আদায়',
                        figure: today.collections!,
                        countLabel: 'টি আদায়',
                        colour: AppColors.success,
                      ),
                    if (today.cashInHand != null)
                      _Figure(
                        label: 'হাতে নগদ',
                        figure: today.cashInHand!,
                        colour: AppColors.onSurface,
                      ),
                    if (today.dues != null)
                      _Figure(
                        label: 'মোট বকেয়া',
                        figure: today.dues!,
                        shopsLabel: 'টি দোকান',
                        colour: AppColors.danger,
                      ),
                    if (today.pendingApprovals != null &&
                        today.pendingApprovals! > 0)
                      Card(
                        child: ListTile(
                          leading: const Icon(Icons.fact_check_outlined,
                              color: AppColors.warning),
                          title: Text(
                              '${today.pendingApprovals} টি নথি আপনার সিদ্ধান্তের অপেক্ষায়'),
                        ),
                      ),
                    if (_error != null) ...[
                      const SizedBox(height: AppSpacing.md),
                      Text(
                        // ⚠️ Said plainly when the figures on screen came
                        // from the phone and the refresh failed. The asOf
                        // line already gives the hour, but somebody reading
                        // numbers during an outage deserves to be told that
                        // is what they are reading, not left to work it out
                        // from a timestamp.
                        _stale
                            ? '${_error!} নিচের সংখ্যাগুলো এই ফোনে রাখা ছিল।'
                            : _error!,
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                            color: AppColors.danger, fontSize: 12.5),
                      ),
                    ],
                  ],
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _Heading extends StatelessWidget {
  const _Heading({required this.today});

  final TodayRecord today;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        // ⚠️ Whose figures these are, said out loud. Somebody can belong to
        // more than one company and this app cannot switch between them yet;
        // unlabelled numbers from the wrong company are numbers acted on.
        Text(
          [
            if (today.company != null) today.company!,
            if (today.branch != null) today.branch!,
          ].join(' · '),
          style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15),
        ),
        if (today.date != null)
          Text(today.date!,
              style: Theme.of(context).textTheme.bodySmall),
        _AsOfLine(asOf: today.asOf),
      ],
    );
  }
}

/// When these numbers were true.
///
/// <p>A stale figure looks exactly like a fresh one, and an owner deciding
/// from the morning's cash at nine in the evening is deciding from a number
/// that stopped being true hours ago. The screen would rather look slightly
/// less confident than be quietly wrong.
class _AsOfLine extends StatelessWidget {
  const _AsOfLine({required this.asOf});

  final DateTime? asOf;

  @override
  Widget build(BuildContext context) {
    if (asOf == null) return const SizedBox.shrink();
    return Text(
      'হিসাব ${DateFormat('dd/MM/yyyy hh:mm a').format(asOf!)} পর্যন্ত',
      style: const TextStyle(fontSize: 12, color: AppColors.onSurfaceMuted),
    );
  }
}

class _Figure extends StatelessWidget {
  const _Figure({
    required this.label,
    required this.figure,
    required this.colour,
    this.countLabel,
    this.shopsLabel,
  });

  final String label;
  final TodayFigure figure;
  final Color colour;
  final String? countLabel;
  final String? shopsLabel;

  @override
  Widget build(BuildContext context) {
    final extra = [
      if (figure.count != null && countLabel != null)
        '${figure.count} $countLabel',
      if (figure.shops != null && shopsLabel != null)
        '${figure.shops} $shopsLabel',
    ].join(' · ');

    return Card(
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(label,
                      style: Theme.of(context).textTheme.bodySmall),
                  if (extra.isNotEmpty)
                    Text(extra,
                        style: Theme.of(context).textTheme.bodySmall),
                ],
              ),
            ),
            Text(
              Money.taka(figure.amount),
              style: TextStyle(
                  fontSize: 20, fontWeight: FontWeight.w700, color: colour),
            ),
          ],
        ),
      ),
    );
  }
}

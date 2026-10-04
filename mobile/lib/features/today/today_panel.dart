import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/records/money.dart';
import '../../core/records/today_record.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';

/// The day's figures as cards, without a Scaffold around them — docs/Contract
/// §৮, drawn the same way whether the home tab embeds them under a greeting
/// or [TodayScreen] shows them on their own page.
///
/// <p>The owner's own words: *বিক্রি · আদায় · নগদ, এক পাতায়*. Everything
/// about *which* figures appear is the server's decision and this widget's
/// job is only to draw what came back: an absent block draws no card at all
/// (rule ক), the company is named (rule খ), and the hour the numbers were
/// true is always on screen (rule ঘ).
class TodayPanel extends StatefulWidget {
  const TodayPanel({
    super.key,
    this.fetch,
    this.lastKnown,
    this.showOrigin = true,
    this.leading,
    this.trailing = const <Widget>[],
    this.onOpenSales,
    this.onOpenDues,
    this.onOpenApprovals,
    this.onRecord,
    this.padding = const EdgeInsets.all(AppSpacing.md),
  });

  /// Seams — the real ones need a server.
  final Future<TodayRecord> Function()? fetch;
  final TodayRecord? Function()? lastKnown;

  /// Whether to print the company · branch line above the cards. The home
  /// tab turns this off only when its own header already names them — the
  /// line is never simply dropped, see docs/Contract §৮ rule খ.
  final bool showOrigin;

  /// Widgets drawn above the figures inside the same scroll view, given the
  /// record on screen (null until anything has arrived). The home tab puts
  /// its greeting and quick actions here so one pull refreshes the lot.
  /// Widgets drawn **below** the figures — the home tab's door to the module dashboards. Below, so today's numbers stay
  /// the first thing on screen.
  final List<Widget> trailing;

  final List<Widget> Function(BuildContext context, TodayRecord? today)?
      leading;

  /// Where a card leads when tapped. A card with no destination draws no
  /// chevron — the arrow is a promise, and it is only drawn when it can be
  /// kept.
  final VoidCallback? onOpenSales;
  final VoidCallback? onOpenDues;
  final VoidCallback? onOpenApprovals;

  /// Told about every record that reaches the screen — the cached one first,
  /// then the fresh one — so a header outside this widget can name the
  /// company these figures belong to.
  final void Function(TodayRecord record)? onRecord;

  final EdgeInsets padding;

  @override
  State<TodayPanel> createState() => _TodayPanelState();
}

class _TodayPanelState extends State<TodayPanel> {
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
    if (_today != null) widget.onRecord?.call(_today!);
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
      widget.onRecord?.call(fresh);
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
    final leading = widget.leading?.call(context, today) ?? const <Widget>[];

    return Column(
      children: [
        // Two pixels at the top rather than a spinner over the figures: a
        // refresh must never hide the numbers it is refreshing.
        if (_busy) const LinearProgressIndicator(minHeight: 2),
        Expanded(
          child: RefreshIndicator(
            onRefresh: _load,
            child: ListView(
              padding: widget.padding,
              children: [
                ...leading,
                if (today == null)
                  _NothingYet(error: _error)
                else ...[
                  if (_error != null)
                    // ⚠️ Said before the numbers, not after them. Somebody
                    // reading figures during an outage deserves to be told
                    // that is what they are reading, not left to work it
                    // out from a timestamp further down.
                    _Notice(
                      text: _stale
                          ? '${_error!} নিচের সংখ্যাগুলো এই ফোনে রাখা ছিল।'
                          : _error!,
                    ),
                  _OriginLine(today: today, showOrigin: widget.showOrigin),
                  const SizedBox(height: AppSpacing.sm),
                  // ⚠️ An absent figure draws no card at all. See
                  // TodayRecord's own doc comment: zero is a number somebody
                  // acts on, and "no cash today" is not "you may not see the
                  // cash".
                  if (today.sales != null)
                    _HeroCard(
                      heading: 'আজকের বিক্রি',
                      figure: today.sales!,
                      colour: AppColors.primary,
                      detail: _count(today.sales!.count, 'টি বিক্রয়'),
                      onTap: widget.onOpenSales,
                    ),
                  if (today.collections != null || today.dues != null)
                    _CollectionsDuesCard(
                      collections: today.collections,
                      dues: today.dues,
                      onTap: widget.onOpenDues,
                    ),
                  if (today.cashInHand != null)
                    _HeroCard(
                      heading: 'হাতে নগদ',
                      figure: today.cashInHand!,
                      colour: AppColors.onSurface,
                    ),
                  if (today.pendingApprovals != null &&
                      today.pendingApprovals! > 0)
                    _ApprovalsCard(
                      count: today.pendingApprovals!,
                      onTap: widget.onOpenApprovals,
                    ),
                ],
                ...widget.trailing,
              ],
            ),
          ),
        ),
      ],
    );
  }

  static String? _count(int? count, String unit) =>
      count == null ? null : '$count $unit';
}

/// Before anything has arrived: a hourglass while the first request runs, a
/// cloud with the reason once it has failed. No figures invented to fill the
/// space.
class _NothingYet extends StatelessWidget {
  const _NothingYet({required this.error});

  final String? error;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: AppSpacing.xxl),
      child: Column(
        children: [
          Icon(
            error == null ? Icons.hourglass_empty : Icons.cloud_off_outlined,
            size: 48,
            color: AppColors.onSurfaceMuted,
          ),
          const SizedBox(height: AppSpacing.md),
          Text(
            error ?? 'আনা হচ্ছে…',
            textAlign: TextAlign.center,
            style: const TextStyle(color: AppColors.onSurfaceMuted),
          ),
        ],
      ),
    );
  }
}

class _Notice extends StatelessWidget {
  const _Notice({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.md, vertical: AppSpacing.sm),
      decoration: BoxDecoration(
        color: AppColors.warningSurface,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        children: [
          const Icon(Icons.cloud_off_outlined,
              size: 18, color: AppColors.warning),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Text(text,
                style:
                    const TextStyle(fontSize: 12.5, color: AppColors.warning)),
          ),
        ],
      ),
    );
  }
}

/// Whose figures these are, and when they were true.
///
/// <p>⚠️ Somebody can belong to more than one company and this app cannot
/// switch between them; unlabelled numbers from the wrong company are numbers
/// acted on. And a stale figure looks exactly like a fresh one: an owner
/// deciding from the morning's cash at nine in the evening is deciding from
/// a number that stopped being true hours ago. The line would rather look
/// slightly less confident than be quietly wrong.
class _OriginLine extends StatelessWidget {
  const _OriginLine({required this.today, required this.showOrigin});

  final TodayRecord today;
  final bool showOrigin;

  @override
  Widget build(BuildContext context) {
    final origin = [
      if (today.company != null) today.company!,
      if (today.branch != null) today.branch!,
    ].join(' · ');
    final asOf = today.asOf;

    return Row(
      children: [
        if (showOrigin && origin.isNotEmpty)
          Expanded(
            child: Text(origin,
                overflow: TextOverflow.ellipsis,
                style:
                    const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
          )
        else
          const Spacer(),
        if (asOf != null)
          Text(
            'হিসাব ${DateFormat('dd/MM/yyyy hh:mm a').format(asOf)} পর্যন্ত',
            style:
                const TextStyle(fontSize: 12, color: AppColors.onSurfaceMuted),
          ),
      ],
    );
  }
}

/// One card, one figure, and the arrow only when it leads somewhere.
class _HeroCard extends StatelessWidget {
  const _HeroCard({
    required this.heading,
    required this.figure,
    required this.colour,
    this.detail,
    this.onTap,
  });

  final String heading;
  final TodayFigure figure;
  final Color colour;
  final String? detail;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return _TapCard(
      onTap: onTap,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _CardHeading(heading, onTap: onTap),
          const SizedBox(height: AppSpacing.xs),
          Text(
            Money.taka(figure.amount),
            style: TextStyle(
                fontSize: 28, fontWeight: FontWeight.w800, color: colour),
          ),
          if (detail != null) ...[
            const SizedBox(height: AppSpacing.xs),
            Text(detail!,
                style: const TextStyle(
                    fontSize: 12.5, color: AppColors.onSurfaceMuted)),
          ],
        ],
      ),
    );
  }
}

/// Collections and dues side by side: what came in today next to what is
/// still out there, because the second is why the first matters.
class _CollectionsDuesCard extends StatelessWidget {
  const _CollectionsDuesCard({
    required this.collections,
    required this.dues,
    this.onTap,
  });

  final TodayFigure? collections;
  final TodayFigure? dues;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return _TapCard(
      onTap: onTap,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _CardHeading('আদায় ও বকেয়া', onTap: onTap),
          const SizedBox(height: AppSpacing.sm),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (collections != null)
                Expanded(
                  child: _Stat(
                    label: 'আজকের আদায়',
                    value: Money.taka(collections!.amount),
                    colour: AppColors.success,
                    detail: collections!.count == null
                        ? null
                        : '${collections!.count} টি আদায়',
                  ),
                ),
              if (dues != null)
                Expanded(
                  child: _Stat(
                    label: 'মোট বকেয়া',
                    value: Money.taka(dues!.amount),
                    colour: AppColors.danger,
                    detail:
                        dues!.shops == null ? null : '${dues!.shops} টি দোকান',
                  ),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

class _ApprovalsCard extends StatelessWidget {
  const _ApprovalsCard({required this.count, this.onTap});

  final int count;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return _TapCard(
      onTap: onTap,
      child: Row(
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              color: AppColors.warningSurface,
              borderRadius: BorderRadius.circular(10),
            ),
            child:
                const Icon(Icons.fact_check_outlined, color: AppColors.warning),
          ),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Text(
              '$count টি নথি আপনার সিদ্ধান্তের অপেক্ষায়',
              style: const TextStyle(fontWeight: FontWeight.w600),
            ),
          ),
          if (onTap != null)
            const Icon(Icons.chevron_right, color: AppColors.onSurfaceMuted),
        ],
      ),
    );
  }
}

class _TapCard extends StatelessWidget {
  const _TapCard({required this.child, this.onTap});

  final Widget child;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: child,
        ),
      ),
    );
  }
}

class _CardHeading extends StatelessWidget {
  const _CardHeading(this.text, {this.onTap});

  final String text;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: Text(text,
              style: const TextStyle(
                  fontSize: 14,
                  fontWeight: FontWeight.w700,
                  color: AppColors.onSurface)),
        ),
        if (onTap != null)
          const Icon(Icons.chevron_right,
              size: 20, color: AppColors.onSurfaceMuted),
      ],
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat({
    required this.label,
    required this.value,
    required this.colour,
    this.detail,
  });

  final String label;
  final String value;
  final Color colour;
  final String? detail;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label,
            style: const TextStyle(
                fontSize: 12.5, color: AppColors.onSurfaceMuted)),
        const SizedBox(height: 2),
        Text(value,
            style: TextStyle(
                fontSize: 20, fontWeight: FontWeight.w700, color: colour)),
        if (detail != null)
          Text(detail!,
              style: const TextStyle(
                  fontSize: 12, color: AppColors.onSurfaceMuted)),
      ],
    );
  }
}

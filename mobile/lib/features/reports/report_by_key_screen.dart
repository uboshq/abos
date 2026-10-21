import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/locale/app_locale.dart';
import '../../core/locale/l10n.dart';
import '../../core/records/report_record.dart';
import '../../core/widgets/empty_state.dart';
import 'reports_screen.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';

/// ⭐ একটা রিপোর্ট, চাবি ধরে — মেনুর রিপোর্ট-সারি থেকে ([ReportPaths]; fe, ১৯ অক্টোবর ২০২৬)।
///
/// <p>রিপোর্টের নাম আর ছাঁকনির ঘোষণা আসে সার্ভারের তালিকা থেকে (`GET /reports`), তারপর [ReportViewScreen] — তারিখের ঘর আর বাকি
/// ছাঁকনি সেই ঘোষণা ধরেই। ⓘ তালিকায় চাবিটা না থাকলে (চাবি নেই, বা মডিউল ফোনে বন্ধ) সোজা কথা — ওয়েব-জানালায় পাঠানো নয়,
/// কারণ সেখানেও একই দেয়াল।
class ReportByKeyScreen extends StatefulWidget {
  const ReportByKeyScreen({super.key, required this.reportKey, this.title = '', this.loadList, this.view});

  final String reportKey;

  /// মেনুর নাম — তালিকা আসার আগে মাথায়
  final String title;

  /// Injected in tests.
  final Future<List<ReportSummary>> Function()? loadList;

  /// Injected in tests — the report screen built for the summary found.
  final Widget Function(ReportSummary summary)? view;

  @override
  State<ReportByKeyScreen> createState() => _ReportByKeyScreenState();
}

class _ReportByKeyScreenState extends State<ReportByKeyScreen> {
  ReportSummary? _summary;
  bool _missing = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _error = null;
      _missing = false;
    });
    try {
      final list = await (widget.loadList ?? ReportsApi.list)();
      final found = list.where((r) => r.key == widget.reportKey).firstOrNull;
      if (!mounted) return;
      setState(() {
        _summary = found;
        _missing = found == null;
      });
    } catch (error) {
      if (mounted) {
        setState(() => _error = errorMessageFor(error, fallback: AppLocale.l10n.reportLoadFailed));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final summary = _summary;
    if (summary != null) {
      return (widget.view ?? (s) => ReportViewScreen(report: s))(summary);
    }

    return Scaffold(
      appBar: AbosScreenBar(title: widget.title.isEmpty ? context.l10n.reportTitle : widget.title),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          children: [
            if (_error != null)
              EmptyState(
                key: const ValueKey('report-by-key-error'),
                icon: Icons.cloud_off_outlined,
                title: context.l10n.reportLoadFailedTitle,
                message: _error,
              )
            else if (_missing)
              EmptyState(
                key: const ValueKey('report-by-key-missing'),
                icon: Icons.lock_outline,
                title: context.l10n.reportNotOpenForYou,
                message: context.l10n.reportNotOpenHint,
              )
            else
              const Padding(
                padding: EdgeInsets.all(32),
                child: Center(child: CircularProgressIndicator()),
              ),
          ],
        ),
      ),
    );
  }
}

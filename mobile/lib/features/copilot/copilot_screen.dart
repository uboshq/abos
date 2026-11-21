import 'package:flutter/material.dart';

import '../../core/api_client/api_client.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/locale/l10n.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import 'copilot_mark.dart';

/// ⭐ ফোনের কোপাইলট — ওয়েবের একই ইঞ্জিন, একই সংখ্যা, একই চাবি (মালিক, ১১ নভেম্বর ২০২৬: "কোপাইলটটা মোবাইল অ্যাপে একই বিল্ডে দিয়ে দাও")।
///
/// <p>দুই ভাগ: "আজ" — সকালের সারাংশ, নজর (কারণ লিখে "এটা ঠিক আছে"), প্রস্তাব; "জিজ্ঞাসা" — প্রশ্ন, উদাহরণ, উত্তরের সারি, উৎস, 👍 👎।
/// নাম সার্ভার থেকে (কন্ট্রোল প্যানেলের "Copilot")। ⛔ এখানে কিছু লেখা বা পাঠানো হয় না — খসড়া ওয়েবের পাতায়।
class CopilotScreen extends StatefulWidget {
  const CopilotScreen({super.key, this.api = const ServerCopilotApi()});

  final CopilotApi api;

  @override
  State<CopilotScreen> createState() => _CopilotScreenState();
}

class _CopilotScreenState extends State<CopilotScreen> {
  Map<String, dynamic>? _today;
  String? _error;
  bool _busy = false;
  final _question = TextEditingController();
  Map<String, dynamic>? _answer;
  String _asked = '';
  bool _asking = false;
  int? _vote;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _question.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final data = await widget.api.today();
      if (mounted) setState(() => _today = data);
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.copilotFailed));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _ask([String? text]) async {
    final q = (text ?? _question.text).trim();
    if (q.isEmpty) return;
    _question.text = q;
    setState(() {
      _asking = true;
      _asked = q;
      _answer = null;
      _vote = null;
    });
    try {
      final a = await widget.api.ask(q);
      if (mounted) setState(() => _answer = a);
    } catch (e) {
      if (mounted) setState(() => _answer = {'state': 'error', 'message': errorMessageFor(e, fallback: context.l10n.copilotFailed)});
    } finally {
      if (mounted) setState(() => _asking = false);
    }
  }

  Future<void> _feedback(int value) async {
    final id = _answer?['answer_id'];
    if (id is! int) return;
    try {
      await widget.api.feedback(id, value);
      if (mounted) setState(() => _vote = value);
    } catch (_) {}
  }

  Future<void> _dismiss(Map<String, dynamic> finding) async {
    final l = context.l10n;
    final reason = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(l.copilotDismissTitle),
        content: TextField(controller: reason, autofocus: true, decoration: InputDecoration(labelText: l.copilotDismissReason)),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: Text(l.copilotCancel)),
          FilledButton(onPressed: () => Navigator.pop(context, reason.text.trim().length >= 3), child: Text(l.copilotDismissGo)),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await widget.api.dismiss('${finding['id']}', reason.text.trim());
      await _load();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.maybeOf(context)?.showSnackBar(SnackBar(content: Text(errorMessageFor(e, fallback: l.copilotFailed))));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l = context.l10n;
    final name = (_today?['name'] as String?) ?? 'Copilot';

    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AbosScreenBar(
          title: name,
          bottom: TabBar(tabs: [Tab(text: l.copilotTabToday), Tab(text: l.copilotTabAsk)]),
        ),
        body: TabBarView(children: [
          RefreshIndicator(onRefresh: _load, child: _todayList(context)),
          _askView(context, name),
        ]),
      ),
    );
  }

  Widget _todayList(BuildContext context) {
    final l = context.l10n;
    final p = context.palette;
    final t = _today;
    final children = <Widget>[];

    if (_busy && t == null) children.add(const Padding(padding: EdgeInsets.all(AppSpacing.lg), child: Center(child: CircularProgressIndicator())));
    if (_error != null) children.add(EmptyState(icon: Icons.cloud_off_outlined, title: l.copilotFailed, message: _error));

    if (t != null) {
      final brief = (t['brief'] as List?) ?? const [];
      final watch = (t['watch'] as List?) ?? const [];
      final suggestions = (t['suggestions'] as List?) ?? const [];

      if (t['brief'] != null) {
        children.add(_Heading(l.copilotBrief));
        for (final part in brief.cast<Map>()) {
          children.add(Padding(padding: const EdgeInsets.only(top: AppSpacing.sm), child: Text('${part['title']}', style: TextStyle(color: p.onSurfaceMuted, fontWeight: FontWeight.w600))));
          for (final row in ((part['rows'] as List?) ?? const []).cast<Map>()) {
            children.add(ListTile(
              dense: true,
              title: Text('${row['label']}'),
              subtitle: '${row['sub']}'.isEmpty ? null : Text('${row['sub']}'),
              trailing: Text('${row['value']}', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
            ));
          }
        }
      }

      if (t['watch'] != null) {
        children.add(_Heading('${l.copilotWatch} (${watch.length})'));
        if (watch.isEmpty) children.add(Text(l.copilotWatchEmpty, style: TextStyle(color: p.onSurfaceMuted)));
        for (final f in watch.cast<Map<String, dynamic>>()) {
          final sev = '${f['severity']}';
          children.add(Card(
            key: ValueKey('copilot-finding-${f['id']}'),
            child: ListTile(
              leading: Icon(Icons.circle, size: 12, color: sev == 'urgent' ? p.danger : (sev == 'today' ? p.warning : p.onSurfaceMuted)),
              title: Text('${f['title']} · ${f['count']}'),
              subtitle: Text('${f['detail']}'),
              trailing: TextButton(onPressed: () => _dismiss(f), child: Text(l.copilotDismiss)),
            ),
          ));
        }
      }

      if (t['suggestions'] != null) {
        children.add(_Heading('${l.copilotSuggestions} (${suggestions.length})'));
        if (suggestions.isEmpty) children.add(Text(l.copilotSuggestionsEmpty, style: TextStyle(color: p.onSurfaceMuted)));
        for (final s in suggestions.cast<Map>()) {
          children.add(Card(
            child: Padding(
              padding: const EdgeInsets.all(AppSpacing.md),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('${s['title']}', style: const TextStyle(fontWeight: FontWeight.w700)),
                Text('${s['detail']}', style: TextStyle(color: p.onSurfaceMuted)),
                for (final row in ((s['rows'] as List?) ?? const []).cast<Map>())
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Row(children: [Expanded(child: Text('${row['label']}')), Text('${row['value']}', style: TextStyle(color: p.onSurfaceMuted, fontSize: 12))]),
                  ),
                const SizedBox(height: 4),
                Text('${s['note']}', style: TextStyle(color: p.onSurfaceMuted, fontSize: 12)),
              ]),
            ),
          ));
        }
      }
    }

    children.add(Padding(padding: const EdgeInsets.symmetric(vertical: AppSpacing.md), child: Text(l.copilotRules, style: TextStyle(color: p.onSurfaceMuted, fontSize: 12))));

    // ⓘ খালি বা ভুলের পাতাতেও নিচে টানলে আবার আনে — তালিকা ছোট হলেও টানা যায়
    return ListView(physics: const AlwaysScrollableScrollPhysics(), padding: const EdgeInsets.all(AppSpacing.md), children: children);
  }

  Widget _askView(BuildContext context, String name) {
    final l = context.l10n;
    final p = context.palette;
    final a = _answer;
    final examples = ((_today?['examples'] as List?) ?? const []).cast<String>().where((e) => e.isNotEmpty).toList();
    final result = a?['answer'] as Map?;

    return ListView(padding: const EdgeInsets.all(AppSpacing.md), children: [
      if (_today != null && _today!['model'] != true) Text(l.copilotModelOff, style: TextStyle(color: p.onSurfaceMuted, fontSize: 12)),
      const SizedBox(height: AppSpacing.sm),
      Row(children: [
        Expanded(
          child: TextField(
            key: const ValueKey('copilot-question'),
            controller: _question,
            textInputAction: TextInputAction.send,
            onSubmitted: (_) => _ask(),
            decoration: InputDecoration(hintText: l.copilotPlaceholder, border: const OutlineInputBorder()),
          ),
        ),
        const SizedBox(width: AppSpacing.sm),
        FilledButton(key: const ValueKey('copilot-ask'), onPressed: _asking ? null : _ask, child: Text(l.copilotAskButton)),
      ]),
      const SizedBox(height: AppSpacing.sm),
      Wrap(spacing: 6, runSpacing: 6, children: [for (final e in examples) ActionChip(label: Text(e), onPressed: () => _ask(e))]),
      // ⓘ উত্তর ভাবার সময় ঘোরা চাকার বদলে Copilot-এর নিজের চিহ্ন, দ্বিগুণ জোরে
      if (_asking)
        const Padding(
            padding: EdgeInsets.all(AppSpacing.lg),
            child: Center(child: CopilotMark(key: ValueKey('copilot-thinking'), size: 48, busy: true))),
      if (a != null) ...[
        const SizedBox(height: AppSpacing.md),
        Text(_asked, style: TextStyle(color: p.onSurfaceMuted)),
        const SizedBox(height: 4),
        if (a['state'] != 'answered')
          Text('${a['message'] ?? ''}', key: const ValueKey('copilot-state'))
        else if (result != null) ...[
          Text('${result['summary']}', key: const ValueKey('copilot-summary'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
          for (final row in ((result['rows'] as List?) ?? const []).cast<Map>())
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Row(children: [
                for (final (i, col) in ((result['columns'] as List?) ?? const []).cast<String>().indexed)
                  i == 0 ? Expanded(child: Text('${row[col] ?? ''}')) : Padding(padding: const EdgeInsets.only(left: 8), child: Text('${row[col] ?? ''}')),
              ]),
            ),
          const Divider(),
          Text('${l.copilotSource}: ${((result['sources'] as List?) ?? const []).map((s) => (s as Map)['label']).join(' · ')} · ${a['understood_by'] ?? ''}',
              style: TextStyle(color: p.onSurfaceMuted, fontSize: 12)),
          Text('${a['check'] ?? ''}', style: TextStyle(color: p.onSurfaceMuted, fontSize: 12)),
          Row(children: [
            IconButton(tooltip: l.copilotRight, onPressed: () => _feedback(1), icon: Icon(_vote == 1 ? Icons.thumb_up : Icons.thumb_up_outlined)),
            IconButton(tooltip: l.copilotWrong, onPressed: () => _feedback(-1), icon: Icon(_vote == -1 ? Icons.thumb_down : Icons.thumb_down_outlined)),
          ]),
        ],
      ],
    ]);
  }
}

class _Heading extends StatelessWidget {
  const _Heading(this.text);

  final String text;

  @override
  Widget build(BuildContext context) =>
      Padding(padding: const EdgeInsets.only(top: AppSpacing.lg, bottom: AppSpacing.xs), child: Text(text, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700)));
}

abstract class CopilotApi {
  Future<Map<String, dynamic>> today();
  Future<Map<String, dynamic>> ask(String question);
  Future<void> feedback(int answerId, int value);
  Future<void> dismiss(String finding, String reason);
}

class ServerCopilotApi implements CopilotApi {
  const ServerCopilotApi();

  @override
  Future<Map<String, dynamic>> today() async =>
      (await ApiClient.dio.get<Map<String, dynamic>>('/copilot/today')).data ?? const <String, dynamic>{};

  @override
  Future<Map<String, dynamic>> ask(String question) async =>
      (await ApiClient.dio.post<Map<String, dynamic>>('/copilot/ask', data: {'q': question, 'page': 'app'})).data ?? const <String, dynamic>{};

  @override
  Future<void> feedback(int answerId, int value) async =>
      ApiClient.dio.post<Map<String, dynamic>>('/copilot/answers/$answerId/feedback', data: {'value': value});

  @override
  Future<void> dismiss(String finding, String reason) async =>
      ApiClient.dio.post<Map<String, dynamic>>('/copilot/dismiss', data: {'finding': finding, 'reason': reason});
}

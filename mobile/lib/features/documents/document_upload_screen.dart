import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/api_client/once_key.dart';
import '../../core/documents_library/upload_api.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/unsaved_guard.dart';
import '../../core/permissions/camera_shot.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';

/// ⭐ কাগজ তোলা — গ্যালারি থেকে এক বা একাধিক ছবি, বা ক্যামেরায় স্ক্যান; নাম, ফোল্ডার, ধরন, গোপনীয়তা, শাখা (মালিক, ১৭ অক্টোবর
/// ২০২৬: *"direct webpage app e bujajayna"*)।
///
/// <p>⛔ সব যাচাই সার্ভারের — ওয়েবের তোলার একই নিয়ম (ফাইলের ধরন ও মাপ)। ⭐ ছবি (ক্যামেরা/গ্যালারি) আর ফোনের যেকোনো ফাইল — PDF,
/// Word, Excel … (fe, ১৭ অক্টোবর ২০২৬: file_picker); ধরন বাছাই সার্ভারের, ফোন আগে থেকে কিছু আটকায় না।
/// ⓘ লেখা পড়া (OCR) ওয়েবের স্ক্যানের পাতায় — ফোনের স্ক্যান ছবিটাই কাগজ হিসেবে রাখে।
class DocumentUploadScreen extends StatefulWidget {
  const DocumentUploadScreen(
      {super.key, this.scan = false, this.api = const ServerDocumentUploadApi(), this.picker, this.filePicker});

  /// ক্যামেরায় স্ক্যান (`/documents/scan`) — না হলে গ্যালারি (`/documents/upload`)
  final bool scan;
  final DocumentUploadApi api;

  /// পরীক্ষার জন্য — ছবির পথ ফেরায়
  final Future<List<String>> Function()? picker;

  /// পরীক্ষার জন্য — ফাইলের পথ ফেরায় (PDF ইত্যাদি)
  final Future<List<String>> Function()? filePicker;

  @override
  State<DocumentUploadScreen> createState() => _DocumentUploadScreenState();
}

class _DocumentUploadScreenState extends State<DocumentUploadScreen> {
  final _once = OnceKey();
  final _name = TextEditingController();
  final _description = TextEditingController();
  UploadSetup? _setup;
  String? _error;
  String? _folder;
  String? _type;
  String? _level;
  String? _branch;
  final List<String> _paths = [];
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _name.dispose();
    _description.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final setup = await widget.api.setup();
      if (!mounted) return;
      setState(() {
        _setup = setup;
        _folder ??= setup.folders.isEmpty ? null : setup.folders.first.code;
        _type ??= setup.types.isEmpty ? null : setup.types.first.code;
        _level ??= setup.levels.any((l) => l.code == 'internal') ? 'internal' : (setup.levels.isEmpty ? null : setup.levels.first.code);
        _branch ??= setup.branches.where((b) => b.isDefault).map((b) => b.code).firstOrNull ??
            (setup.companyWide || setup.branches.isEmpty ? null : setup.branches.first.code);
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: 'তোলার ফর্ম আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।',
            whenAbsent: 'সার্ভারে ফোন থেকে তোলা এখনো আসেনি — অফিসে জানান।'));
      }
    }
  }

  Future<void> _pick({bool files = false}) async {
    final List<String> got;
    if (files) {
      got = widget.filePicker != null
          ? await widget.filePicker!()
          : [
              for (final f in (await FilePicker.pickFiles(allowMultiple: true))?.files ?? const <PlatformFile>[])
                if (f.path != null) f.path!,
            ];
    } else if (widget.picker != null) {
      got = await widget.picker!();
    } else if (widget.scan) {
      final shot = await takeCameraShot(context, imageQuality: 85, maxWidth: 2400);
      got = [if (shot != null) shot.path];
    } else {
      got = [for (final x in await ImagePicker().pickMultiImage(imageQuality: 85, maxWidth: 2400)) x.path];
    }
    if (got.isEmpty || !mounted) return;
    final max = _setup?.maxFiles ?? 20;
    setState(() {
      _paths.addAll(got);
      if (_paths.length > max) _paths.removeRange(max, _paths.length);
    });
  }

  Future<void> _save() async {
    if (_paths.isEmpty) {
      setState(() => _error = widget.scan ? 'আগে কাগজটার ছবি তুলুন।' : 'আগে অন্তত একটা ছবি বা ফাইল বাছুন।');
      return;
    }
    if (_folder == null || _type == null || _level == null) {
      setState(() => _error = 'ফোল্ডার, ধরন আর গোপনীয়তা বাছুন।');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final (count, message) = await _once.send(() => widget.api.upload(UploadDraft(
            paths: List.of(_paths),
            name: _name.text,
            folder: _folder!,
            type: _type!,
            level: _level!,
            branch: _branch,
            description: _description.text,
          )));
      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(message.isNotEmpty ? message : '$count টি কাগজ তোলা হলো।')));
      Navigator.of(context).pop(true);
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'কাগজ তোলা গেল না।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Widget _choose(String key, String label, List<UploadChoice> items, String? value, void Function(String?) onChanged,
          {bool allowNone = false, String noneLabel = ''}) =>
      Padding(
        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
        child: DropdownButtonFormField<String?>(
          key: ValueKey(key),
          initialValue: value,
          isExpanded: true,
          decoration: InputDecoration(labelText: label),
          items: [
            if (allowNone) DropdownMenuItem<String?>(value: null, child: Text(noneLabel)),
            for (final c in items) DropdownMenuItem<String?>(value: c.code, child: Text(c.label)),
          ],
          onChanged: _busy ? null : onChanged,
        ),
      );

  // ⭐ ছবি বা ফাইল বাছা থাকলে "পিছনে" জিজ্ঞেস করে — বাছা কাগজগুলো এক চাপে হারাত (মোবাইলের নিরীক্ষা, ১৯ অক্টোবর ২০২৬; [[UnsavedGuard]])
  @override
  Widget build(BuildContext context) => UnsavedGuard(dirty: _paths.isNotEmpty && !_busy, child: _screen(context));

  Widget _screen(BuildContext context) {
    final s = _setup;

    return Scaffold(
      appBar: AbosScreenBar(title: widget.scan ? 'ক্যামেরায় স্ক্যান' : 'কাগজ তোলা'),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (s == null && _error != null)
              EmptyState(icon: Icons.cloud_off_outlined, title: 'আনা গেল না', message: _error)
            else if (s == null)
              const Padding(padding: EdgeInsets.all(AppSpacing.xl), child: Center(child: CircularProgressIndicator()))
            else ...[
              Wrap(spacing: AppSpacing.sm, runSpacing: AppSpacing.xs, children: [
                OutlinedButton.icon(
                  key: const ValueKey('upload-pick'),
                  icon: Icon(widget.scan ? Icons.photo_camera_outlined : Icons.photo_library_outlined),
                  label: Text(widget.scan ? 'ছবি তুলুন' : 'ছবি বাছুন'),
                  onPressed: _busy ? null : _pick,
                ),
                if (!widget.scan)
                  OutlinedButton.icon(
                    key: const ValueKey('upload-pick-file'),
                    icon: const Icon(Icons.attach_file),
                    label: const Text('ফাইল বাছুন (PDF ইত্যাদি)'),
                    onPressed: _busy ? null : () => _pick(files: true),
                  ),
              ]),
              if (_paths.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
                  child: Text('${_paths.length} টি ফাইল — প্রতিটা আলাদা কাগজ হবে',
                      key: const ValueKey('upload-count'), style: const TextStyle(fontWeight: FontWeight.w600)),
                ),
              Text('ⓘ কোন ধরনের ফাইল চলে আর কত বড়, সার্ভার ওয়েবের নিয়মেই দেখে। লেখা পড়া (OCR) ওয়েবে।',
                  style: TextStyle(fontSize: 12, color: context.palette.onSurfaceMuted)),
              const SizedBox(height: AppSpacing.sm),
              TextField(
                key: const ValueKey('upload-name'),
                controller: _name,
                decoration: const InputDecoration(labelText: 'নাম (ফাঁকা রাখলে ফাইলের নাম)'),
              ),
              const SizedBox(height: AppSpacing.sm),
              _choose('upload-folder', 'ফোল্ডার', s.folders, _folder, (v) => setState(() => _folder = v)),
              _choose('upload-type', 'ধরন', s.types, _type, (v) => setState(() => _type = v)),
              _choose('upload-level', 'গোপনীয়তা', s.levels, _level, (v) => setState(() => _level = v)),
              _choose('upload-branch', 'শাখা', s.branches, _branch, (v) => setState(() => _branch = v),
                  allowNone: s.companyWide, noneLabel: 'গোটা কোম্পানি'),
              TextField(
                key: const ValueKey('upload-description'),
                controller: _description,
                maxLines: 2,
                decoration: const InputDecoration(labelText: 'বিবরণ (ঐচ্ছিক)'),
              ),
              const SizedBox(height: AppSpacing.sm),
              if (_error != null)
                Padding(
                  padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                  child: Text(_error!, style: TextStyle(color: context.palette.danger)),
                ),
              FilledButton(
                key: const ValueKey('upload-save'),
                onPressed: _busy ? null : _save,
                child: Text(_busy ? 'তোলা হচ্ছে…' : 'তুলুন'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

import 'package:flutter/material.dart';

import '../../core/auth/session_profile.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/workspace/workspace_switcher.dart';

/// Opens the company/branch picker from the home header. Resolves to true
/// when the session moved anywhere, so the home screen knows to reload.
///
/// <p>[reload] is `GET /me` again: after a company change the branch list
/// is the new company's, and only the server knows it.
Future<bool> showWorkspacePicker(
  BuildContext context, {
  required SessionProfile profile,
  required Future<SessionProfile?> Function() reload,
  WorkspaceSwitcher? switcher,
}) async {
  final changed = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    showDragHandle: true,
    builder: (context) => WorkspacePicker(
      profile: profile,
      reload: reload,
      switcher: switcher ?? WorkspaceSwitcher(),
    ),
  );
  return changed ?? false;
}

/// Company first, then the branches of the company the session is in —
/// "সব শাখা" at the top, exactly as the web header lists them.
///
/// <p>Picking another company moves there at once (its default branch, all
/// branches in view — what the web does) and then shows that company's own
/// branches, because the list of the old one means nothing there. Picking a
/// branch moves there and closes.
class WorkspacePicker extends StatefulWidget {
  const WorkspacePicker({
    super.key,
    required this.profile,
    required this.reload,
    required this.switcher,
  });

  final SessionProfile profile;
  final Future<SessionProfile?> Function() reload;
  final WorkspaceSwitcher switcher;

  @override
  State<WorkspacePicker> createState() => _WorkspacePickerState();
}

class _WorkspacePickerState extends State<WorkspacePicker> {
  late SessionProfile _profile = widget.profile;
  bool _busy = false;
  bool _changed = false;
  String? _error;

  Future<void> _move({required String company, required String branch, required bool close}) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.switcher.switchTo(
        currentCompanyPublicId: _profile.company.publicId,
        companyPublicId: company,
        branch: branch,
      );
      _changed = true;
      final fresh = await widget.reload();
      if (!mounted) return;
      if (close || fresh == null || fresh.branches.length <= 1) {
        Navigator.of(context).pop(true);
        return;
      }
      setState(() {
        _profile = fresh;
        _busy = false;
      });
    } on WorkspaceFailure catch (failure) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = failure.message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final profile = _profile;
    final allSelected = profile.viewAllBranches;

    return PopScope(
      canPop: !_busy,
      child: SafeArea(
        child: ConstrainedBox(
          constraints: BoxConstraints(maxHeight: MediaQuery.of(context).size.height * 0.8),
          child: ListView(
            shrinkWrap: true,
            padding: const EdgeInsets.only(bottom: AppSpacing.lg),
            children: [
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md),
                child: Row(
                  children: [
                    const Expanded(
                      child: Text('কোম্পানি ও শাখা',
                          style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
                    ),
                    if (_busy)
                      const SizedBox(
                          width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2))
                    else
                      TextButton(
                        onPressed: () => Navigator.of(context).pop(_changed),
                        child: const Text('বন্ধ করুন'),
                      ),
                  ],
                ),
              ),
              if (_error != null)
                Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Text(_error!,
                      key: const ValueKey('workspace-error'),
                      style: const TextStyle(color: AppColors.danger)),
                ),
              if (profile.companies.length > 1) ...[
                const _SectionTitle('কোম্পানি'),
                for (final company in profile.companies)
                  _Choice(
                    key: ValueKey('company-${company.publicId}'),
                    label: company.name,
                    selected: company.publicId == profile.company.publicId,
                    onTap: _busy || company.publicId == profile.company.publicId
                        ? null
                        : () => _move(
                              company: company.publicId,
                              branch: WorkspaceSwitcher.allBranches,
                              close: false,
                            ),
                  ),
              ],
              if (profile.branches.isNotEmpty) ...[
                _SectionTitle(profile.company.name.isEmpty
                    ? 'শাখা'
                    : 'শাখা — ${profile.company.name}'),
                if (profile.branches.length > 1)
                  _Choice(
                    key: const ValueKey('branch-all'),
                    label: 'সব শাখা',
                    selected: allSelected,
                    onTap: _busy || allSelected
                        ? null
                        : () => _move(
                              company: profile.company.publicId,
                              branch: WorkspaceSwitcher.allBranches,
                              close: true,
                            ),
                  ),
                for (final branch in profile.branches)
                  _Choice(
                    key: ValueKey('branch-${branch.publicId}'),
                    label: branch.name,
                    selected: !allSelected && branch.publicId == profile.branch.publicId,
                    onTap: _busy ||
                            profile.branches.length <= 1 ||
                            (!allSelected && branch.publicId == profile.branch.publicId)
                        ? null
                        : () => _move(
                              company: profile.company.publicId,
                              branch: branch.publicId,
                              close: true,
                            ),
                  ),
                const Padding(
                  padding: EdgeInsets.fromLTRB(AppSpacing.md, AppSpacing.sm, AppSpacing.md, 0),
                  child: Text(
                    'আপনি যা লিখবেন তা বাছা শাখার নামেই বসবে। "সব শাখা" কেবল দেখার জন্য — '
                    'কাজের শাখা বদলায় না।',
                    style: TextStyle(fontSize: 12, color: AppColors.onSurfaceMuted),
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(AppSpacing.md, AppSpacing.md, AppSpacing.md, AppSpacing.xs),
        child: Text(text,
            style: const TextStyle(
                fontSize: 12, fontWeight: FontWeight.w700, color: AppColors.onSurfaceMuted)),
      );
}

class _Choice extends StatelessWidget {
  const _Choice({super.key, required this.label, required this.selected, this.onTap});

  final String label;
  final bool selected;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => ListTile(
        title: Text(label,
            style: TextStyle(fontWeight: selected ? FontWeight.w700 : FontWeight.w400)),
        trailing: selected ? const Icon(Icons.check, color: AppColors.primary) : null,
        onTap: onTap,
      );
}

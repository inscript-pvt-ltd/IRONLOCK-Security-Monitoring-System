import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../providers/app_providers.dart';
import '../theme/app_colors.dart';
import '../theme/app_gradients.dart';
import '../theme/app_spacing.dart';
import '../theme/app_typography.dart';
import '../theme/responsive.dart';
import '../widgets/app_button.dart';
import '../widgets/app_summary_row.dart';

/// Show via: showEndShiftSheet(context)
void showEndShiftSheet(BuildContext context) {
  showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.transparent,
    barrierColor: const Color(0xB8000000),
    builder: (_) => const EndShiftSheet(),
  );
}

class EndShiftSheet extends ConsumerStatefulWidget {
  const EndShiftSheet({super.key});

  @override
  ConsumerState<EndShiftSheet> createState() => _EndShiftSheetState();
}

class _EndShiftSheetState extends ConsumerState<EndShiftSheet> {
  // Preset early-end reasons. A category keeps supervisor reporting filterable;
  // the note captures the detail. "Incident / Emergency" stays near the top so
  // it's fast to reach when it matters most.
  static const _reasons = <String>[
    'Incident / Emergency',
    'Illness',
    'Relieved early',
    'Site closed',
    'Other',
  ];
  static const _minNoteLength = 10;

  String? _reason;
  final _noteCtrl = TextEditingController();

  @override
  void dispose() {
    _noteCtrl.dispose();
    super.dispose();
  }

  bool get _earlyValid =>
      _reason != null && _noteCtrl.text.trim().length >= _minNoteLength;

  String _formatTime(DateTime? dt) {
    if (dt == null) return '--:--';
    return '${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';
  }

  String _formatDuration(DateTime? start) {
    if (start == null) return '0h 0m';
    final d = DateTime.now().difference(start);
    return '${d.inHours}h ${d.inMinutes % 60}m';
  }

  void _confirm({required bool isEarly}) {
    Navigator.of(context).pop();
    ref.read(shiftProvider.notifier).end(
          endedEarly: isEarly,
          reason: isEarly ? _reason : null,
          note: isEarly ? _noteCtrl.text.trim() : null,
        );
    ref.read(zoneProvider.notifier).set(0);
    ref.read(zoneUpdatedAtProvider.notifier).reset();
    ref.read(activeTabProvider.notifier).setTab(0);
  }

  @override
  Widget build(BuildContext context) {
    final shift = ref.watch(shiftProvider);
    final zone = ref.watch(zoneProvider);
    final currentShift = ref.watch(currentShiftProvider);

    // "Early" = ending while we're still inside the scheduled window. The
    // server is the real authority, but this drives whether we require a reason.
    final isEarly = currentShift != null &&
        DateTime.now().isBefore(currentShift.scheduledEnd);

    return Container(
      decoration: const BoxDecoration(
        gradient: AppGradients.bottomSheet,
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
        border: Border(top: BorderSide(color: Color(0x0FFFFFFF))),
        boxShadow: [
          BoxShadow(color: Color(0x80000000), blurRadius: 40, offset: Offset(0, -8)),
        ],
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          // Lift the sheet above the keyboard when the note field is focused.
          padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(
              AppSpacing.xl, AppSpacing.base, AppSpacing.xl, AppSpacing.xl,
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                // Handle pill
                Center(
                  child: Container(
                    width: 40,
                    height: 4,
                    margin: const EdgeInsets.only(bottom: AppSpacing.base),
                    decoration: BoxDecoration(
                      color: const Color(0x1FFFFFFF),
                      borderRadius: BorderRadius.circular(2),
                    ),
                  ),
                ),

                Text(isEarly ? 'End Shift Early?' : 'End Shift?', style: AppType.h2),
                const SizedBox(height: AppSpacing.base),

                // Summary rows
                AppSummaryRow(label: 'Site', value: currentShift?.site?.name ?? '—'),
                AppSummaryRow(
                  label: 'Shift ID',
                  value: shift.shiftRef ?? '#--',
                ),
                AppSummaryRow(
                  label: 'Started',
                  value: _formatTime(shift.startTime),
                ),
                AppSummaryRow(
                  label: 'Duration',
                  value: _formatDuration(shift.startTime),
                ),
                AppSummaryRow(
                  label: 'Location',
                  value: zone == 2 ? 'Interrupted' : 'Active throughout',
                  valueColor: zone == 2 ? AppColors.warning : null,
                ),
                AppSummaryRow(
                  label: 'Welfare checks',
                  value: shift.welfareChecksTotal == 0
                      ? 'None'
                      : '${shift.welfareChecksPassed} / ${shift.welfareChecksTotal}',
                  valueColor: shift.welfareChecksTotal > 0 &&
                          shift.welfareChecksPassed < shift.welfareChecksTotal
                      ? AppColors.warning
                      : null,
                ),
                AppSummaryRow(
                  label: 'Photos',
                  value: shift.photosTotal == 0
                      ? 'None'
                      : '${shift.photosPassed} / ${shift.photosTotal}',
                  valueColor: shift.photosTotal > 0 &&
                          shift.photosPassed < shift.photosTotal
                      ? AppColors.warning
                      : null,
                  isLast: true,
                ),

                // Early-end reason capture — required so an early finish is
                // recorded and accountable for the supervisor, not silent.
                if (isEarly) _buildEarlyReason(context),

                const SizedBox(height: AppSpacing.xl),

                isEarly
                    ? AppButton(
                        label: 'End Shift Early',
                        variant: AppButtonVariant.danger,
                        enabled: _earlyValid,
                        onPressed: () => _confirm(isEarly: true),
                      )
                    : AppButton(
                        label: 'Confirm End Shift',
                        variant: AppButtonVariant.danger,
                        onPressed: () => _confirm(isEarly: false),
                      ),
                const SizedBox(height: AppSpacing.sm),
                AppButton(
                  label: 'Cancel',
                  variant: AppButtonVariant.secondary,
                  onPressed: () => Navigator.of(context).pop(),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildEarlyReason(BuildContext context) {
    final noteLen = _noteCtrl.text.trim().length;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(height: context.s(20)),
        Text('WHY ARE YOU ENDING EARLY?',
            style: AppType.section.copyWith(
              fontSize: context.sp(11),
              color: AppColors.warning,
            )),
        SizedBox(height: context.s(10)),
        Wrap(
          spacing: context.s(8),
          runSpacing: context.s(8),
          children: [
            for (final r in _reasons)
              _ReasonChip(
                label: r,
                selected: _reason == r,
                onTap: () => setState(() => _reason = r),
              ),
          ],
        ),
        SizedBox(height: context.s(12)),
        TextField(
          controller: _noteCtrl,
          onChanged: (_) => setState(() {}),
          minLines: 2,
          maxLines: 4,
          maxLength: 200,
          textCapitalization: TextCapitalization.sentences,
          style: AppType.body.copyWith(fontSize: context.sp(15)),
          cursorColor: AppColors.gold,
          decoration: InputDecoration(
            hintText: 'Add a brief note (required)…',
            hintStyle: AppType.body.copyWith(color: AppColors.placeholder),
            filled: true,
            fillColor: const Color(0xCC0A1931),
            contentPadding: const EdgeInsets.all(AppSpacing.base),
            counterStyle: AppType.micro.copyWith(color: AppColors.faint),
            border: _noteBorder(AppColors.border),
            enabledBorder: _noteBorder(AppColors.border),
            focusedBorder: _noteBorder(AppColors.gold, width: 1.5),
          ),
        ),
        if (!_earlyValid) ...[
          SizedBox(height: context.s(6)),
          Text(
            _reason == null
                ? 'Select a reason and add a brief note.'
                : 'Add at least $_minNoteLength characters ($noteLen/$_minNoteLength).',
            style: AppType.caption.copyWith(
              fontSize: context.sp(11),
              color: AppColors.muted,
            ),
          ),
        ],
      ],
    );
  }

  OutlineInputBorder _noteBorder(Color color, {double width = 1}) =>
      OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: BorderSide(color: color, width: width),
      );
}

// ── Selectable reason chip ────────────────────────────────────────────────

class _ReasonChip extends StatelessWidget {
  const _ReasonChip({
    required this.label,
    required this.selected,
    required this.onTap,
  });
  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 120),
        padding: EdgeInsets.symmetric(
          horizontal: context.s(14),
          vertical: context.s(9),
        ),
        decoration: BoxDecoration(
          color: selected ? const Color(0x26D4AF37) : const Color(0xCC0A1931),
          borderRadius: BorderRadius.circular(context.s(10)),
          border: Border.all(
            color: selected ? AppColors.gold : AppColors.border,
            width: selected ? 1.5 : 1,
          ),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (selected) ...[
              Icon(Icons.check_rounded, size: context.sp(14), color: AppColors.gold),
              SizedBox(width: context.s(5)),
            ],
            Text(
              label,
              style: AppType.label.copyWith(
                fontSize: context.sp(13),
                color: selected ? AppColors.gold : AppColors.muted,
                fontWeight: selected ? FontWeight.w600 : FontWeight.w500,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../providers/app_providers.dart';
import '../theme/app_colors.dart';
import '../theme/app_gradients.dart';
import '../theme/app_spacing.dart';
import '../theme/app_typography.dart';
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

class EndShiftSheet extends ConsumerWidget {
  const EndShiftSheet({super.key});

  String _formatTime(DateTime? dt) {
    if (dt == null) return '--:--';
    return '${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';
  }

  String _formatDuration(DateTime? start) {
    if (start == null) return '0h 0m';
    final d = DateTime.now().difference(start);
    return '${d.inHours}h ${d.inMinutes % 60}m';
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final shift = ref.watch(shiftProvider);
    final zone = ref.watch(zoneProvider);
    final currentShift = ref.watch(currentShiftProvider);

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

              Text('End Shift?', style: AppType.h2),
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
                    : '${shift.welfareChecksPassed} / ${shift.welfareChecksTotal}'
                        ' ${shift.welfareChecksPassed == shift.welfareChecksTotal ? "✓" : "⚠"}',
                valueColor: shift.welfareChecksTotal > 0 &&
                        shift.welfareChecksPassed < shift.welfareChecksTotal
                    ? AppColors.warning
                    : null,
              ),
              AppSummaryRow(
                label: 'Photos',
                value: shift.photosTotal == 0
                    ? 'None'
                    : '${shift.photosPassed} / ${shift.photosTotal}'
                        ' ${shift.photosPassed == shift.photosTotal ? "✓" : "⚠"}',
                valueColor: shift.photosTotal > 0 &&
                        shift.photosPassed < shift.photosTotal
                    ? AppColors.warning
                    : null,
                isLast: true,
              ),

              const SizedBox(height: AppSpacing.xl),

              AppButton(
                label: 'Confirm End Shift',
                variant: AppButtonVariant.danger,
                onPressed: () {
                  Navigator.of(context).pop();
                  ref.read(shiftProvider.notifier).end();
                  ref.read(zoneProvider.notifier).set(0);
                  ref.read(activeTabProvider.notifier).setTab(0);
                },
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
    );
  }
}

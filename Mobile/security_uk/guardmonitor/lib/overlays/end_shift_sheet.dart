import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/api_response.dart';
import '../models/current_shift_model.dart';
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
  bool _submitting = false;

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

  /// Early ends now go through supervisor approval: this submits the request
  /// (reason + note) and leaves the shift running. The guard ends it only once
  /// approval comes back on the next `GET /shifts/current` poll.
  Future<void> _submitRequest() async {
    if (_submitting) return;
    setState(() => _submitting = true);
    try {
      await ref.read(shiftProvider.notifier).requestEarlyEnd(
            reason: _reason!,
            note: _noteCtrl.text.trim(),
          );
      if (!mounted) return;
      Navigator.of(context).pop();
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Early-end request sent — waiting for supervisor approval.'),
          duration: Duration(seconds: 4),
        ),
      );
    } on DioException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(ApiError.fromDioException(e).message),
          duration: const Duration(seconds: 4),
        ),
      );
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Could not send the request. Please try again.'),
          duration: Duration(seconds: 4),
        ),
      );
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  /// Actually closes the shift. Used for a normal (on-time) end, and for an
  /// early end that has already been approved (reason/note carried from the
  /// approved request). Surfaces named 409 errors so the guard knows exactly
  /// why the server rejected the end rather than seeing a raw API message.
  Future<void> _confirmEnd({required bool isEarly, String? reason, String? note}) async {
    if (_submitting) return;
    setState(() => _submitting = true);
    try {
      await ref.read(shiftProvider.notifier).end(
            endedEarly: isEarly,
            reason: reason,
            note: note,
          );
      if (!mounted) return;
      ref.read(zoneProvider.notifier).set(0);
      ref.read(zoneUpdatedAtProvider.notifier).reset();
      ref.read(activeTabProvider.notifier).setTab(0);
      Navigator.of(context).pop();
    } on DioException catch (e) {
      if (!mounted) return;
      setState(() => _submitting = false);
      final apiError = ApiError.fromDioException(e);
      final message = switch (apiError.code) {
        'END_BEFORE_SCHEDULED' =>
          "Your shift hasn't reached its scheduled end time. If you need to "
              "leave early, submit an early-end request.",
        'EARLY_END_NOT_APPROVED' =>
          "Your early-end request hasn't been approved yet. Keep working until "
              "a supervisor approves it.",
        'EARLY_END_NOT_APPLICABLE' =>
          'No active early-end request to fulfil. Submit a request first.',
        _ => apiError.message,
      };
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('⚠ $message'),
          duration: const Duration(seconds: 5),
        ),
      );
    } catch (_) {
      if (!mounted) return;
      setState(() => _submitting = false);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Connection error. Please try again.'),
          duration: Duration(seconds: 4),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final shift = ref.watch(shiftProvider);
    final zone = ref.watch(zoneProvider);
    final currentShift = ref.watch(currentShiftProvider);

    // "Early" = ending while we're still inside the scheduled window. Used for
    // the title label and as a fallback for needsRequest when the server hasn't
    // sent can_request_early_end yet.
    final isEarly = currentShift != null &&
        DateTime.now().isBefore(currentShift.scheduledEnd);

    // Early ends require supervisor approval. The status (mirrored from the
    // server on each poll) decides which face of the sheet we show.
    final pending = currentShift?.earlyEndPending ?? false;
    final approved = currentShift?.earlyEndApproved ?? false;
    // Prefer the server flag so timing + existing-request state is computed
    // server-side (immune to device clock skew). Fall back to device-clock
    // isEarly when the server hasn't sent the flag yet (older backend).
    final needsRequest =
        currentShift?.canRequestEarlyEnd ?? (isEarly && !pending && !approved);

    final title = pending
        ? 'Awaiting Approval'
        : approved
            ? 'Approved — End Shift'
            : isEarly
                ? 'End Shift Early?'
                : 'End Shift?';

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

                Text(title, style: AppType.h2),
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
                  value: zone == 0
                      ? 'Active throughout'
                      : zone == 1
                          ? 'Left zone'
                          : 'Interrupted',
                  valueColor: zone != 0 ? AppColors.warning : null,
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

                // Status-driven body:
                //  • pending  → read-only "waiting for approval" notice
                //  • approved → show the approved reason, then allow End
                //  • needsRequest → capture reason + note for a fresh request
                if (pending)
                  _buildPendingNotice(context)
                else if (approved)
                  _buildApprovedNotice(context, currentShift!)
                else if (needsRequest)
                  // Early-end reason capture — required so an early finish is
                  // recorded and accountable for the supervisor, not silent.
                  _buildEarlyReason(context),

                const SizedBox(height: AppSpacing.xl),

                if (pending)
                  // Nothing to confirm yet — the guard waits. Only Close below.
                  const SizedBox.shrink()
                else if (approved)
                  AppButton(
                    label: _submitting ? 'Ending…' : 'End Shift',
                    variant: AppButtonVariant.danger,
                    onPressed: _submitting
                        ? null
                        : () => _confirmEnd(
                              isEarly: true,
                              reason: currentShift!.earlyEndReason ?? _reason,
                              note: currentShift.earlyEndNote ??
                                  _noteCtrl.text.trim(),
                            ),
                  )
                else if (needsRequest)
                  AppButton(
                    label: _submitting ? 'Sending…' : 'Request Early End',
                    variant: AppButtonVariant.danger,
                    enabled: _earlyValid && !_submitting,
                    onPressed: _submitRequest,
                  )
                else
                  AppButton(
                    label: _submitting ? 'Ending…' : 'Confirm End Shift',
                    variant: AppButtonVariant.danger,
                    onPressed:
                        _submitting ? null : () => _confirmEnd(isEarly: false),
                  ),
                const SizedBox(height: AppSpacing.sm),
                AppButton(
                  label: pending ? 'Close' : 'Cancel',
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

  // Shown while a request is pending — the guard cannot end yet.
  Widget _buildPendingNotice(BuildContext context) {
    final reason = ref.read(currentShiftProvider)?.earlyEndReason;
    return _noticeCard(
      context,
      icon: Icons.hourglass_top_rounded,
      tint: AppColors.warning,
      title: 'WAITING FOR SUPERVISOR APPROVAL',
      body: reason == null
          ? 'Your early-end request has been sent. You can end the shift once a '
              'supervisor approves it — keep working until then.'
          : 'Your early-end request ($reason) has been sent. You can end the '
              'shift once a supervisor approves it — keep working until then.',
    );
  }

  // Shown once a supervisor has approved — the End button is now live.
  Widget _buildApprovedNotice(BuildContext context, CurrentShiftModel currentShift) {
    final reason = currentShift.earlyEndReason;
    final note = currentShift.earlyEndNote;
    final detail = StringBuffer(
      'A supervisor approved your early end. Tap “End Shift” to finish.',
    );
    if (reason != null) detail.write('\n\nReason: $reason');
    if (note != null && note.isNotEmpty) detail.write('\nNote: $note');
    return _noticeCard(
      context,
      icon: Icons.verified_rounded,
      tint: AppColors.success,
      title: 'APPROVED',
      body: detail.toString(),
    );
  }

  Widget _noticeCard(
    BuildContext context, {
    required IconData icon,
    required Color tint,
    required String title,
    required String body,
  }) {
    return Container(
      margin: EdgeInsets.only(top: context.s(20)),
      padding: EdgeInsets.all(context.s(14)),
      decoration: BoxDecoration(
        color: const Color(0xCC0A1931),
        borderRadius: BorderRadius.circular(context.s(12)),
        border: Border.all(color: tint.withValues(alpha: 0.5)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: context.sp(20), color: tint),
          SizedBox(width: context.s(12)),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: AppType.section.copyWith(
                    fontSize: context.sp(11),
                    color: tint,
                  ),
                ),
                SizedBox(height: context.s(6)),
                Text(
                  body,
                  style: AppType.body.copyWith(
                    fontSize: context.sp(13),
                    color: AppColors.muted,
                    height: 1.4,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
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

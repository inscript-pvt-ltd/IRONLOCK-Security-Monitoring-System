import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../services/shift_access_link.dart';
import 'auth_provider.dart';

/// UI state for redeeming a Shift Access Link (SSO). The deep-link handler drives
/// this; the login screen watches it to show a "signing you in…" overlay and any
/// failure message. On success the auth state flips to signed-in and `main.dart`
/// swaps in the home screen automatically — so success just returns to idle.
enum ShiftAccessStatus { idle, redeeming, error }

class ShiftAccessState {
  const ShiftAccessState({
    this.status = ShiftAccessStatus.idle,
    this.message,
    this.windowExpired = false,
  });

  final ShiftAccessStatus status;
  final String? message;
  final bool windowExpired;

  bool get isRedeeming => status == ShiftAccessStatus.redeeming;
}

class ShiftAccessNotifier extends Notifier<ShiftAccessState> {
  @override
  ShiftAccessState build() => const ShiftAccessState();

  /// Redeem [token] and sign in. Single-use — never auto-retry; a failure routes
  /// the guard back to the login screen with the mapped message.
  Future<void> redeem(String token) async {
    state = const ShiftAccessState(status: ShiftAccessStatus.redeeming);
    try {
      await ref.read(authProvider.notifier).signInWithShiftAccess(token);
      state = const ShiftAccessState(); // success → auth drives navigation
    } on ShiftAccessException catch (e) {
      state = ShiftAccessState(
        status: ShiftAccessStatus.error,
        message: e.message,
        windowExpired: e.windowExpired,
      );
    } catch (_) {
      state = const ShiftAccessState(
        status: ShiftAccessStatus.error,
        message:
            'Could not sign in with this link. Check your connection and try again.',
      );
    }
  }

  void clear() => state = const ShiftAccessState();
}

final shiftAccessProvider =
    NotifierProvider<ShiftAccessNotifier, ShiftAccessState>(
        ShiftAccessNotifier.new);

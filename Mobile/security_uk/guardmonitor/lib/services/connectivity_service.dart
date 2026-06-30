import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// A plain `Stream<bool>` of online/offline: the current state first, then every
/// change. Shared by [networkStatusProvider] (UI) and the Phase 7 flush engine
/// (which needs a raw stream to detect the offline→online reconnect).
Stream<bool> connectivityBoolStream() async* {
  final initial = await Connectivity().checkConnectivity();
  yield initial.any((r) => r != ConnectivityResult.none);
  yield* Connectivity().onConnectivityChanged.map(
    (results) => results.any((r) => r != ConnectivityResult.none),
  );
}

final networkStatusProvider = StreamProvider<bool>((ref) {
  // Emit the current state immediately so the UI is correct on launch without
  // waiting for a connectivity change event to fire. On iOS simulator,
  // onConnectivityChanged alone is unreliable for WiFi.
  return connectivityBoolStream();
});

final isOnlineProvider = Provider<bool>((ref) {
  return ref.watch(networkStatusProvider).maybeWhen(
    data: (online) => online,
    orElse: () => true, // assume online until proven otherwise
  );
});

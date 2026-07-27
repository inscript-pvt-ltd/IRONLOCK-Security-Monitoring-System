import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import '../theme/app_colors.dart';
import '../theme/app_typography.dart';
import '../theme/responsive.dart';

/// Full-screen, non-dismissible gate shown while the device's location services
/// are switched off. GPS tracking is core to a shift (the dashboard shows the
/// guard as live from their pings), so the app must not be usable with location
/// off — otherwise a guard could work a whole shift invisible on the map.
///
/// It clears itself the moment location is re-enabled (the caller rebuilds off
/// `locationServiceEnabledProvider`). The button opens the OS location settings;
/// a `PopScope(canPop:false)` stops the back button from dismissing it.
class LocationRequiredOverlay extends StatelessWidget {
  const LocationRequiredOverlay({super.key});

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      child: Material(
        color: const Color(0xF007111F),
        child: SafeArea(
          child: Center(
            child: Padding(
              padding: EdgeInsets.symmetric(horizontal: context.s(32)),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Container(
                    width: context.s(88),
                    height: context.s(88),
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: const Color(0x1FDC2626),
                      border: Border.all(color: const Color(0x4DDC2626), width: 2),
                    ),
                    child: Icon(
                      Icons.location_off_rounded,
                      color: AppColors.danger,
                      size: context.s(44),
                    ),
                  ),
                  SizedBox(height: context.s(24)),
                  Text(
                    'Location is turned off',
                    style: AppType.h2.copyWith(fontSize: context.sp(22)),
                    textAlign: TextAlign.center,
                  ),
                  SizedBox(height: context.s(12)),
                  Text(
                    'Your location is required for the whole shift so your site '
                    'knows you are safe and on-post. Turn location services back '
                    'on to continue using the app.',
                    style: AppType.caption.copyWith(fontSize: context.sp(14)),
                    textAlign: TextAlign.center,
                  ),
                  SizedBox(height: context.s(28)),
                  SizedBox(
                    width: double.infinity,
                    child: FilledButton(
                      style: FilledButton.styleFrom(
                        backgroundColor: AppColors.gold,
                        foregroundColor: const Color(0xFF07111F),
                        padding: EdgeInsets.symmetric(vertical: context.s(16)),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(14),
                        ),
                      ),
                      onPressed: () => Geolocator.openLocationSettings(),
                      child: Text(
                        'Open Location Settings',
                        style: AppType.label.copyWith(
                          color: const Color(0xFF07111F),
                          fontSize: context.sp(15),
                        ),
                      ),
                    ),
                  ),
                  SizedBox(height: context.s(14)),
                  Text(
                    'This screen closes automatically once location is on.',
                    style: AppType.caption.copyWith(
                      fontSize: context.sp(12),
                      color: AppColors.muted,
                    ),
                    textAlign: TextAlign.center,
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

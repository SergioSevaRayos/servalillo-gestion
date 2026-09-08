import 'package:battery_plus/battery_plus.dart';
import 'package:geolocator/geolocator.dart';

import '../models/tracked_position.dart';

/// Toma una lectura de posición. Interfaz para poder sustituirla en tests.
abstract class LocationSampler {
  Future<TrackedPosition?> sample();
}

class GeolocatorSampler implements LocationSampler {
  GeolocatorSampler({Battery? battery}) : _battery = battery ?? Battery();

  final Battery _battery;

  @override
  Future<TrackedPosition?> sample() async {
    if (!await Geolocator.isLocationServiceEnabled()) return null;

    final LocationPermission perm = await Geolocator.checkPermission();
    if (perm == LocationPermission.denied ||
        perm == LocationPermission.deniedForever) {
      return null;
    }

    Position? pos;
    try {
      pos = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 20),
        ),
      );
    } catch (_) {
      pos = await Geolocator.getLastKnownPosition();
    }
    if (pos == null) return null;

    int? battery;
    try {
      battery = await _battery.batteryLevel;
    } catch (_) {
      battery = null;
    }

    return TrackedPosition(
      latitude: pos.latitude,
      longitude: pos.longitude,
      recordedAt: pos.timestamp,
      accuracyM: pos.accuracy,
      speedMps: pos.speed,
      headingDeg: pos.heading,
      batteryLevel: battery,
    );
  }
}

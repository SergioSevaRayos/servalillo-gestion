import 'package:flutter_test/flutter_test.dart';
import 'package:servalillo_tracker/models/tracked_position.dart';

void main() {
  test('toJson usa las claves del servidor y recorded_at en UTC', () {
    final pos = TrackedPosition(
      latitude: 36.8768801234,
      longitude: -2.4430871234,
      recordedAt: DateTime.utc(2026, 9, 8, 10, 30),
    );
    final json = pos.toJson();

    expect(json['lat'], 36.8768801);
    expect(json['lng'], -2.4430871);
    expect(json['recorded_at'], '2026-09-08T10:30:00.000Z');
    expect(json.containsKey('accuracy_m'), isFalse);
    expect(json.containsKey('speed_mps'), isFalse);
    expect(json.containsKey('heading_deg'), isFalse);
    expect(json.containsKey('battery_level'), isFalse);
  });

  test(
    'omite speed/heading negativos (geolocator los devuelve así si no los sabe)',
    () {
      final json = TrackedPosition(
        latitude: 1,
        longitude: 2,
        recordedAt: DateTime.utc(2026, 1, 1),
        speedMps: -1,
        headingDeg: -1,
        accuracyM: 12.5,
      ).toJson();

      expect(json.containsKey('speed_mps'), isFalse);
      expect(json.containsKey('heading_deg'), isFalse);
      expect(json['accuracy_m'], 12.5);
    },
  );

  test('battery_level se recorta a 0..100', () {
    expect(
      TrackedPosition(
        latitude: 1,
        longitude: 2,
        recordedAt: DateTime.utc(2026),
        batteryLevel: 150,
      ).toJson()['battery_level'],
      100,
    );
    expect(
      TrackedPosition(
        latitude: 1,
        longitude: 2,
        recordedAt: DateTime.utc(2026),
        batteryLevel: -5,
      ).toJson()['battery_level'],
      0,
    );
  });

  test('heading se recorta a 0..360', () {
    final json = TrackedPosition(
      latitude: 1,
      longitude: 2,
      recordedAt: DateTime.utc(2026),
      headingDeg: 400,
    ).toJson();
    expect(json['heading_deg'], 360);
  });

  test('toRow/fromRow ida y vuelta', () {
    final pos = TrackedPosition(
      latitude: 36.5,
      longitude: -2.1,
      recordedAt: DateTime.utc(2026, 3, 4, 5, 6, 7),
      accuracyM: 8,
      speedMps: 3.2,
      headingDeg: 90,
      batteryLevel: 55,
    );
    final back = TrackedPosition.fromRow(<String, Object?>{
      'id': 7,
      ...pos.toRow(),
    });

    expect(back.id, 7);
    expect(back.latitude, 36.5);
    expect(back.longitude, -2.1);
    expect(back.recordedAt, DateTime.utc(2026, 3, 4, 5, 6, 7));
    expect(back.accuracyM, 8);
    expect(back.speedMps, 3.2);
    expect(back.headingDeg, 90);
    expect(back.batteryLevel, 55);
  });
}

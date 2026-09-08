import 'package:flutter_test/flutter_test.dart';
import 'package:servalillo_tracker/models/tracking_config.dart';

void main() {
  test('fromJson lee todos los campos', () {
    final c = TrackingConfig.fromJson(<String, dynamic>{
      'ping_interval_seconds': 30,
      'ping_distance_meters': 60,
      'pause_start': '23:00',
      'pause_end': '06:00',
    });
    expect(c.pingIntervalSeconds, 30);
    expect(c.pingDistanceMeters, 60);
    expect(c.pauseStart, '23:00');
    expect(c.pauseEnd, '06:00');
    expect(c.pingInterval, const Duration(seconds: 30));
  });

  test('aplica defaults y clamps ante datos ausentes o basura', () {
    final c = TrackingConfig.fromJson(<String, dynamic>{
      'ping_interval_seconds': 1,
      'pause_start': 'no-es-hora',
    });
    expect(c.pingIntervalSeconds, 5); // clamp inferior
    expect(c.pingDistanceMeters, TrackingConfig.fallback.pingDistanceMeters);
    expect(c.pauseStart, TrackingConfig.fallback.pauseStart);
    expect(c.pauseEnd, TrackingConfig.fallback.pauseEnd);
  });

  test('normaliza "H:mm" a "HH:mm"', () {
    final c = TrackingConfig.fromJson(<String, dynamic>{'pause_end': '5:00'});
    expect(c.pauseEnd, '05:00');
  });

  test('encode/decode ida y vuelta', () {
    const original = TrackingConfig(
      pingIntervalSeconds: 45,
      pingDistanceMeters: 75,
      pauseStart: '22:00',
      pauseEnd: '05:00',
    );
    final decoded = TrackingConfig.decode(original.encode());
    expect(decoded.toJson(), original.toJson());
  });

  test('decode devuelve fallback ante null / vacío / json inválido', () {
    expect(
      TrackingConfig.decode(null).toJson(),
      TrackingConfig.fallback.toJson(),
    );
    expect(
      TrackingConfig.decode('').toJson(),
      TrackingConfig.fallback.toJson(),
    );
    expect(
      TrackingConfig.decode('{no json').toJson(),
      TrackingConfig.fallback.toJson(),
    );
  });
}

import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:servalillo_tracker/core/clock.dart';
import 'package:servalillo_tracker/data/position_queue.dart';
import 'package:servalillo_tracker/data/secure_store.dart';
import 'package:servalillo_tracker/models/tracked_position.dart';
import 'package:servalillo_tracker/models/tracking_config.dart';
import 'package:servalillo_tracker/services/location_sampler.dart';
import 'package:servalillo_tracker/services/sync_service.dart';
import 'package:servalillo_tracker/services/tracker_api.dart';
import 'package:servalillo_tracker/services/tracker_controller.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

import 'support/test_db.dart';

class FakeSampler implements LocationSampler {
  FakeSampler(this._value);
  final TrackedPosition? _value;
  int calls = 0;

  @override
  Future<TrackedPosition?> sample() async {
    calls++;
    return _value;
  }
}

TrackedPosition pos() => TrackedPosition(
  latitude: 36.5,
  longitude: -2.4,
  recordedAt: DateTime.utc(2026, 9, 8, 10),
);

void main() {
  late Database db;
  late PositionQueue queue;
  late SecureStore store;

  setUp(() async {
    db = await openTestDatabase();
    queue = PositionQueue(db);
    store = SecureStore(InMemoryKeyValueStore());
    await store.setToken('tok');
    await store.setConfig(
      const TrackingConfig(
        pingIntervalSeconds: 45,
        pingDistanceMeters: 75,
        pauseStart: '22:00',
        pauseEnd: '05:00',
      ),
    );
  });

  tearDown(() async => db.close());

  TrackerController controller({
    required FakeSampler sampler,
    required http.Response Function(http.Request) api,
    required DateTime now,
  }) {
    return TrackerController(
      queue: queue,
      store: store,
      sampler: sampler,
      sync: SyncService(
        queue: queue,
        store: store,
        api: TrackerApi(MockClient((r) async => api(r)), 'https://s.test'),
      ),
      clock: FixedClock(now),
    );
  }

  test('dentro de la pausa: no muestrea ni envía', () async {
    final sampler = FakeSampler(pos());
    final c = controller(
      sampler: sampler,
      api: (_) => fail('no debería llamar a la API en pausa'),
      now: DateTime(2026, 9, 8, 23, 30),
    );
    final r = await c.tick();
    expect(r.action, TickAction.paused);
    expect(sampler.calls, 0);
    expect(r.message, contains('05:00'));
  });

  test('fuera de pausa: muestrea, encola y envía', () async {
    final sampler = FakeSampler(pos());
    final c = controller(
      sampler: sampler,
      api: (_) =>
          http.Response(jsonEncode(<String, dynamic>{'accepted': 1}), 202),
      now: DateTime(2026, 9, 8, 12),
    );
    final r = await c.tick();
    expect(sampler.calls, 1);
    expect(r.action, TickAction.tracking);
    expect(await queue.count(), 0);
  });

  test(
    'sampler nulo: no encola pero intenta vaciar la cola existente',
    () async {
      await queue.enqueue(pos());
      final sampler = FakeSampler(null);
      final c = controller(
        sampler: sampler,
        api: (_) =>
            http.Response(jsonEncode(<String, dynamic>{'accepted': 1}), 202),
        now: DateTime(2026, 9, 8, 12),
      );
      final r = await c.tick();
      expect(r.action, TickAction.tracking);
      expect(await queue.count(), 0);
    },
  );

  test('token revocado → stopRevoked', () async {
    await queue.enqueue(pos());
    final c = controller(
      sampler: FakeSampler(null),
      api: (_) => http.Response('{}', 401),
      now: DateTime(2026, 9, 8, 12),
    );
    final r = await c.tick();
    expect(r.action, TickAction.stopRevoked);
  });

  test(
    'sin red con Retry-After → retryLater con skipTicks calculado',
    () async {
      await queue.enqueue(pos());
      final c = controller(
        sampler: FakeSampler(null),
        api: (_) => http.Response(
          '{}',
          503,
          headers: <String, String>{'retry-after': '135'},
        ),
        now: DateTime(2026, 9, 8, 12),
      );
      final r = await c.tick();
      expect(r.action, TickAction.retryLater);
      expect(r.skipTicks, 3); // ceil(135 / 45)
      expect(r.pending, 1);
    },
  );
}

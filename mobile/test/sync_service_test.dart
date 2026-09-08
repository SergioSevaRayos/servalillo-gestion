import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:servalillo_tracker/data/position_queue.dart';
import 'package:servalillo_tracker/data/secure_store.dart';
import 'package:servalillo_tracker/models/tracked_position.dart';
import 'package:servalillo_tracker/services/sync_service.dart';
import 'package:servalillo_tracker/services/tracker_api.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

import 'support/test_db.dart';

TrackedPosition sample(int s) => TrackedPosition(
  latitude: 36.5,
  longitude: -2.4,
  recordedAt: DateTime.utc(2026, 9, 8, 10, 0, s),
);

void main() {
  late Database db;
  late PositionQueue queue;
  late SecureStore store;

  setUp(() async {
    db = await openTestDatabase();
    queue = PositionQueue(db);
    store = SecureStore(InMemoryKeyValueStore());
  });

  tearDown(() async => db.close());

  SyncService sync(TrackerApi api) =>
      SyncService(queue: queue, store: store, api: api);
  TrackerApi mock(http.Response Function(http.Request) h) =>
      TrackerApi(MockClient((r) async => h(r)), 'https://s.test');

  test('sin token → notEnrolled', () async {
    final result = await sync(mock((_) => http.Response('{}', 202))).flush();
    expect(result.outcome, FlushOutcome.notEnrolled);
  });

  test('vacía la cola en lotes y borra tras 2xx', () async {
    await store.setToken('tok');
    for (int i = 0; i < 3; i++) {
      await queue.enqueue(sample(i));
    }
    int calls = 0;
    final result = await sync(
      mock((r) {
        calls++;
        final body = jsonDecode(r.body) as Map<String, dynamic>;
        expect((body['positions'] as List).length, 3);
        return http.Response(jsonEncode(<String, dynamic>{'accepted': 3}), 202);
      }),
    ).flush();

    expect(calls, 1);
    expect(result.outcome, FlushOutcome.sent);
    expect(result.sent, 3);
    expect(await queue.count(), 0);
    expect(await store.lastSentAt, isNotNull);
  });

  test('cola vacía → idle', () async {
    await store.setToken('tok');
    final result = await sync(mock((_) => http.Response('{}', 202))).flush();
    expect(result.outcome, FlushOutcome.idle);
  });

  test('422 descarta el lote y continúa', () async {
    await store.setToken('tok');
    await queue.enqueue(sample(0));
    final result = await sync(mock((_) => http.Response('{}', 422))).flush();
    expect(result.outcome, FlushOutcome.idle);
    expect(await queue.count(), 0);
  });

  test('401 → stopRevoked y marca el estado', () async {
    await store.setToken('tok');
    await queue.enqueue(sample(0));
    final result = await sync(mock((_) => http.Response('{}', 401))).flush();
    expect(result.outcome, FlushOutcome.stopRevoked);
    expect(await store.state, EnrolState.revoked);
    expect(await queue.count(), 1); // no se pierde nada
  });

  test('403 → stopDeactivated', () async {
    await store.setToken('tok');
    await queue.enqueue(sample(0));
    final result = await sync(mock((_) => http.Response('{}', 403))).flush();
    expect(result.outcome, FlushOutcome.stopDeactivated);
    expect(await store.state, EnrolState.deactivated);
  });

  test('error de red → retryLater conservando la cola', () async {
    await store.setToken('tok');
    await queue.enqueue(sample(0));
    final result = await sync(
      mock(
        (_) => http.Response(
          '{}',
          503,
          headers: <String, String>{'retry-after': '30'},
        ),
      ),
    ).flush();
    expect(result.outcome, FlushOutcome.retryLater);
    expect(result.retryAfter, const Duration(seconds: 30));
    expect(await queue.count(), 1);
  });
}

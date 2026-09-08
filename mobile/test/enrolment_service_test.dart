import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:servalillo_tracker/core/backoff.dart';
import 'package:servalillo_tracker/data/secure_store.dart';
import 'package:servalillo_tracker/services/enrolment_service.dart';
import 'package:servalillo_tracker/services/tracker_api.dart';

EnrolmentService service(
  SecureStore store,
  http.Response Function(http.Request) handler, {
  bool configured = true,
}) {
  return EnrolmentService(
    store: store,
    api: TrackerApi(MockClient((r) async => handler(r)), 'https://s.test'),
    backoff: ExponentialBackoff(
      base: Duration.zero,
      jitter: 0,
      max: Duration.zero,
    ),
    configured: configured,
    secret: 's3cr3t',
    appVersion: '1.0.0',
  );
}

http.Response okEnrolment() => http.Response(
  jsonEncode(<String, dynamic>{
    'token': 'tok-1',
    'device_id': 9,
    'tracking': <String, dynamic>{'ping_interval_seconds': 40},
  }),
  201,
);

void main() {
  test('sin configurar → outcome de error, sin llamar a la API', () async {
    final store = SecureStore(InMemoryKeyValueStore());
    final outcome = await service(
      store,
      (_) => fail('no debería llamar'),
      configured: false,
    ).ensureEnrolled();
    expect(outcome.enrolled, isFalse);
    expect(outcome.error, isNotNull);
  });

  test(
    'enrola, persiste token/deviceId/config/estado y genera install_identifier',
    () async {
      final store = SecureStore(InMemoryKeyValueStore());
      final outcome = await service(
        store,
        (_) => okEnrolment(),
      ).ensureEnrolled();

      expect(outcome.enrolled, isTrue);
      expect(await store.token, 'tok-1');
      expect(await store.deviceId, 9);
      expect(await store.state, EnrolState.enrolled);
      expect((await store.config).pingIntervalSeconds, 40);
      expect(await store.installIdentifier, isNotNull);
    },
  );

  test('si ya está enrolado con token no vuelve a llamar', () async {
    final store = SecureStore(InMemoryKeyValueStore());
    await store.setToken('ya-tengo');
    await store.setState(EnrolState.enrolled);

    final outcome = await service(
      store,
      (_) => fail('no debería llamar'),
    ).ensureEnrolled();
    expect(outcome.enrolled, isTrue);
  });

  test('reutiliza el mismo install_identifier entre intentos', () async {
    final store = SecureStore(InMemoryKeyValueStore());
    await store.setInstallIdentifier('fijo-123');
    await service(store, (r) {
      final body = jsonDecode(r.body) as Map<String, dynamic>;
      expect(body['install_identifier'], 'fijo-123');
      return okEnrolment();
    }).ensureEnrolled();
  });

  test('403 → corta sin reintentar', () async {
    final store = SecureStore(InMemoryKeyValueStore());
    int calls = 0;
    final outcome = await service(store, (_) {
      calls++;
      return http.Response('{}', 403);
    }).ensureEnrolled();

    expect(calls, 1);
    expect(outcome.enrolled, isFalse);
    expect(await store.state, isNot(EnrolState.enrolled));
  });

  test('reintenta ante fallo de red y acaba enrolando', () async {
    final store = SecureStore(InMemoryKeyValueStore());
    int calls = 0;
    final outcome = await service(store, (_) {
      calls++;
      if (calls < 3) return http.Response('boom', 500);
      return okEnrolment();
    }).ensureEnrolled(maxAttempts: 5);

    expect(calls, 3);
    expect(outcome.enrolled, isTrue);
  });

  test('reEnrol limpia token y estado antes de volver a enrolar', () async {
    final store = SecureStore(InMemoryKeyValueStore());
    await store.setToken('viejo');
    await store.setState(EnrolState.revoked);

    final outcome = await service(store, (_) => okEnrolment()).reEnrol();
    expect(outcome.enrolled, isTrue);
    expect(await store.token, 'tok-1');
    expect(await store.state, EnrolState.enrolled);
  });
}

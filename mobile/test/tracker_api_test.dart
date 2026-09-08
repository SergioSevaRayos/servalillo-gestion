import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:servalillo_tracker/models/api_exceptions.dart';
import 'package:servalillo_tracker/services/tracker_api.dart';

TrackerApi apiThatReturns(
  http.Response Function(http.Request request) handler,
) {
  return TrackerApi(
    MockClient((http.Request r) async => handler(r)),
    'https://srv.test/',
  );
}

void main() {
  group('register', () {
    test('201 → EnrolmentResult con token y config', () async {
      final api = apiThatReturns((r) {
        expect(r.url.path, '/api/device/register');
        final body = jsonDecode(r.body) as Map<String, dynamic>;
        expect(body['install_identifier'], 'inst-1');
        expect(body['secret'], 's3cr3t');
        return http.Response(
          jsonEncode(<String, dynamic>{
            'token': 'tok-abc',
            'device_id': 12,
            'tracking': <String, dynamic>{'ping_interval_seconds': 30},
          }),
          201,
        );
      });

      final res = await api.register(
        installIdentifier: 'inst-1',
        secret: 's3cr3t',
      );
      expect(res.token, 'tok-abc');
      expect(res.deviceId, 12);
      expect(res.tracking.pingIntervalSeconds, 30);
    });

    test('403 → EnrolmentRejected', () async {
      final api = apiThatReturns((_) => http.Response('{}', 403));
      expect(
        api.register(installIdentifier: 'x', secret: 'y'),
        throwsA(isA<EnrolmentRejected>()),
      );
    });

    test('500 → TransientNetworkError', () async {
      final api = apiThatReturns((_) => http.Response('boom', 500));
      expect(
        api.register(installIdentifier: 'x', secret: 'y'),
        throwsA(isA<TransientNetworkError>()),
      );
    });
  });

  group('sendBatch', () {
    test('202 → nº aceptadas del cuerpo', () async {
      final api = apiThatReturns((r) {
        expect(r.headers['authorization'], 'Bearer tok');
        return http.Response(jsonEncode(<String, dynamic>{'accepted': 3}), 202);
      });
      final n = await api.sendBatch(
        token: 'tok',
        positions: <Map<String, dynamic>>[{}, {}, {}, {}],
      );
      expect(n, 3);
    });

    test('2xx sin cuerpo → asume todas aceptadas', () async {
      final api = apiThatReturns((_) => http.Response('', 200));
      final n = await api.sendBatch(
        token: 'tok',
        positions: <Map<String, dynamic>>[{}, {}],
      );
      expect(n, 2);
    });

    test('401 → TokenRevoked', () async {
      final api = apiThatReturns((_) => http.Response('{}', 401));
      expect(
        api.sendBatch(token: 't', positions: const []),
        throwsA(isA<TokenRevoked>()),
      );
    });

    test('403 → DeviceDeactivated', () async {
      final api = apiThatReturns((_) => http.Response('{}', 403));
      expect(
        api.sendBatch(token: 't', positions: const []),
        throwsA(isA<DeviceDeactivated>()),
      );
    });

    test('422 → BatchRejected', () async {
      final api = apiThatReturns((_) => http.Response('{}', 422));
      expect(
        api.sendBatch(token: 't', positions: const []),
        throwsA(isA<BatchRejected>()),
      );
    });

    test(
      '429 con Retry-After → TransientNetworkError con retryAfter',
      () async {
        final api = apiThatReturns(
          (_) => http.Response(
            '{}',
            429,
            headers: <String, String>{'retry-after': '120'},
          ),
        );
        try {
          await api.sendBatch(token: 't', positions: const []);
          fail('debería lanzar');
        } on TransientNetworkError catch (e) {
          expect(e.retryAfter, const Duration(seconds: 120));
        }
      },
    );
  });

  group('fetchDevice', () {
    test('200 → DeviceStatus', () async {
      final api = apiThatReturns(
        (_) => http.Response(
          jsonEncode(<String, dynamic>{
            'device_id': 5,
            'label': 'Camión 1',
            'is_active': true,
            'driver': <String, dynamic>{'name': 'Pedro'},
            'tracking': <String, dynamic>{},
            'server_time': '2026-09-08T10:00:00Z',
          }),
          200,
        ),
      );
      final s = await api.fetchDevice(token: 't');
      expect(s.deviceId, 5);
      expect(s.driverName, 'Pedro');
      expect(s.isAssigned, isTrue);
      expect(s.isActive, isTrue);
      expect(s.serverTime, isNotNull);
    });

    test('401 → TokenRevoked', () async {
      final api = apiThatReturns((_) => http.Response('{}', 401));
      expect(api.fetchDevice(token: 't'), throwsA(isA<TokenRevoked>()));
    });
  });

  test('error de red del cliente → TransientNetworkError', () async {
    final api = TrackerApi(
      MockClient((_) async => throw http.ClientException('sin red')),
      'https://srv.test',
    );
    expect(api.fetchDevice(token: 't'), throwsA(isA<TransientNetworkError>()));
  });
}

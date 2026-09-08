import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import '../models/api_exceptions.dart';
import '../models/device_status.dart';
import '../models/enrolment_result.dart';

/// Cliente HTTP de la API de tracking. `http.Client` inyectable para los tests.
class TrackerApi {
  TrackerApi(this._client, String baseUrl)
    : _base = baseUrl.endsWith('/')
          ? baseUrl.substring(0, baseUrl.length - 1)
          : baseUrl;

  final http.Client _client;
  final String _base;

  static const Duration _timeout = Duration(seconds: 25);

  Uri _uri(String path) => Uri.parse('$_base/api/$path');

  Map<String, String> _headers({String? token}) => <String, String>{
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    if (token != null) 'Authorization': 'Bearer $token',
  };

  /// `POST /api/device/register`. 201 → token + config. 403 → [EnrolmentRejected].
  Future<EnrolmentResult> register({
    required String installIdentifier,
    required String secret,
    String? appVersion,
  }) async {
    final http.Response res = await _send(
      () => _client.post(
        _uri('device/register'),
        headers: _headers(),
        body: jsonEncode(<String, dynamic>{
          'install_identifier': installIdentifier,
          'secret': secret,
          'platform': 'android',
          'app_version': ?appVersion,
        }),
      ),
    );

    if (res.statusCode == 201) {
      return EnrolmentResult.fromJson(_json(res));
    }
    if (res.statusCode == 403) {
      throw const EnrolmentRejected();
    }
    throw _transient(res);
  }

  /// `POST /api/gps/batch`. 202 → nº aceptadas. La app borra el lote entero ante cualquier 2xx.
  Future<int> sendBatch({
    required String token,
    required List<Map<String, dynamic>> positions,
  }) async {
    final http.Response res = await _send(
      () => _client.post(
        _uri('gps/batch'),
        headers: _headers(token: token),
        body: jsonEncode(<String, dynamic>{'positions': positions}),
      ),
    );

    if (res.statusCode >= 200 && res.statusCode < 300) {
      final Map<String, dynamic> body = _json(res, orEmpty: true);
      return (body['accepted'] as num?)?.toInt() ?? positions.length;
    }
    _throwForAuthCodes(res);
    if (res.statusCode == 422) {
      throw const BatchRejected();
    }
    throw _transient(res);
  }

  /// `GET /api/device`. Estado del dispositivo (chofer asignado, activo, config).
  Future<DeviceStatus> fetchDevice({required String token}) async {
    final http.Response res = await _send(
      () => _client.get(_uri('device'), headers: _headers(token: token)),
    );

    if (res.statusCode == 200) {
      return DeviceStatus.fromJson(_json(res));
    }
    _throwForAuthCodes(res);
    throw _transient(res);
  }

  // --- helpers ---

  Future<http.Response> _send(Future<http.Response> Function() request) async {
    try {
      return await request().timeout(_timeout);
    } on SocketException {
      throw const TransientNetworkError('Sin conexión.');
    } on HttpException {
      throw const TransientNetworkError();
    } on http.ClientException {
      throw const TransientNetworkError();
    } catch (_) {
      throw const TransientNetworkError('Timeout.');
    }
  }

  void _throwForAuthCodes(http.Response res) {
    if (res.statusCode == 401) throw const TokenRevoked();
    if (res.statusCode == 403) throw const DeviceDeactivated();
  }

  TrackerApiException _transient(http.Response res) {
    Duration? retryAfter;
    final String? h = res.headers['retry-after'];
    if (h != null) {
      final int? secs = int.tryParse(h.trim());
      if (secs != null) retryAfter = Duration(seconds: secs);
    }
    return TransientNetworkError('HTTP ${res.statusCode}.', retryAfter);
  }

  Map<String, dynamic> _json(http.Response res, {bool orEmpty = false}) {
    if (res.body.isEmpty) {
      if (orEmpty) return <String, dynamic>{};
      throw const TransientNetworkError('Respuesta vacía.');
    }
    try {
      return jsonDecode(res.body) as Map<String, dynamic>;
    } catch (_) {
      if (orEmpty) return <String, dynamic>{};
      throw const TransientNetworkError('Respuesta no-JSON.');
    }
  }
}

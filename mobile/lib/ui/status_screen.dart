import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'package:http/http.dart' as http;

import '../config/app_config.dart';
import '../data/secure_store.dart';
import '../foreground/foreground_service.dart';
import '../models/device_status.dart';
import '../services/enrolment_service.dart';
import '../services/tracker_api.dart';
import 'permission_flow.dart';

class StatusScreen extends StatefulWidget {
  const StatusScreen({super.key});

  @override
  State<StatusScreen> createState() => _StatusScreenState();
}

class _StatusScreenState extends State<StatusScreen>
    with WidgetsBindingObserver {
  final PermissionFlow _permissions = const PermissionFlow();
  final SecureStore _store = SecureStore(SecureKeyValueStore());
  late final TrackerApi _api = TrackerApi(http.Client(), AppConfig.serverUrl);
  late final EnrolmentService _enrolment = EnrolmentService(
    store: _store,
    api: _api,
  );

  PermissionsState? _perms;
  EnrolState _enrolState = EnrolState.none;
  int? _deviceId;
  String? _enrolError;
  DeviceStatus? _serverStatus;
  DateTime? _lastSentAt;
  String _serviceMessage = '';
  int _pending = 0;
  bool _serviceRunning = false;
  bool _working = false;
  Timer? _deviceTimer;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    FlutterForegroundTask.addTaskDataCallback(_onTaskData);
    WidgetsBinding.instance.addPostFrameCallback((_) => _bootstrap());
  }

  @override
  void dispose() {
    _deviceTimer?.cancel();
    FlutterForegroundTask.removeTaskDataCallback(_onTaskData);
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _refresh();
  }

  void _onTaskData(Object data) {
    if (data is! Map) return;
    setState(() {
      _serviceMessage = (data['message'] as String?) ?? _serviceMessage;
      _pending = (data['pending'] as int?) ?? _pending;
      final String? action = data['action'] as String?;
      if (action == 'stopRevoked') _enrolState = EnrolState.revoked;
      if (action == 'stopDeactivated') _enrolState = EnrolState.deactivated;
    });
  }

  Future<void> _bootstrap() async {
    final EnrolmentOutcome outcome = await _enrolment.ensureEnrolled();
    if (!outcome.enrolled && outcome.error != null) {
      _enrolError = outcome.error;
    }
    await _refresh();
    await _maybeStartService();
    _deviceTimer ??= Timer.periodic(
      const Duration(seconds: 60),
      (_) => _pollServerStatus(),
    );
    await _pollServerStatus();
  }

  Future<void> _refresh() async {
    final PermissionsState perms = await _permissions.check();
    final EnrolState state = await _store.state;
    final int? id = await _store.deviceId;
    final DateTime? last = await _store.lastSentAt;
    final bool running = await FlutterForegroundTask.isRunningService;
    if (!mounted) return;
    setState(() {
      _perms = perms;
      _enrolState = state;
      _deviceId = id;
      _lastSentAt = last;
      _serviceRunning = running;
    });
  }

  Future<void> _pollServerStatus() async {
    final String? token = await _store.token;
    if (token == null) return;
    try {
      final DeviceStatus status = await _api.fetchDevice(token: token);
      if (!mounted) return;
      setState(() => _serverStatus = status);
      await _store.setConfig(status.tracking);
      if (!status.isActive) {
        await _store.setState(EnrolState.deactivated);
        await TrackerForegroundService.stop();
        if (mounted) setState(() => _enrolState = EnrolState.deactivated);
      }
    } catch (_) {
      // silencioso: es solo para la pantalla
    }
  }

  Future<void> _maybeStartService() async {
    final PermissionsState? p = _perms;
    if (p == null || !p.allGranted) return;
    if (_enrolState != EnrolState.enrolled) return;
    if (await FlutterForegroundTask.isRunningService) return;
    final config = await _store.config;
    await TrackerForegroundService.applyConfig(config);
    await TrackerForegroundService.start();
    await _refresh();
  }

  Future<void> _grantNext() async {
    setState(() => _working = true);
    final result = await _permissions.requestNext();
    if (!mounted) return;
    setState(() {
      _perms = result.state;
      _working = false;
    });
    await _maybeStartService();
  }

  Future<void> _reEnrol() async {
    setState(() => _working = true);
    final EnrolmentOutcome outcome = await _enrolment.reEnrol();
    if (!mounted) return;
    setState(() {
      _working = false;
      _enrolError = outcome.enrolled ? null : outcome.error;
    });
    await _refresh();
    await _maybeStartService();
  }

  @override
  Widget build(BuildContext context) {
    final PermissionsState? p = _perms;

    return Scaffold(
      appBar: AppBar(title: const Text('Servalillo · Tracker')),
      body: RefreshIndicator(
        onRefresh: _refresh,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: <Widget>[
            if (!AppConfig.isConfigured)
              _card(
                color: Colors.red.shade50,
                child: const Text(
                  'Falta configurar el servidor y el secreto en el build (--dart-define).',
                  style: TextStyle(color: Colors.red),
                ),
              ),

            _statusCard(),
            const SizedBox(height: 12),

            if (p != null && !p.allGranted) ...<Widget>[
              _permissionsCard(p),
              const SizedBox(height: 12),
              FilledButton(
                onPressed: _working ? null : _grantNext,
                child: Text(p.nextActionLabel),
              ),
            ],

            if (_enrolState == EnrolState.revoked) ...<Widget>[
              const SizedBox(height: 12),
              _card(
                color: Colors.amber.shade50,
                child: const Text(
                  'El acceso de este dispositivo fue revocado.',
                ),
              ),
              const SizedBox(height: 8),
              FilledButton.tonal(
                onPressed: _working ? null : _reEnrol,
                child: const Text('Re-enrolar'),
              ),
            ],

            if (_enrolState == EnrolState.deactivated)
              _card(
                color: Colors.grey.shade200,
                child: const Text(
                  'Dispositivo desactivado desde el panel. No se está enviando nada.',
                ),
              ),

            if (_enrolError != null)
              _card(
                color: Colors.red.shade50,
                child: Text(
                  _enrolError!,
                  style: const TextStyle(color: Colors.red),
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _statusCard() {
    final DeviceStatus? s = _serverStatus;
    return _card(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          _row(
            'Servicio',
            _serviceRunning ? 'activo' : 'parado',
            ok: _serviceRunning,
          ),
          _row(
            'Enrolado',
            _deviceId != null ? '#$_deviceId' : 'no',
            ok: _deviceId != null,
          ),
          _row(
            'Chofer',
            s == null
                ? '—'
                : (s.driverName ?? 'sin asignar — pídelo al técnico'),
            ok: s?.isAssigned ?? true,
          ),
          _row('Última señal', _serviceMessage.isEmpty ? '—' : _serviceMessage),
          _row(
            'Última posición enviada',
            _lastSentAt == null ? 'nunca' : _relative(_lastSentAt!),
          ),
          _row('En cola', _pending.toString()),
        ],
      ),
    );
  }

  Widget _permissionsCard(PermissionsState p) => _card(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const Text('Permisos', style: TextStyle(fontWeight: FontWeight.bold)),
        const SizedBox(height: 8),
        _row(
          'Ubicación',
          p.locationWhileInUse ? 'sí' : 'no',
          ok: p.locationWhileInUse,
        ),
        _row(
          'Ubicación "todo el tiempo"',
          p.locationAlways ? 'sí' : 'no',
          ok: p.locationAlways,
        ),
        _row(
          'Notificaciones',
          p.notifications ? 'sí' : 'no',
          ok: p.notifications,
        ),
        _row(
          'Batería sin restricción',
          p.batteryUnrestricted ? 'sí' : 'no',
          ok: p.batteryUnrestricted,
        ),
      ],
    ),
  );

  Widget _card({required Widget child, Color? color}) => Card(
    color: color,
    child: Padding(padding: const EdgeInsets.all(16), child: child),
  );

  Widget _row(String label, String value, {bool ok = true}) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 3),
    child: Row(
      children: <Widget>[
        Expanded(
          child: Text(label, style: const TextStyle(color: Colors.black54)),
        ),
        Text(
          value,
          style: TextStyle(
            fontWeight: FontWeight.w600,
            color: ok ? Colors.black87 : Colors.red,
          ),
        ),
      ],
    ),
  );

  String _relative(DateTime t) {
    final Duration d = DateTime.now().difference(t);
    if (d.inSeconds < 60) return 'hace ${d.inSeconds} s';
    if (d.inMinutes < 60) return 'hace ${d.inMinutes} min';
    if (d.inHours < 24) return 'hace ${d.inHours} h';
    return 'hace ${d.inDays} d';
  }
}

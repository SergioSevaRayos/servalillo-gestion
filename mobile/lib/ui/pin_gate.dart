import 'dart:async';

import 'package:flutter/material.dart';

import '../data/secure_store.dart';
import '../models/device_policies.dart';
import '../services/pin_verifier.dart';

/// Pide la clave del terminal (docs/24) antes de mostrar la APK, si la web le ha puesto una.
/// Tras [maxFailures] fallos se bloquea [lockout]. Vuelve a pedirla al salir de la app.
class PinGate extends StatefulWidget {
  const PinGate({super.key, required this.child, this.store});

  final Widget child;
  final SecureStore? store;

  static const int maxFailures = 5;
  static const Duration lockout = Duration(minutes: 5);

  @override
  State<PinGate> createState() => _PinGateState();
}

class _PinGateState extends State<PinGate> with WidgetsBindingObserver {
  late final SecureStore _store =
      widget.store ?? SecureStore(SecureKeyValueStore());
  final TextEditingController _pin = TextEditingController();

  bool _loading = true;
  bool _unlocked = false;
  bool _checking = false;
  UnlockConfig? _config;
  String? _error;
  DateTime? _lockedUntil;
  Timer? _ticker;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _load();
  }

  @override
  void dispose() {
    _ticker?.cancel();
    _pin.dispose();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.paused) {
      // Al salir de la app se vuelve a bloquear.
      setState(() => _unlocked = false);
    } else if (state == AppLifecycleState.resumed) {
      _load();
    }
  }

  Future<void> _load() async {
    final UnlockConfig? config = await _store.unlock;
    final DateTime? locked = await _store.pinLockedUntil;
    if (!mounted) return;
    setState(() {
      _config = config;
      _lockedUntil = locked != null && locked.isAfter(DateTime.now())
          ? locked
          : null;
      _loading = false;
    });
    _startTicker();
  }

  void _startTicker() {
    _ticker?.cancel();
    if (_lockedUntil == null) return;
    _ticker = Timer.periodic(const Duration(seconds: 1), (_) {
      if (!mounted) return;
      if (_lockedUntil != null && DateTime.now().isAfter(_lockedUntil!)) {
        _ticker?.cancel();
        setState(() => _lockedUntil = null);
      } else {
        setState(() {});
      }
    });
  }

  Future<void> _submit() async {
    final UnlockConfig? config = _config;
    if (config == null || _checking || _lockedUntil != null) return;
    final String pin = _pin.text.trim();
    if (pin.isEmpty) return;

    setState(() {
      _checking = true;
      _error = null;
    });

    final bool ok = await verifyPinInBackground(pin, config);
    _pin.clear();

    if (ok) {
      await _store.setPinFailures(0);
      await _store.setPinLockedUntil(null);
      if (mounted) {
        setState(() {
          _checking = false;
          _unlocked = true;
        });
      }
      return;
    }

    final int failures = await _store.pinFailures + 1;
    DateTime? locked;
    if (failures >= PinGate.maxFailures) {
      locked = DateTime.now().add(PinGate.lockout);
      await _store.setPinFailures(0);
      await _store.setPinLockedUntil(locked);
    } else {
      await _store.setPinFailures(failures);
    }

    if (!mounted) return;
    setState(() {
      _checking = false;
      _lockedUntil = locked;
      _error = locked != null
          ? 'Demasiados intentos.'
          : 'Clave incorrecta (${PinGate.maxFailures - failures} intentos más).';
    });
    _startTicker();
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }
    // Sin clave configurada desde la web, la APK se abre como siempre.
    if (_config == null || _unlocked) return widget.child;

    final DateTime? locked = _lockedUntil;
    final int secondsLeft = locked == null
        ? 0
        : locked.difference(DateTime.now()).inSeconds.clamp(0, 3600);

    return Scaffold(
      appBar: AppBar(title: const Text('Servalillo · Tracker')),
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 360),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                const Icon(Icons.shield_outlined, size: 48),
                const SizedBox(height: 12),
                const Text(
                  'Terminal protegido',
                  style: TextStyle(fontSize: 20, fontWeight: FontWeight.w600),
                ),
                const SizedBox(height: 4),
                const Text(
                  'Introduce la clave del terminal para continuar.',
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 20),
                TextField(
                  controller: _pin,
                  enabled: locked == null && !_checking,
                  obscureText: true,
                  keyboardType: TextInputType.number,
                  maxLength: 8,
                  autofocus: true,
                  textAlign: TextAlign.center,
                  style: const TextStyle(fontSize: 24, letterSpacing: 8),
                  decoration: const InputDecoration(
                    border: OutlineInputBorder(),
                    counterText: '',
                  ),
                  onSubmitted: (_) => _submit(),
                ),
                const SizedBox(height: 8),
                if (locked != null)
                  Text(
                    'Bloqueado. Vuelve a intentarlo en '
                    '${secondsLeft ~/ 60}:${(secondsLeft % 60).toString().padLeft(2, '0')}.',
                    style: const TextStyle(color: Colors.red),
                  )
                else if (_error != null)
                  Text(_error!, style: const TextStyle(color: Colors.red)),
                const SizedBox(height: 12),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton(
                    onPressed: locked == null && !_checking ? _submit : null,
                    child: Text(_checking ? 'Comprobando…' : 'Entrar'),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

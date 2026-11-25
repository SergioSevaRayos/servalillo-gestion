import 'package:flutter/material.dart';
import 'package:flutter_foreground_task/flutter_foreground_task.dart';

import 'ui/pin_gate.dart';
import 'ui/status_screen.dart';

class TrackerApp extends StatelessWidget {
  const TrackerApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Servalillo Tracker',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xFF0d7d8c)),
        useMaterial3: true,
      ),
      // WithForegroundTask mantiene viva la comunicación con el isolate del servicio.
      // PinGate pide la clave del terminal si la web le ha puesto una (docs/24).
      home: const WithForegroundTask(child: PinGate(child: StatusScreen())),
    );
  }
}

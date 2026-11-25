package es.servalillo.device_policy

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageInstaller
import android.util.Log

/**
 * Resultado de la actualización automática (docs/24). Solo deja constancia en el log: si falla,
 * la app lo reintenta más tarde (AppUpdateService), y si sale bien Android reinicia la app.
 */
class InstallResultReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val status = intent.getIntExtra(PackageInstaller.EXTRA_STATUS, PackageInstaller.STATUS_FAILURE)
        val message = intent.getStringExtra(PackageInstaller.EXTRA_STATUS_MESSAGE)
        if (status == PackageInstaller.STATUS_SUCCESS) {
            Log.i("ServalilloUpdate", "Actualización instalada")
        } else {
            Log.w("ServalilloUpdate", "Actualización fallida ($status): $message")
        }
    }
}

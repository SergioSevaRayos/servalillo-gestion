package es.servalillo.device_policy

import android.app.Activity
import android.app.admin.DevicePolicyManager
import android.content.Intent
import android.os.Build
import android.os.Bundle

/**
 * Alta por QR (docs/24), Android 10+: el asistente pregunta qué tipo de alta queremos.
 * Respondemos "móvil totalmente gestionado" (Device Owner). Sin interfaz.
 */
class ProvisioningModeActivity : Activity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val result = Intent()
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            result.putExtra(
                DevicePolicyManager.EXTRA_PROVISIONING_MODE,
                DevicePolicyManager.PROVISIONING_MODE_FULLY_MANAGED_DEVICE,
            )
        }
        setResult(RESULT_OK, result)
        finish()
    }
}

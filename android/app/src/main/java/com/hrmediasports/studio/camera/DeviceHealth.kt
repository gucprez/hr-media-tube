package com.hrmediasports.studio.camera

import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.os.BatteryManager
import android.os.Build
import android.os.PowerManager

/** Lecturas de batería y temperatura para el registro de validación (consumo y temperatura). */
data class HealthSample(
    val batteryPct: Int?,
    val batteryTempC: Double?,
    val thermalStatus: Int?,
    val chargeCounterUah: Long?,
    val currentNowUa: Long?,
)

object DeviceHealth {
    fun read(context: Context): HealthSample {
        val ctx = context.applicationContext
        val intent: Intent? = ctx.registerReceiver(null, IntentFilter(Intent.ACTION_BATTERY_CHANGED))
        val level = intent?.getIntExtra(BatteryManager.EXTRA_LEVEL, -1) ?: -1
        val scale = intent?.getIntExtra(BatteryManager.EXTRA_SCALE, -1) ?: -1
        val temp = intent?.getIntExtra(BatteryManager.EXTRA_TEMPERATURE, Int.MIN_VALUE) ?: Int.MIN_VALUE
        val bm = ctx.getSystemService(Context.BATTERY_SERVICE) as? BatteryManager
        val thermal = if (Build.VERSION.SDK_INT >= 29) {
            (ctx.getSystemService(Context.POWER_SERVICE) as? PowerManager)?.currentThermalStatus
        } else null
        return HealthSample(
            batteryPct = if (level >= 0 && scale > 0) level * 100 / scale else null,
            batteryTempC = if (temp != Int.MIN_VALUE) temp / 10.0 else null,
            thermalStatus = thermal,
            // Valores crudos del sistema; el signo y la disponibilidad de CURRENT_NOW varían según el fabricante.
            chargeCounterUah = bm?.getLongProperty(BatteryManager.BATTERY_PROPERTY_CHARGE_COUNTER)?.takeIf { it != Long.MIN_VALUE && it > 0 },
            currentNowUa = bm?.getLongProperty(BatteryManager.BATTERY_PROPERTY_CURRENT_NOW)?.takeIf { it != Long.MIN_VALUE && it != 0L },
        )
    }
}

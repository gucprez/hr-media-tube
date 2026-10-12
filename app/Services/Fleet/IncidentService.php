<?php

namespace App\Services\Fleet;

use App\Models\IncidentEvent;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleIncident;
use App\Services\Audit;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Averías de vehículos: reporte, cambios de estado con historial y sincronización del estado del vehículo. */
class IncidentService
{
    /** Orden del ciclo de vida (para detectar retrocesos, que exigen justificación). */
    protected const ORDER = ['reported' => 1, 'pending' => 2, 'in_repair' => 3, 'repaired' => 4, 'closed' => 5];

    public function report(Vehicle $vehicle, array $d, ?User $user = null): VehicleIncident
    {
        return DB::transaction(function () use ($vehicle, $d, $user) {
            $incident = VehicleIncident::create([
                'vehicle_id' => $vehicle->id, 'reported_by' => $user?->id, 'title' => $d['title'], 'description' => $d['description'] ?? null,
                'odometer' => $d['odometer'] ?? null, 'priority' => $d['priority'] ?? 'medium', 'status' => 'reported', 'reported_at' => $d['reported_at'] ?? now(),
            ]);
            $this->event($incident, null, 'reported', $user, 'Avería reportada');
            if (! empty($d['odometer']) && $d['odometer'] > (float) $vehicle->odometer) {
                $vehicle->forceFill(['odometer' => $d['odometer']])->save();
            }
            $this->syncVehicleStatus($vehicle);
            Audit::log('incident.reported', "Avería reportada en {$vehicle->ficha}: {$incident->title} (prioridad {$incident->priority_label})", $incident, null,
                ['vehiculo' => $vehicle->ficha, 'problema' => $incident->title, 'prioridad' => $incident->priority, 'kilometraje' => $incident->odometer]);

            return $incident;
        });
    }

    public function changeStatus(VehicleIncident $incident, string $to, ?string $note = null, ?User $user = null): VehicleIncident
    {
        if (! isset(self::ORDER[$to])) {
            throw new RuntimeException('Estado no válido.');
        }
        $from = $incident->status;
        if ($from === $to) {
            throw new RuntimeException('La avería ya está en ese estado.');
        }
        $note = trim((string) $note);
        $backwards = self::ORDER[$to] < self::ORDER[$from];
        if ($backwards && mb_strlen($note) < 5) {
            throw new RuntimeException('Para regresar la avería a un estado anterior indique el motivo (mín. 5 caracteres).');
        }

        return DB::transaction(function () use ($incident, $to, $from, $note, $user) {
            $incident->forceFill(['status' => $to, 'closed_at' => $to === 'closed' ? now() : null])->save();
            $this->event($incident, $from, $to, $user, $note ?: null);
            $this->syncVehicleStatus($incident->vehicle);
            Audit::log('incident.status', "Avería «{$incident->title}» ({$incident->vehicle->ficha}): ".VehicleIncident::STATUSES[$from].' → '.VehicleIncident::STATUSES[$to], $incident,
                ['status' => $from], ['status' => $to, 'nota' => $note ?: null]);

            return $incident;
        });
    }

    /**
     * Estado del vehículo según sus averías abiertas:
     *  prioridad alta/crítica sin atender → Con avería · alguna en reparación → En mantenimiento · si no → Operativo.
     * Los vehículos marcados «Inactivo» manualmente no se tocan.
     */
    public function syncVehicleStatus(Vehicle $vehicle): void
    {
        $vehicle = Vehicle::withTrashed()->find($vehicle->id);
        if (! $vehicle || $vehicle->status === 'inactive') {
            return;
        }
        $open = VehicleIncident::where('vehicle_id', $vehicle->id)->whereIn('status', VehicleIncident::OPEN);
        $status = 'operational';
        if ((clone $open)->whereIn('priority', ['high', 'critical'])->whereIn('status', ['reported', 'pending'])->exists()) {
            $status = 'breakdown';
        } elseif ((clone $open)->where('status', 'in_repair')->exists()) {
            $status = 'maintenance';
        }
        if ($status !== $vehicle->status) {
            $old = $vehicle->status;
            $vehicle->forceFill(['status' => $status])->save();
            Audit::log('vehicle.status', "Vehículo {$vehicle->ficha}: ".Vehicle::STATUSES[$old].' → '.Vehicle::STATUSES[$status], $vehicle, ['status' => $old], ['status' => $status]);
        }
    }

    protected function event(VehicleIncident $incident, ?string $from, string $to, ?User $user, ?string $note): void
    {
        IncidentEvent::create(['incident_id' => $incident->id, 'user_id' => $user?->id, 'from_status' => $from, 'to_status' => $to, 'note' => $note, 'created_at' => now()]);
    }
}

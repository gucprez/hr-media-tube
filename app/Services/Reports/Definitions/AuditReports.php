<?php

namespace App\Services\Reports\Definitions;

use App\Models\AuditLog;
use App\Services\Reports\Period;
use App\Services\Reports\ReportResult;
use App\Services\Reports\ReportSet;

class AuditReports extends ReportSet
{
    /** Acciones que representan un ajuste, anulación, reverso o cambio sensible. */
    public const SENSITIVE = ['inventory.void', 'ticket.voided', 'container.voided', 'maintenance.voided', 'fuel.adjustment', 'tire.waived', 'incident.status', 'vehicle.status', 'user.deleted', 'role.updated'];

    public function section(): string
    {
        return 'audit';
    }

    public function definitions(): array
    {
        return [
            $this->def('audit-log', 'Log de auditoría', 'Todas las acciones registradas, con usuario, IP y descripción.', fn (Period $p, array $f) => $this->log($p, $f), ['user', 'action' => ['label' => 'Acción (contiene)', 'text' => true]]),
            $this->def('audit-users', 'Actividad por usuario', 'Cantidad de acciones por usuario en el período, con primera y última actividad.', fn (Period $p, array $f) => $this->byUser($p), []),
            $this->def('audit-adjustments', 'Ajustes y anulaciones', 'Ajustes de inventario/gasoil, anulaciones y cambios sensibles.', fn (Period $p, array $f) => $this->sensitive($p, $f), ['user']),
            $this->def('audit-access', 'Accesos al sistema', 'Inicios y cierres de sesión, e intentos fallidos.', fn (Period $p, array $f) => $this->access($p, $f), ['user']),
        ];
    }

    protected function base(Period $p, array $f)
    {
        return AuditLog::query()->whereBetween('created_at', [$p->from, $p->to])
            ->when(! empty($f['user']), fn ($q) => $q->where('user_id', (int) $f['user']))->orderBy('created_at')->orderBy('id');
    }

    protected function rows($logs)
    {
        return $logs->map(fn (AuditLog $l) => ['at' => $l->created_at, 'user' => $l->user_name, 'action' => $l->action, 'description' => $l->description, 'ip' => $l->ip_address]);
    }

    protected function table($rows): ReportResult
    {
        return ReportResult::make([['at', 'Fecha', 'datetime'], ['user', 'Usuario'], ['action', 'Acción'], ['description', 'Descripción'], ['ip', 'IP']], $rows)->summary(['Registros' => (string) count($rows)]);
    }

    protected function log(Period $p, array $f): ReportResult
    {
        $q = $this->base($p, $f)->when(! empty($f['action']), fn ($q) => $q->where('action', 'like', '%'.addcslashes((string) $f['action'], '%_\\').'%'));

        return $this->table($this->rows($q->limit(20000)->get()));
    }

    protected function byUser(Period $p): ReportResult
    {
        $rows = AuditLog::query()->whereBetween('created_at', [$p->from, $p->to])->selectRaw('user_name, COUNT(*) as total, MIN(created_at) as first_at, MAX(created_at) as last_at')
            ->groupBy('user_name')->orderByDesc('total')->get()->map(fn ($r) => [
                'user' => $r->user_name ?? 'Sistema', 'total' => (int) $r->total, 'first' => \Carbon\Carbon::parse($r->first_at), 'last' => \Carbon\Carbon::parse($r->last_at),
            ]);

        return ReportResult::make([['user', 'Usuario'], ['total', 'Acciones', 'int'], ['first', 'Primera actividad', 'datetime'], ['last', 'Última actividad', 'datetime']], $rows, ['total']);
    }

    protected function sensitive(Period $p, array $f): ReportResult
    {
        return $this->table($this->rows($this->base($p, $f)->where(fn ($q) => $q->whereIn('action', self::SENSITIVE)->orWhere('action', 'like', '%adjust%')->orWhere('action', 'like', '%void%'))->get()));
    }

    protected function access(Period $p, array $f): ReportResult
    {
        return $this->table($this->rows($this->base($p, $f)->whereIn('action', ['login', 'logout', 'login.failed'])->get()));
    }
}

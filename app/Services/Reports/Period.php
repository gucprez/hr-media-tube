<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Período de un reporte: diario, semanal, mensual, trimestral, semestral, anual o rango personalizado. */
class Period
{
    public const RANGES = [
        'day' => 'Diario', 'week' => 'Semanal', 'month' => 'Mensual', 'quarter' => 'Trimestral',
        'semester' => 'Semestral', 'year' => 'Anual', 'custom' => 'Rango personalizado',
    ];

    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $range,
        public readonly CarbonImmutable $anchor,
    ) {}

    /** @param array<string,mixed> $in range, date (fecha ancla), from, to */
    public static function fromInput(array $in): self
    {
        $range = array_key_exists($in['range'] ?? '', self::RANGES) ? $in['range'] : 'month';
        $anchor = self::date($in['date'] ?? null) ?? CarbonImmutable::today();

        if ($range === 'custom') {
            $from = self::date($in['from'] ?? null) ?? $anchor->startOfMonth();
            $to = self::date($in['to'] ?? null) ?? $anchor;
            if ($to->lt($from)) {
                [$from, $to] = [$to, $from];
            }

            return new self($from->startOfDay(), $to->endOfDay(), $range, $anchor);
        }

        [$from, $to] = match ($range) {
            'day' => [$anchor->startOfDay(), $anchor->endOfDay()],
            'week' => [$anchor->startOfWeek(CarbonInterface::MONDAY), $anchor->endOfWeek(CarbonInterface::SUNDAY)],
            'quarter' => [$anchor->startOfQuarter(), $anchor->endOfQuarter()],
            'semester' => $anchor->month <= 6
                ? [$anchor->startOfYear(), $anchor->setMonth(6)->endOfMonth()]
                : [$anchor->setMonth(7)->startOfMonth(), $anchor->endOfYear()],
            'year' => [$anchor->startOfYear(), $anchor->endOfYear()],
            default => [$anchor->startOfMonth(), $anchor->endOfMonth()],
        };

        return new self($from->startOfDay(), $to->endOfDay(), $range, $anchor);
    }

    protected static function date(mixed $v): ?CarbonImmutable
    {
        if (! is_string($v) || $v === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($v);
        } catch (\Throwable) {
            return null;
        }
    }

    public function label(): string
    {
        return $this->from->isSameDay($this->to)
            ? $this->from->format('d/m/Y')
            : $this->from->format('d/m/Y').' — '.$this->to->format('d/m/Y');
    }

    public function rangeLabel(): string
    {
        return self::RANGES[$this->range];
    }
}

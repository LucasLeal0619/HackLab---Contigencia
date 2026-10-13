<?php

namespace App\Domain\Reports;

use App\Domain\Tasks\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pendência vencida no SQL: a mesma regra de Task::isOverdue (não fechada, com prazo no passado)
 * e do filtro `overdue` da listagem de pendências.
 */
class OverdueTasks
{
    public function apply(Builder $query): Builder
    {
        return $query->where('status', '!=', TaskStatus::Completed->value)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now());
    }
}

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sincronização central da Even3 (Complemento 11.2): a cada 10 minutos, sem sobreposição. Só roda onde houver
// um processo de agendamento (schedule:work/cron); no desenvolvimento o front dispara com Administrador ativo.
Schedule::command('even3:sync')->everyTenMinutes()->withoutOverlapping();

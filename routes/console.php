<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function (): void {
    $this->comment('Legalitas yang rapi membuat bisnis tumbuh lebih tenang.');
})->purpose('Display an inspiring quote');

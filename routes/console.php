<?php

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;

Artisan::command('admin:create', function () {
    $name = $this->ask('Nama administrator');
    $email = $this->ask('Email');
    $password = $this->secret('Kata sandi (minimal 12 karakter)');
    $confirmation = $this->secret('Ulangi kata sandi');
    $v = Validator::make(['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation], ['name' => 'required|string|max:100', 'email' => 'required|email|unique:users,email', 'password' => 'required|string|min:12|confirmed']);
    if ($v->fails()) {
        foreach ($v->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }
    User::create(['name' => $name, 'email' => $email, 'password' => $password]);
    $this->info('Administrator dibuat.');
})->purpose('Membuat administrator tanpa kredensial bawaan');

Artisan::command('device:list', function () {
    $this->table(['ID', 'Nama', 'Jenis', 'Sumber', 'Token'], Device::orderBy('id')->get()->map(fn ($d) => [$d->id, $d->name, $d->type, $d->is_simulated ? 'SIMULASI' : 'NYATA', $d->token_hash ? 'Aktif' : 'Tidak aktif'])->all());
})->purpose('Daftar identitas perangkat tanpa menampilkan token');

Artisan::command('device:create {name} {type} {--simulated}', function () {
    $type = $this->argument('type');
    if (! in_array($type, ['binsonic', 'terra']) || mb_strlen($this->argument('name')) > 100 || trim($this->argument('name')) === '') {
        $this->error('Jenis harus binsonic atau terra; nama harus berisi 1–100 karakter.');

        return 1;
    }
    $device = Device::create(['name' => $this->argument('name'), 'type' => $type, 'is_simulated' => (bool) $this->option('simulated')]);
    $this->info('Perangkat #'.$device->id.' dibuat ('.($device->is_simulated ? 'SIMULASI' : 'NYATA').'). Token hanya ditampilkan di Terminal; simpan dengan aman.');
    $this->line($device->rotateToken());

    return 0;
})->purpose('Membuat identitas dan token perangkat khusus melalui Terminal');

Artisan::command('device:token {device : ID perangkat} {--revoke}', function () {
    $device = Device::find($this->argument('device'));
    if (! $device) {
        $this->error('Perangkat tidak ditemukan.');

        return 1;
    }
    if ($this->option('revoke')) {
        $device->update(['token_hash' => null]);
        $this->info('Token dicabut.');

        return 0;
    }
    if ($device->token_hash && ! $this->confirm('Rotasi menonaktifkan token lama. Lanjutkan?', false)) {
        return 1;
    }
    $this->info('Token hanya ditampilkan di Terminal; simpan dengan aman.');
    $this->line($device->rotateToken());

    return 0;
})->purpose('Menerbitkan, merotasi, atau mencabut token tanpa mengirimnya ke browser');

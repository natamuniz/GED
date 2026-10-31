<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CreateAdministrator extends Command
{
    protected $signature = 'ged:create-admin';

    protected $description = 'Cria um administrador com senha informada de forma interativa';

    public function handle(): int
    {
        $data = [
            'name' => $this->ask('Nome'),
            'email' => strtolower((string) $this->ask('E-mail')),
            'password' => $this->secret('Senha (mínimo de 12 caracteres)'),
            'password_confirmation' => $this->secret('Confirme a senha'),
        ];
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $profile = Profile::where('name', 'Administrador')->first();
        if (! $profile || ! $profile->permissions()->where('key', 'users.manage')->exists()
            || ! $profile->permissions()->where('key', 'profiles.manage')->exists()) {
            $this->error('Execute os seeders e verifique as permissões do perfil Administrador.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($data, $profile) {
            $user = new User(collect($data)->except('password_confirmation')->all());
            $user->profile()->associate($profile);
            $user->save();
            AuditEvent::create([
                'action' => 'user.admin-created-cli', 'subject_type' => 'user', 'subject_id' => $user->id,
            ]);
        });
        $this->info('Administrador criado.');

        return self::SUCCESS;
    }
}

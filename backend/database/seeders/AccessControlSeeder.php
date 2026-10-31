<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Profile;
use Illuminate\Database\Seeder;

class AccessControlSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            'access.global' => 'Acessar todas as unidades',
            'documents.view' => 'Consultar documentos e versões',
            'documents.download' => 'Baixar arquivos',
            'documents.create' => 'Cadastrar documentos',
            'documents.update' => 'Editar metadados',
            'documents.add-version' => 'Adicionar versões',
            'documents.archive' => 'Arquivar e restaurar',
            'documents.move' => 'Alterar unidade produtora',
            'documents.manage-general' => 'Manter documentos gerais',
            'integrity.verify' => 'Verificar integridade',
            'shares.create' => 'Compartilhar versões',
            'shares.revoke-own' => 'Revogar compartilhamentos próprios',
            'shares.revoke-any' => 'Revogar compartilhamentos da abrangência',
            'audit.view' => 'Consultar auditoria dos documentos',
            'audit.view-global' => 'Consultar auditoria global',
            'users.manage' => 'Gerenciar usuários e vínculos',
            'profiles.manage' => 'Gerenciar perfis e exceções de acesso',
            'units.manage' => 'Gerenciar unidades',
            'document-types.manage' => 'Gerenciar tipos documentais',
        ];
        foreach ($catalog as $key => $name) {
            Permission::updateOrCreate(['key' => $key], ['name' => $name, 'group' => explode('.', $key)[0]]);
        }

        $reader = ['documents.view', 'documents.download'];
        $contributor = [...$reader, 'documents.create', 'documents.update', 'documents.add-version', 'integrity.verify', 'shares.create', 'shares.revoke-own'];
        $manager = [...$contributor, 'documents.archive', 'documents.move', 'shares.revoke-any', 'audit.view'];

        foreach (['Administrador' => array_keys($catalog), 'Gestor' => $manager, 'Colaborador' => $contributor, 'Leitor' => $reader] as $name => $keys) {
            // Rerunning the seeder must preserve administrator customizations.
            $profile = Profile::firstOrCreate(['name' => $name], ['is_system' => true]);
            if ($profile->wasRecentlyCreated) {
                $profile->permissions()->sync(Permission::whereIn('key', $keys)->pluck('id'));
            }
        }
    }
}

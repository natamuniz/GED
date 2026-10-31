<?php

// Run only inside the isolated ged-api-qa container. Never part of application setup.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (config('database.connections.pgsql.database') !== 'ged_test' || env('SESSION_COOKIE') !== 'ged_qa_session') {
    fwrite(STDERR, "This fixture requires the isolated frontend QA environment.\n");
    exit(1);
}

Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
$finance = App\Models\OrganizationalUnit::create(['name' => 'Financeiro', 'code' => 'FIN']);
$payable = App\Models\OrganizationalUnit::create(['name' => 'Contas a pagar', 'code' => 'FIN-CP', 'parent_id' => $finance->id]);
$receivable = App\Models\OrganizationalUnit::create(['name' => 'Contas a receber', 'code' => 'FIN-CR', 'parent_id' => $finance->id]);
$user = App\Models\User::factory()->create([
    'name' => 'Marina Oliveira', 'email' => 'frontend.qa@example.test',
    'password' => 'Frontend-QA-2026!',
    'profile_id' => App\Models\Profile::where('name', 'Gestor')->value('id'),
]);
$user->units()->attach($finance);
$recipient = App\Models\User::factory()->create([
    'name' => 'Gabriel Santos', 'email' => 'recipient.qa@example.test',
    'password' => 'Frontend-QA-2026!',
    'profile_id' => App\Models\Profile::where('name', 'Leitor')->value('id'),
]);
$recipient->units()->attach($payable);
$titles = ['Contrato de prestação de serviços', 'Ofício de encaminhamento', 'Relatório de recebimentos', 'Contrato de fornecimento', 'Ofício de solicitação', 'Relatório financeiro'];
for ($index = 0; $index < 24; $index++) {
    $kind = ['Contrato', 'Ofício', 'Relatório'][$index % 3];
    $document = app(App\Actions\CreateDocument::class)->execute([
        'title' => $titles[$index % count($titles)].' '.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
        'description' => 'Documento fictício utilizado exclusivamente na validação do frontend.',
        'document_type_id' => App\Models\DocumentType::where('name', $kind)->value('id'),
        'producing_unit_id' => $index % 2 ? $payable->id : $receivable->id,
        'production_date' => sprintf('2026-10-%02d', $index + 1),
        'reference_number' => sprintf('DOC-%03d/2026', $index + 1),
        'author' => 'Equipe administrativa', 'interested_party' => 'Instituto Aurora (fictício)',
    ], Illuminate\Http\UploadedFile::fake()->createWithContent('documento-'.($index + 1).'.pdf', "%PDF-1.4\nFrontend QA fixture ".($index + 1)."\n%%EOF"), $user);
    if ($index < 3) {
        app(App\Services\DocumentLifecycle::class)->archive($user, $document);
    }
}
echo "Frontend QA fixture ready: 24 documents, 21 active, 3 archived.\n";

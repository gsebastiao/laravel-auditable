<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Copia o widget JS opcional (audit-table.init.js) do pacote para dentro
 * de public/, num caminho configurável.
 *
 * O arquivo já vem junto do pacote em src/plugin/audit-table.init.js — este
 * comando só o COPIA para um lugar que o navegador consegue acessar (tudo
 * dentro de public/ é servido publicamente pelo Laravel). Não é obrigatório
 * rodar isto: o pacote de auditoria funciona inteiramente sem ele. Rode
 * apenas se quiser usar o modal de histórico pronto (veja o README, seção
 * "Widget JS opcional").
 *
 *   php artisan auditable:publish-js
 *
 * O destino vem de config('auditable.js.publish_path'), relativo a public/.
 * Padrão: "assets/js" (ou seja, public/assets/js/audit-table.init.js).
 * Para publicar em outro lugar, mude essa chave em config/auditable.php,
 * ou passe --path=algum/outro/caminho para esta execução específica.
 */
final class PublishAuditTableJs extends Command
{
    protected $signature = 'auditable:publish-js
        {--path= : Caminho dentro de public/ para copiar o arquivo (sobrepõe o config para esta execução)}
        {--force : Sobrescreve o arquivo de destino, se já existir}';

    protected $description = 'Publica o widget JS opcional do audit-table (modal de histórico) em public/';

    public function handle(Filesystem $files): int
    {
        $source = __DIR__.'/../../plugin/audit-table.init.js';

        if (! $files->exists($source)) {
            // Não deveria acontecer num pacote instalado via Composer, mas
            // uma mensagem clara aqui poupa uma investigação de "cadê o arquivo".
            $this->components->error("Arquivo de origem não encontrado: {$source}");

            return self::FAILURE;
        }

        $relativePath = trim($this->option('path') ?? config('auditable.js.publish_path', 'assets/js'), '/');
        $destinationDir = public_path($relativePath);
        $destination = $destinationDir.'/audit-table.init.js';

        if ($files->exists($destination) && ! $this->option('force')) {
            $this->components->warn("O arquivo já existe e foi mantido: {$destination}");
            $this->components->info('Para substituir pela versão do pacote: php artisan auditable:publish-js --force');

            // Nada deu errado: devolvemos SUCCESS (código 0) para não quebrar
            // scripts de deploy que param em qualquer código diferente de 0.
            return self::SUCCESS;
        }

        $files->ensureDirectoryExists($destinationDir);
        $files->copy($source, $destination);

        $this->components->info('Widget JS publicado com sucesso.');
        $this->components->twoColumnDetail('Origem', $source);
        $this->components->twoColumnDetail('Destino', $destination);
        $this->newLine();
        $this->line('Inclua no seu layout, por exemplo:');
        $this->line('  <script src="'.asset($relativePath.'/audit-table.init.js').'"></script>');

        return self::SUCCESS;
    }
}

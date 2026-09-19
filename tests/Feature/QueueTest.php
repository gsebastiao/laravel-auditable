<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Feature;

use Gsebastiao\Auditable\Audit;
use Gsebastiao\Auditable\Models\Audit as AuditModel;
use Gsebastiao\Auditable\Support\QueueContext;
use Gsebastiao\Auditable\Tests\Fixtures\Documento;
use Gsebastiao\Auditable\Tests\Fixtures\EmitirNotaFiscal;
use Gsebastiao\Auditable\Tests\Fixtures\Produto;
use Gsebastiao\Auditable\Tests\TestCase;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;

/** Jobs despachadas durante uma operação continuam-na sozinhas. */
final class QueueTest extends TestCase
{
    /**
     * Corre $trabalho como um worker noutro processo: sem batch aberto,
     * ninguém logado, só com o payload que a job recebeu.
     *
     * @param  array<string, mixed>  $payload
     */
    private function comoWorker(array $payload, callable $trabalho): void
    {
        $this->app->forgetScopedInstances();
        config(['auditable.default_created_by' => null]);

        $job = new SyncJob($this->app, (string) json_encode($payload), 'redis', 'default');
        $this->app['events']->dispatch(new JobProcessing('redis', $job));

        try {
            $trabalho();
        } finally {
            $this->app['events']->dispatch(new JobProcessed('redis', $job));
        }
    }

    public function test_pipeline_real_do_laravel_com_fila_sync(): void
    {
        config(['auditable.default_created_by' => 7]);
        $visto = null;
        $this->app['events']->listen(JobProcessing::class, function (JobProcessing $event) use (&$visto) {
            $visto = $event->job->payload()[QueueContext::KEY] ?? null;
        });

        $produto = Audit::transaction(function () {
            $produto = Produto::create(['nome' => 'Mesa', 'preco' => 1]);
            EmitirNotaFiscal::dispatch($produto->id);

            return $produto;
        });
        Produto::create(['nome' => 'Depois da operação', 'preco' => 1]);

        $criados = AuditModel::action('created')->orderBy('id')->get();
        $nota = AuditModel::action('nota_fiscal_emitida')->first();

        $this->assertSame(['batch' => $criados[0]->batch, 'user' => 7], $visto, 'o payload da job leva batch e usuário');
        $this->assertSame($criados[0]->batch, $nota->batch);
        $this->assertNotSame($criados[0]->batch, $criados[1]->batch, 'depois da job síncrona nada fica preso à operação');
        $this->assertSame($produto->id, (int) $nota->subject_id);
    }

    public function test_job_noutro_processo_herda_operacao_e_autor(): void
    {
        config(['auditable.default_created_by' => 7]);
        [$produto, $payload] = Audit::transaction(function () {
            return [Produto::create(['nome' => 'Mesa', 'preco' => 1]), QueueContext::payload()];
        });

        $this->comoWorker($payload, fn () => $produto->auditAction('nota_fiscal_emitida'));

        $nota = AuditModel::action('nota_fiscal_emitida')->first();
        $this->assertSame(AuditModel::action('created')->first()->batch, $nota->batch);
        $this->assertSame(7, (int) $nota->created_by);
    }

    public function test_transaction_dentro_da_job_continua_a_mesma_operacao(): void
    {
        $payload = Audit::transaction(function () {
            Produto::create(['nome' => 'Mesa', 'preco' => 1]);

            return QueueContext::payload();
        });

        $this->comoWorker($payload, fn () => Audit::transaction(fn () => Produto::create(['nome' => 'Na job', 'preco' => 2])));

        $this->assertSame(1, AuditModel::query()->distinct()->count('batch'));
    }

    public function test_depois_da_job_nada_fica_herdado(): void
    {
        config(['auditable.default_created_by' => 7]);
        $payload = Audit::transaction(function () {
            Produto::create(['nome' => 'Mesa', 'preco' => 1]);

            return QueueContext::payload();
        });

        $this->comoWorker($payload, fn () => null);
        Produto::create(['nome' => 'Fora da job', 'preco' => 1]);

        $fora = AuditModel::orderByDesc('id')->first();
        $this->assertNotSame(AuditModel::orderBy('id')->first()->batch, $fora->batch);
        $this->assertNull($fora->created_by);
    }

    public function test_job_despachada_fora_de_uma_operacao_nao_leva_batch(): void
    {
        config(['auditable.default_created_by' => 7]);

        $this->assertSame([QueueContext::KEY => ['user' => 7]], QueueContext::payload());
    }

    public function test_propagacao_pode_ser_desligada(): void
    {
        config([
            'auditable.default_created_by' => 7,
            'auditable.queue.propagate_batch' => false,
            'auditable.queue.propagate_user' => false,
        ]);

        $this->assertSame([], Audit::transaction(fn () => QueueContext::payload()));
    }

    public function test_tenant_so_e_propagado_quando_ligado(): void
    {
        $tenant = 5;
        Audit::resolveTenantUsing(function () use (&$tenant) {
            return $tenant;
        });

        $this->assertArrayNotHasKey('tenant', QueueContext::payload()[QueueContext::KEY] ?? []);

        config(['auditable.queue.propagate_tenant' => true]);
        $payload = QueueContext::payload();
        DB::table('documentos')->insert([['tenant_id' => 5, 'titulo' => 'Do 5'], ['tenant_id' => 6, 'titulo' => 'Do 6']]);

        $tenant = null; // no worker, o resolver já não sabe o tenant (ninguém logado)
        $this->comoWorker($payload, function () {
            $novo = Documento::create(['titulo' => 'Criado na job']);

            $this->assertSame(5, (int) $novo->tenant_id);
            $this->assertSame(['Criado na job', 'Do 5'], Documento::orderBy('titulo')->pluck('titulo')->all());
        });
    }
}

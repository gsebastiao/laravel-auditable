<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Gsebastiao\Auditable\Support\AuditOptions;
use Gsebastiao\Auditable\Support\ChangeSetBuilder;
use Gsebastiao\Auditable\Support\LabelResolver;
use Gsebastiao\Auditable\Support\ResolveMap;
use Gsebastiao\Auditable\Tests\Fakes\FakeConnection;

/**
 * LabelResolver aceita uma ConnectionInterface — aqui FakeConnection, em
 * memória. Prova que ResolveMap::direct()/join()/alias() funcionam só com o
 * Query Builder, sem Eloquent (o caminho usado por Audit::for(...)).
 */
final class ResolveMapLabelResolverTest extends TestCase
{
    private FakeConnection $db;
    private ChangeSetBuilder $builder;

    protected function setUp(): void
    {
        $this->db = new FakeConnection();
        $this->builder = new ChangeSetBuilder(new LabelResolver($this->db));
    }

    public function test_direct_resolves_fk_to_human_label_on_both_sides(): void
    {
        $this->db->seed('status', [
            ['id' => 2, 'nome' => 'Aguardando pagamento'],
            ['id' => 5, 'nome' => 'Enviado'],
        ]);

        $map = ResolveMap::direct(label: 'Status', table: 'status', column: 'nome');
        $options = AuditOptions::defaults()->resolveMap(['status_id' => $map]);

        $diff = $this->builder->build(
            new: ['status_id' => 5],
            old: ['status_id' => 2],
            options: $options,
        );

        $this->assertSame([
            'Status' => [
                'old' => ['id' => 2, 'label' => 'Aguardando pagamento'],
                'new' => ['id' => 5, 'label' => 'Enviado'],
            ],
        ], $diff);
    }

    public function test_join_navigates_estado_to_pais_and_resolves_country_name(): void
    {
        // O cenário exato pedido: resolver o nome do país a partir de
        // estado_id, navegando estados -> paises, via ResolveMap::join(),
        // sem NENHUM Eloquent envolvido (FakeConnection é puro Query Builder).
        $this->db->seed('estados', [
            ['id' => 3, 'nome' => 'São Paulo', 'pais_id' => 1],
            ['id' => 7, 'nome' => 'Lisboa', 'pais_id' => 2],
        ]);
        $this->db->seed('paises', [
            ['id' => 1, 'nome' => 'Brasil'],
            ['id' => 2, 'nome' => 'Portugal'],
        ]);

        $map = ResolveMap::join([
            ['table' => 'estados', 'key' => 'id'],
            ['table' => 'paises',
                'on'     => ['paises.id', '=', 'estados.pais_id'],
                'column' => 'nome',
                'label'  => 'País'],
        ]);
        $options = AuditOptions::defaults()->resolveMap(['estado_id' => $map]);

        $diff = $this->builder->build(
            new: ['estado_id' => 7],
            old: ['estado_id' => 3],
            options: $options,
        );

        $this->assertSame([
            'País' => [
                'old' => ['id' => 3, 'label' => 'Brasil'],
                'new' => ['id' => 7, 'label' => 'Portugal'],
            ],
        ], $diff);
    }

    public function test_alias_only_renames_the_field_without_querying_the_database(): void
    {
        $map = ResolveMap::alias('Observação Interna');
        $options = AuditOptions::defaults()->resolveMap(['obs' => $map]);

        $diff = $this->builder->build(
            new: ['obs' => 'nova nota'],
            old: ['obs' => 'nota antiga'],
            options: $options,
        );

        $this->assertSame([
            'Observação Interna' => ['old' => 'nota antiga', 'new' => 'nova nota'],
        ], $diff);
    }

    public function test_direct_resolves_to_null_label_when_fk_target_does_not_exist(): void
    {
        $this->db->seed('status', [
            ['id' => 2, 'nome' => 'Aguardando pagamento'],
        ]);

        $map = ResolveMap::direct(label: 'Status', table: 'status', column: 'nome');
        $options = AuditOptions::defaults()->resolveMap(['status_id' => $map]);

        $diff = $this->builder->build(
            new: ['status_id' => 999], // não existe na tabela "status"
            old: ['status_id' => 2],
            options: $options,
        );

        $this->assertSame([
            'Status' => [
                'old' => ['id' => 2, 'label' => 'Aguardando pagamento'],
                'new' => ['id' => 999, 'label' => null],
            ],
        ], $diff, 'FK sem correspondência deve resolver para label null, sem lançar exceção');
    }

    public function test_label_resolver_caches_by_value_within_the_same_request(): void
    {
        $this->db->seed('cacheable', [
            ['id' => 1, 'nome' => 'Original'],
        ]);

        $resolver = new LabelResolver($this->db);
        $map = ResolveMap::direct(label: 'Cacheable', table: 'cacheable', column: 'nome');

        $first = $resolver->resolve(1, $map);

        // muda a "tabela" por baixo, sem passar por um resolver novo
        $this->db->seed('cacheable', [
            ['id' => 1, 'nome' => 'Mutated'],
        ]);

        $second = $resolver->resolve(1, $map);

        $this->assertSame('Original', $first);
        $this->assertSame('Original', $second, 'a mesma instância de LabelResolver deve cachear por valor dentro do mesmo request');
    }

    public function test_join_tambem_usa_cache(): void
    {
        $this->db->seed('estados', [['id' => 1, 'pais_id' => 10]]);
        $this->db->seed('paises', [['id' => 10, 'nome' => 'Moçambique']]);

        $resolver = new LabelResolver($this->db);
        $map = ResolveMap::join([
            ['table' => 'estados', 'key' => 'id'],
            ['table' => 'paises', 'on' => ['paises.id', '=', 'estados.pais_id'], 'column' => 'nome', 'label' => 'País'],
        ]);

        $first = $resolver->resolve(1, $map);
        $this->db->seed('paises', [['id' => 10, 'nome' => 'Mudou']]);

        $this->assertSame('Moçambique', $first);
        $this->assertSame('Moçambique', $resolver->resolve(1, $map));
    }

    public function test_label_numerico_vira_texto(): void
    {
        $this->db->seed('codigos', [['id' => 1, 'codigo' => 4711]]);

        $label = (new LabelResolver($this->db))->resolve(1, ResolveMap::direct('Código', 'codigos', 'codigo'));

        $this->assertSame('4711', $label);
    }

    public function test_valor_que_nao_e_uma_chave_nao_e_consultado(): void
    {
        $resolver = new LabelResolver($this->db);
        $map = ResolveMap::direct('Status', 'status');

        $this->assertNull($resolver->resolve(['a', 'b'], $map));
        $this->assertNull($resolver->resolve(null, $map));
        $this->assertNull($resolver->resolve('', $map));
    }

    public function test_cache_tem_teto_e_descarta_o_mais_antigo(): void
    {
        $this->db->seed('status', [
            ['id' => 1, 'nome' => 'A'], ['id' => 2, 'nome' => 'B'],
            ['id' => 3, 'nome' => 'C'], ['id' => 4, 'nome' => 'D'],
        ]);
        $resolver = new LabelResolver($this->db, maxEntries: 3);
        $map = ResolveMap::direct('Status', 'status');

        foreach ([1, 2, 3] as $id) {
            $resolver->resolve($id, $map);
        }
        $this->db->seed('status', [
            ['id' => 1, 'nome' => 'A2'], ['id' => 2, 'nome' => 'B2'],
            ['id' => 3, 'nome' => 'C2'], ['id' => 4, 'nome' => 'D2'],
        ]);

        $this->assertSame('C', $resolver->resolve(3, $map), 'dentro do teto: veio do cache');
        $resolver->resolve(4, $map); // cache cheio: o 1 (mais antigo) sai
        $this->assertSame('A2', $resolver->resolve(1, $map), 'o 1 foi descartado e consultado de novo');
        $this->assertSame('C', $resolver->resolve(3, $map));
    }
}

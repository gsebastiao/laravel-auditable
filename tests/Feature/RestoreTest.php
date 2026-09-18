<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Feature;

use Gsebastiao\Auditable\Models\Audit;
use Gsebastiao\Auditable\Tests\Fixtures\Cliente;
use Gsebastiao\Auditable\Tests\Fixtures\Produto;
use Gsebastiao\Auditable\Tests\Fixtures\Situacao;
use Gsebastiao\Auditable\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RestoreTest extends TestCase
{
    protected function tearDown(): void
    {
        Relation::morphMap([], false);
        date_default_timezone_set('UTC');
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_restaura_identico_mesmo_com_fuso_horario_e_casts(): void
    {
        config(['app.timezone' => 'Africa/Maputo']);
        date_default_timezone_set('Africa/Maputo');
        Model::encryptUsing(new Encrypter(str_repeat('k', 32), 'AES-256-CBC'));
        Carbon::setTestNow('2026-03-01 10:00:00');

        $cliente = Cliente::create([
            'nome' => 'Ana',
            'meta' => ['vip' => true],
            'nascimento' => '1990-05-10',
            'situacao' => Situacao::Ativo,
            'segredo' => 'PIN-1234',
        ]);
        $antes = (array) DB::table('clientes')->where('id', $cliente->id)->first();
        $cliente->delete();

        Audit::action('deleted')->first()->restore();

        $depois = (array) DB::table('clientes')->where('id', $cliente->id)->first();
        $this->assertSame($antes, $depois);
        $this->assertSame('PIN-1234', Cliente::find($cliente->id)->segredo);
    }

    public function test_restaura_com_morph_map(): void
    {
        Relation::morphMap(['produto' => Produto::class]);

        $produto = Produto::create(['nome' => 'Mesa', 'preco' => 1]);
        $produto->delete();

        $restaurado = Produto::auditsFor($produto->id)->action('deleted')->first()->restore();

        $this->assertInstanceOf(Produto::class, $restaurado);
        $this->assertSame('Mesa', Produto::find($produto->id)->nome);
    }

    public function test_id_ocupado_da_um_erro_claro(): void
    {
        $produto = Produto::create(['nome' => 'Mesa', 'preco' => 1]);
        $produto->delete();
        DB::table('produtos')->insert(['id' => $produto->id, 'nome' => 'Outro']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('restore(withId: false)');

        Audit::action('deleted')->first()->restore();
    }

    public function test_sem_id_e_com_valores_extra(): void
    {
        $produto = Produto::create(['nome' => 'Mesa', 'preco' => 1]);
        $produto->delete();

        $novo = Audit::action('deleted')->first()->restore(withId: false, attributes: ['nome' => 'Mesa (restaurada)']);

        $this->assertNotSame($produto->id, $novo->id);
        $this->assertSame('Mesa (restaurada)', Produto::find($novo->id)->nome);
    }

    public function test_so_deleted_pode_ser_restaurado(): void
    {
        Produto::create(['nome' => 'Mesa', 'preco' => 1]);

        $this->expectException(RuntimeException::class);

        Audit::action('created')->first()->restore();
    }
}

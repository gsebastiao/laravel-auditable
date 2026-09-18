<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Gsebastiao\Auditable\Audit;
use Gsebastiao\Auditable\Tests\Fixtures\Produto;
use Gsebastiao\Auditable\Tests\TestCase;

final class TransactionBatchTest extends TestCase
{
    public function test_audit_transaction_groups_multiple_writes_under_one_batch(): void
    {
        Audit::transaction(function () {
            Produto::create(['nome' => 'A', 'preco' => 1]);
            Produto::create(['nome' => 'B', 'preco' => 2]);
        });

        $batches = \Gsebastiao\Auditable\Models\Audit::pluck('batch')->unique();

        $this->assertCount(1, $batches, 'all writes inside Audit::transaction() must share one batch');
    }

    public function test_audit_transaction_rolls_back_both_data_and_audit_on_exception(): void
    {
        try {
            Audit::transaction(function () {
                Produto::create(['nome' => 'A', 'preco' => 1]);
                throw new \RuntimeException('forced failure');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, Produto::count(), 'the product write must be rolled back');
        $this->assertSame(0, \Gsebastiao\Auditable\Models\Audit::count(), 'the audit entry must be rolled back too, since it lives in the same transaction');
    }

    public function test_batch_wrapping_db_transaction_shares_one_batch(): void
    {
        Audit::batch(function () {
            DB::transaction(function () {
                Produto::create(['nome' => 'A', 'preco' => 1]);
                Produto::create(['nome' => 'B', 'preco' => 2]);
            });
        });

        $batches = \Gsebastiao\Auditable\Models\Audit::pluck('batch')->unique();

        $this->assertCount(1, $batches, 'Audit::batch() wrapping DB::transaction() must still produce one batch');
    }

    public function test_db_transaction_wrapping_batch_shares_one_batch(): void
    {
        DB::transaction(function () {
            Audit::batch(function () {
                Produto::create(['nome' => 'A', 'preco' => 1]);
                Produto::create(['nome' => 'B', 'preco' => 2]);
            });
        });

        $batches = \Gsebastiao\Auditable\Models\Audit::pluck('batch')->unique();

        $this->assertCount(1, $batches, 'DB::transaction() wrapping Audit::batch() must still produce one batch');
    }
}

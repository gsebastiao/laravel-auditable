<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Support;

use Illuminate\Support\Str;
use Gsebastiao\Auditable\Contracts\BatchIdGenerator;

/**
 * Gerador de batch id padrão.
 *
 * Usa ULID: ordenável lexicograficamente por tempo de criação, 128 bits de
 * unicidade, gerado em memória. Substitui o esquema "uuid-0001" do BaseModel
 * original (SELECT MAX + preg_match + str_pad), que era simultaneamente uma
 * race condition sob concorrência e um gargalo de I/O por operação.
 */
final class UlidBatchIdGenerator implements BatchIdGenerator
{
    public function generate(): string
    {
        return (string) Str::ulid();
    }
}

<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Support;

/**
 * Os valores passados a $model->audit(...). Cada propriedade null significa
 * "usar o valor automático". Existe como objeto (e não array solto) para ter
 * tipos e autocomplete.
 *
 * @internal criado pelo trait Auditable
 */
final class ManualAuditPayload
{
    /**
     * @param  array<string, mixed>|null  $changes
     * @param  array<string, mixed>|null  $debugInfo
     */
    public function __construct(
        public readonly ?string $batch = null,
        public readonly ?string $subjectType = null,
        public readonly string|int|null $subjectId = null,
        public readonly ?string $event = null,
        public readonly ?array $changes = null,
        public readonly ?array $debugInfo = null,
        public readonly string|int|null $createdBy = null,
        public readonly string|int|null $tenantId = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
    ) {}
}

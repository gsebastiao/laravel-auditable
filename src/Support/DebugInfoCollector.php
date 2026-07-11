<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Support;

use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Throwable;

/**
 * Coleta o contexto técnico de uma falha para o campo debug_info.
 *
 * É a versão isolada e testável do formatDebugInfo() do BaseModel original:
 * o mesmo espírito de "só o dev vê o que está por trás do erro daquele evento
 * e daquele registro", mas como serviço injetável em vez de método estático
 * gigante. O que muda em relação ao original:
 *
 *   - dados sensíveis do banco (host) não vazam por padrão;
 *   - o nível de detalhe é configurável por ambiente;
 *   - não depende de $_SERVER direto quando há Request disponível.
 *
 * O CONTEÚDO daqui vai para debug_info (visível ao dev). NUNCA para changes
 * (visível ao usuário) — essa separação é feita no AuditManager.
 */
final class DebugInfoCollector
{
    public function __construct(
        private DatabaseManager $db,
        private ?Request $request = null,
    ) {}

    /**
     * @param  array<string, mixed>  $context  Dados extra fornecidos no ponto da falha.
     * @return array<string, mixed>
     */
    public function collect(?Throwable $exception = null, array $context = []): array
    {
        $debug = [
            '_metadata' => [
                'timestamp' => now()->toDateTimeString(),
                'environment' => app()->environment(),
                'php_version' => PHP_VERSION,
                'memory_peak' => memory_get_peak_usage(true),
            ],
        ];

        if ($exception !== null) {
            $debug['error'] = [
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'class' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                // SQL só se for uma QueryException, e sem os bindings (podem ter dados sensíveis).
                'sql' => method_exists($exception, 'getSql') ? $exception->getSql() : null,
            ];

            // Stack trace limitado, como no original (30 linhas).
            $debug['trace'] = array_slice(
                explode("\n", $exception->getTraceAsString()),
                0,
                30,
            );
        }

        if (! empty($context)) {
            $debug['context'] = $context;
        }

        $request = $this->request ?? (app()->bound('request') ? app('request') : null);
        if ($request instanceof Request) {
            $debug['request'] = [
                'method' => $request->method(),
                'uri' => $request->path(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ];
        }

        // Config do banco — sem host/credenciais por padrão; driver e nome bastam
        // para diagnosticar. Ligue detalhes só se realmente precisar.
        if (config('auditable.debug.include_database', true)) {
            $connConfig = $this->db->connection(config('auditable.connection'))->getConfig();
            $debug['database'] = [
                'driver' => $connConfig['driver'] ?? null,
                'database' => $connConfig['database'] ?? null,
            ];
        }

        // Detalhes de servidor só em ambientes não-produtivos, como no original.
        if (app()->environment('local', 'development', 'testing')) {
            $debug['server'] = [
                'software' => $_SERVER['SERVER_SOFTWARE'] ?? null,
                'name' => $_SERVER['SERVER_NAME'] ?? null,
            ];
        }

        return $debug;
    }
}

<?php

declare(strict_types=1);

namespace Gsebastiao\Auditable\Support;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Throwable;

/**
 * Monta o `debug_info` de uma falha (auditFailure). Só o desenvolvedor deve
 * ler este campo.
 *
 * Nunca guarda: host, porta, usuário ou senha do banco, nem os VALORES
 * enviados na query (bindings). Numa QueryException, o SQL é guardado com os
 * "?" no lugar dos valores.
 */
final class DebugInfoCollector
{
    public function __construct(
        private DatabaseManager $db,
        private ?Request $request = null,
    ) {}

    /**
     * @param  array<string, mixed>  $context  Dados extra fornecidos por quem chamou.
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

        $connectionName = null;

        if ($exception !== null) {
            $isQuery = $exception instanceof QueryException;
            $connectionName = $isQuery ? $exception->connectionName : null;

            $debug['error'] = [
                'message' => $this->safeMessage($exception),
                'code' => $exception->getCode(),
                'class' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'sql' => $isQuery ? $exception->getSql() : null,
                'connection' => $connectionName,
            ];

            $debug['trace'] = array_slice(explode("\n", $exception->getTraceAsString()), 0, 30);
        }

        if ($context !== []) {
            $debug['context'] = $context;
        }

        if (! app()->runningInConsole()) {
            $request = $this->request ?? (app()->bound('request') ? app('request') : null);

            if ($request instanceof Request) {
                $debug['request'] = [
                    'method' => $request->method(),
                    'uri' => $request->path(),
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ];
            }
        }

        if (config('auditable.debug.include_database', true)) {
            $config = $this->db->connection($connectionName)->getConfig();

            $debug['database'] = [
                'connection' => $connectionName ?? $this->db->getDefaultConnection(),
                'driver' => $config['driver'] ?? null,
                'database' => $config['database'] ?? null,
            ];
        }

        if (app()->environment('local', 'development', 'testing')) {
            $debug['server'] = [
                'software' => $_SERVER['SERVER_SOFTWARE'] ?? null,
                'name' => $_SERVER['SERVER_NAME'] ?? null,
            ];
        }

        return $debug;
    }

    /**
     * A mensagem de uma QueryException inclui o SQL com os valores e, no
     * Laravel 12, host e porta do banco. Guardamos só a mensagem do driver.
     */
    private function safeMessage(Throwable $exception): string
    {
        if ($exception instanceof QueryException) {
            return $exception->getPrevious()?->getMessage() ?? 'Erro de banco de dados.';
        }

        return $exception->getMessage();
    }
}

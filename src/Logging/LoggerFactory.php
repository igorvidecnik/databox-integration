<?php
declare(strict_types=1);

namespace App\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;

final class LoggerFactory
{
    /**
     * Keys are matched case-insensitively (we strtolower() the key).
     *
     * @var array<string, true>
     */
    private const SENSITIVE_KEYS = [
        // OAuth tokens / secrets
        'access_token'   => true,
        'refresh_token'  => true,
        'authorization'  => true,
        'client_secret'  => true,
        'client_id'      => true,
        'code'           => true, // OAuth auth code
        'token'          => true,
        'secret'         => true,
        'password'       => true,

        // Databox
        'databox_token'  => true, 
        'x-api-key'      => true,
        'api_key'        => true,
        'apikey'         => true,
    ];

    public static function create(): Logger
    {
        $logDir = __DIR__ . '/../../logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }

        $logger = new Logger('app');

        $processor = self::createRedactionProcessor();

        $fileHandler = new StreamHandler($logDir . '/app.jsonl', Level::Info);
        $fileHandler->setFormatter(new JsonFormatter());
        $fileHandler->pushProcessor($processor);

        $consoleHandler = new StreamHandler('php://stdout', Level::Info);
        $consoleHandler->pushProcessor($processor);

        $logger->pushHandler($fileHandler);
        $logger->pushHandler($consoleHandler);

        return $logger;
    }

    private static function createRedactionProcessor(): callable
    {
        return function (LogRecord $record): LogRecord {
            $context = self::sanitize($record->context);
            $extra   = self::sanitize($record->extra);
            $message = self::maskBearer($record->message);

            return $record->with(
                message: $message,
                context: is_array($context) ? $context : $record->context,
                extra: is_array($extra) ? $extra : $record->extra,
            );
        };
    }

    private static function sanitize(mixed $data): mixed
    {
        if (is_array($data)) {
            $sanitized = [];

            foreach ($data as $key => $value) {
                $lowerKey = strtolower((string) $key);

                if (isset(self::SENSITIVE_KEYS[$lowerKey])) {
                    $sanitized[$key] = '[REDACTED]';
                    continue;
                }

                $sanitized[$key] = self::sanitize($value);
            }

            return $sanitized;
        }

        if (is_string($data)) {
            return self::maskBearer($data);
        }

        return $data;
    }

    private static function maskBearer(string $value): string
    {
        // Masks: "Bearer <anything>" (covers most Authorization header logging accidents)
        return preg_replace('/Bearer\s+\S+/i', 'Bearer [REDACTED]', $value) ?? $value;
    }
}

<?php
declare(strict_types=1);

namespace App\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

final class LoggerFactory
{
    public static function create(): Logger
    {
        $logDir = __DIR__ . '/../../logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }

        $logger = new Logger('app');

        $fileHandler = new StreamHandler($logDir . '/app.jsonl', Level::Info);
        $fileHandler->setFormatter(new JsonFormatter());
        $logger->pushHandler($fileHandler);

        $consoleHandler = new StreamHandler('php://stdout', Level::Info);
        $logger->pushHandler($consoleHandler);

        return $logger;
    }
}

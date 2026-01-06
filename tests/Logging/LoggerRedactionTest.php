<?php
declare(strict_types=1);

namespace Tests\Logging;

use App\Logging\LoggerFactory;
use PHPUnit\Framework\TestCase;

final class LoggerRedactionTest extends TestCase
{
    public function test_sensitive_values_are_redacted_in_jsonl_log(): void
    {
        $root = dirname(__DIR__, 2); // tests/Logging -> project root
        $logFile = $root . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.jsonl';

        // Ensure clean slate
        if (file_exists($logFile)) {
            @unlink($logFile);
        }

        $logger = LoggerFactory::create();

        $accessToken = 'access_token_SHOULD_NOT_LEAK';
        $refreshToken = 'refresh_token_SHOULD_NOT_LEAK';
        $apiKey = 'x_api_key_SHOULD_NOT_LEAK';
        $bearer = 'Bearer BEARER_SHOULD_NOT_LEAK';

        $logger->info('test redaction', [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'Authorization' => $bearer,
            'headers' => [
                'x-api-key' => $apiKey,
            ],
        ]);

        $this->assertFileExists($logFile, 'Expected logs/app.jsonl to be created.');

        $content = file_get_contents($logFile);
        $this->assertIsString($content);

        // Ensure raw secrets are not present
        $this->assertStringNotContainsString($accessToken, $content);
        $this->assertStringNotContainsString($refreshToken, $content);
        $this->assertStringNotContainsString($apiKey, $content);
        $this->assertStringNotContainsString('BEARER_SHOULD_NOT_LEAK', $content);

        // Ensure redaction marker is present
        $this->assertStringContainsString('[REDACTED]', $content);

        // Authorization key is fully redacted (preferred)
        $this->assertStringContainsString('"Authorization":"[REDACTED]"', $content);

    }
}

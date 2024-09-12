<?php

declare(strict_types=1);

namespace Keboola\DbExtractorSSHTunnel\Test;

use Keboola\DbExtractorSSHTunnel\Exception\UserException;
use Keboola\DbExtractorSSHTunnel\SSHTunnel;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SSHTunnelTest extends TestCase
{
    private readonly LoggerInterface $logger;

    private readonly TestHandler $logsHandler;

    public function setUp(): void
    {
        parent::setUp();

        $this->logsHandler = new TestHandler();
        $this->logger = new Logger('test', [$this->logsHandler]);
    }

    public function testConfig(): void
    {
        $dbConfig = [
            'ssh' => [
                'user' => 'root',
                'sshHost' => 'sshproxy',
                'sshPort' => '22',
                'localPort' => '33306',
                'keys' => ['private' => $this->getPrivateKey()],
            ],
            'host' => 'mysql',
            'port' => '3306',
        ];

        $tunnel = new SSHTunnel($this->logger);
        $newDbConfig = $tunnel->createSshTunnel($dbConfig);

        $this->assertEquals(
            array_merge(
                $dbConfig,
                ['host' => '127.0.0.1', 'port' => '33306'],
            ),
            $newDbConfig,
        );
    }

    public function testDebug(): void
    {
        $dbConfig = [
            'ssh' => [
                'user' => 'root',
                'sshHost' => 'sshproxy',
                'sshPort' => '22',
                'localPort' => '33307',
                'keys' => ['private' => $this->getPrivateKey()],
                'debug' => true,
            ],
            'host' => 'mysql',
            'port' => '3307',
        ];

        $tunnel = new SSHTunnel($this->logger);
        $tunnel->createSshTunnel($dbConfig);

        $this->assertTrue($this->logsHandler->hasInfo('SSH tunnel opened'));
        $debugLogs = array_filter($this->logsHandler->getRecords(), function ($record) {
            return $record['level'] === Logger::INFO;
        });
        $firstDebugLog = end($debugLogs);
        $this->assertSame('', $firstDebugLog['context']['Output']);
        $this->assertStringContainsString('debug3:', $firstDebugLog['context']['ErrorOutput']);
    }

    public function testMissingMainParameter(): void
    {
        $dbConfig = [
            'host' => 'testHost',
            'port' => 'testPort',
        ];

        $tunnel = new SSHTunnel($this->logger);

        self::expectException(UserException::class);
        self::expectExceptionMessage("Main parameter 'ssh' is missing");

        $tunnel->createSshTunnel($dbConfig);
    }

    public function testMissingParameter(): void
    {
        $dbConfig = [
            'ssh' => [
                'keys' => 'anyKey',
            ],
            'host' => 'testHost',
            'port' => 'testPort',
        ];

        $tunnel = new SSHTunnel($this->logger);

        self::expectException(UserException::class);
        self::expectExceptionMessage("Parameter 'sshHost' is missing");

        $tunnel->createSshTunnel($dbConfig);
    }

    public function testMaxSshTunnelConnectionRetriesConfig(): void
    {
        $maxRetries = 3;
        $dbConfig = [
            'ssh' => [
                'user' => 'root',
                'sshHost' => 'sshproxy',
                'sshPort' => '222', //wrong port
                'localPort' => '33306',
                'keys' => ['private' => $this->getPrivateKey()],
                'maxRetries' => $maxRetries,
            ],
            'host' => 'mysql',
            'port' => '3306',
        ];

        $tunnel = new SSHTunnel($this->logger);

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Unable to create ssh tunnel. Output:  ErrorOutput: ssh: connect to host ' .
        "sshproxy port 222: Connection refused\r\nRetries count: " . $maxRetries);

        $tunnel->createSshTunnel($dbConfig);
    }

    public function getPrivateKey(): string
    {
        return (string) file_get_contents('/root/.ssh/id_rsa');
    }

    public function getPublicKey(): string
    {
        return (string) file_get_contents('/root/.ssh/id_rsa.pub');
    }
}

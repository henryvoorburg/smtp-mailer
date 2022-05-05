<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

define('UNITTEST_MOCK_TIME', time());

final class ServiceBasicTest extends TestCase {

    private const QUEUE_DIR = __DIR__ . '/../env/Queue/mail/';
    private const TEMP_DIR = __DIR__ . '/../env/Queue/temp/';
    private const TEMPLATE_DIR = __DIR__ . '/../env/Template/html/';
    private const QUEUE_FILE_REGEX = "/^mail_([\d]+)_([a-z0-9]+)\.([\d]+)\.json/";
    private const TEMPLATE_FILE_REGEX = "/^[^.].*$/";

    private static $templateHTML = '<!doctype html>
    <html lang="en">
    <head>
      <title>Test Email</title>
      <meta http-equiv="X-UA-Compatible" content="IE=edge">
      <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
    </head>
    <body style="word-spacing:normal;">
      <h1>Test Email</h1>
    </body>
    </html>';

    private static function expectedResponse(string $status, $data, $message): array {
        return [
            'status' => $status,
            'data' => $data,
            'message' => $message
        ];
    }

    private static function clearTestJsonFiles() {
        $queueFiles = self::scanQueueJsonFiles();
        $tempFiles = self::scanTempJsonFiles();
        foreach ($queueFiles as $file) {
            if (is_file(self::QUEUE_DIR . $file)) {
                @unlink(self::QUEUE_DIR . $file);
            }
        }
        foreach ($tempFiles as $file) {
            if (is_file(self::TEMP_DIR . $file)) {
                @unlink(self::TEMP_DIR . $file);
            }
        }
    }

    private static function clearTestTemplateFiles() {
        $files = self::scanTemplateFiles();
        foreach ($files as $file) {
            if (is_file(self::TEMPLATE_DIR . $file)) {
                @unlink(self::TEMPLATE_DIR . $file);
            }
        }
    }

    private static function scanQueueJsonFiles() {
        return array_values(preg_grep(self::QUEUE_FILE_REGEX, scandir(self::QUEUE_DIR)));
    }

    private static function scanTempJsonFiles() {
        return array_values(preg_grep(self::QUEUE_FILE_REGEX, scandir(self::TEMP_DIR)));
    }

    private static function scanTemplateFiles() {
        return array_values(preg_grep(self::TEMPLATE_FILE_REGEX, scandir(self::TEMPLATE_DIR)));
    }

    public static function setUpBeforeClass(): void {
        self::clearTestTemplateFiles();
        self::clearTestJsonFiles();
        file_put_contents(self::TEMPLATE_DIR . 'test-1.html', self::$templateHTML);
    }

    private static function connect($payload): array {
        try {
            $response = '';

            $context = stream_context_create();
            $fp = stream_socket_client('tcp://127.0.0.1:3333', $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);

            if (stream_set_timeout($fp, 3)) {
                if (!$fp) {
                    throw new \Exception("open TCP sock failed ({$errno}: {$errstr})");
                } else {
                    fwrite($fp, json_encode($payload));
                    while (!feof($fp)) {
                        $buffer = fread($fp, 1024);
                        $response .= $buffer;
                        if (strlen($buffer) < 1024) {
                            break;
                        }
                    }
                    fclose($fp);
                }
                $result = json_decode($response, true);
                return $result;
            } else {
                throw new \Exception("stream_set_timeout failed");
            }
        } catch (\Throwable $e) {
            throw new \Exception("send exception: {$e->getMessage()})");
        }
    }
        
    public function testCanConnectToServiceAndReceiveResponse(): void {
        $this->assertSame(
            self::expectedResponse('error', null, 'payload cannot be empty'), // expected
            self::connect(null)
        );
    }

    /**
     * @dataProvider invalidDataProvider
     */
    public function testCanConnectWithInvalidData($input, array $expected): void {
        $this->assertSame(
            $expected,
            self::connect($input)
        );
    }

    public function invalidDataProvider(): array {
        return [
            // case 0
            [
                [],
                self::expectedResponse('error', null, 'payload cannot be empty') // expected
            ],
            // case 1
            [
                null,
                self::expectedResponse('error', null, 'payload cannot be empty') // expected
            ],
            // case 2
            [
                [
                    'auth' => 'abc12345'
                ],
                self::expectedResponse('error', null, 'invalid request') // expected
            ],
            // case 3
            [
                [
                    'sendMail' => '',
                    'auth' => 'abc12345'
                ],
                self::expectedResponse('error', [
                    'payload' => ['The data (null) must match the type: object']
                ], 'invalid payload') // expected
            ]
        ];
    }

    /**
     * @dataProvider sendEmailDataProvider
     */
    public function testCanConnectAndSendMockEmail($input, array $expected): void {
        $this->assertSame(
            $expected,
            self::connect($input)
        );
    }

    public function sendEmailDataProvider(): array {
        return [
            // case 0
            [
                [
                    'sendMail' => [
                        'to' => ['user@example.test'],
                        'ccList' => [],
                        'bccList' => [],
                        'attachments' => [],
                        'embedded' => [],
                        'subject' => 'This is subject',
                        'body' => '<html>This is content</html>'
                    ]
                ],
                self::expectedResponse('error', 'SMTP Error: Could not connect to SMTP host.', 'failed to send mail') // expected
            ],
            // case 1
            [
                [
                    'sendMail' => [
                        'to' => ['user@example.test'],
                        'ccList' => [],
                        'bccList' => [],
                        'attachments' => [],
                        'embedded' => [],
                        'subject' => 'This is subject',
                        'useTemplate' => 'test-1.html'
                    ]
                ],
                self::expectedResponse('error', 'SMTP Error: Could not connect to SMTP host.', 'failed to send mail') // expected
            ]
        ];
    }

    public function testCanMoveFailedSmtpEmailToQueue(): void {
        $queueFiles = self::scanQueueJsonFiles();
        $expectedData = self::sendEmailDataProvider();

        if (count($queueFiles) !== count($expectedData)) {
            throw new \Exception("Expected output JSON files number not matched");
        }

        for ($i = 0; $i < count($queueFiles); $i++) {
            $content = json_decode(file_get_contents(self::QUEUE_DIR . $queueFiles[$i]), true);
            $expected = $expectedData[$i][0]['sendMail'];
            $expected['failToDelivered'] = 1;

            ksort($expected);
            ksort($content);

            $this->assertSame(
                $expected,
                $content
            );
        }

        self::clearTestJsonFiles();
    }

    /**
     * @dataProvider queueEmailDataProvider
     */
    public function testCanQueueSmtpEmail($input, array $expected): void {
        $this->assertSame(
            $expected,
            self::connect($input)
        );
    }

    public function queueEmailDataProvider(): array {
        return [
            // case 0
            [
                [
                    'queueMail' => [
                        'to' => ['user@example.test'],
                        'ccList' => [],
                        'bccList' => [],
                        'attachments' => [],
                        'embedded' => [],
                        'subject' => 'This is subject',
                        'body' => self::$templateHTML
                    ]
                ],
                self::expectedResponse('success', null, 'mail added to queue') // expected
            ],
            // case 1
            [
                [
                    'queueMail' => [
                        'to' => ['user@example.test'],
                        'ccList' => [],
                        'bccList' => [],
                        'attachments' => [],
                        'embedded' => [],
                        'subject' => 'This is subject',
                        'useTemplate' => 'test-1.html',
                        'smtpUser' => 'user2@example.test',
                        'smtpPassword' => 'abc12345'
                    ]
                ],
                self::expectedResponse('success', null, 'mail added to queue') // expected
            ]
        ];
    }

    public function testVerifyQueuedMailIsValid(): void {
        $queueFiles = self::scanQueueJsonFiles();
        $expectedData = self::queueEmailDataProvider();

        if (count($queueFiles) !== count($expectedData)) {
            throw new \Exception("Expected output JSON files number not matched");
        }

        for ($i = 0; $i < count($queueFiles); $i++) {
            $content = json_decode(file_get_contents(self::QUEUE_DIR . $queueFiles[$i]), true);
            $expected = $expectedData[$i][0]['queueMail'];

            unset($expected['smtpPassword']);
            unset($content['smtpEncryptPassword']);

            $expected['failToDelivered'] = 0;

            ksort($expected);
            ksort($content);

            $this->assertSame(
                $expected,
                $content
            );
        }
        self::clearTestJsonFiles();
    }

    /**
     * @dataProvider scheduleEmailDataProvider
     */
    public function testCanScheduleSmtpEmail($input, array $expected): void {
        $this->assertSame(
            $expected,
            self::connect($input)
        );
    }

    public function scheduleEmailDataProvider(): array {
        return [
            // case 0
            [
                [
                    'queueMail' => [
                        'scheduleTime' => UNITTEST_MOCK_TIME + 300,
                        'to' => ['user@example.test'],
                        'ccList' => [],
                        'bccList' => [],
                        'attachments' => [],
                        'embedded' => [],
                        'subject' => 'This is subject',
                        'body' => self::$templateHTML
                    ]
                ],
                self::expectedResponse('success', null, 'mail added to queue') // expected
            ],
            // case 1
            [
                [
                    'queueMail' => [
                        'scheduleTime' => UNITTEST_MOCK_TIME + 3600,
                        'to' => ['user@example.test'],
                        'ccList' => [],
                        'bccList' => [],
                        'attachments' => [],
                        'embedded' => [],
                        'subject' => 'This is subject',
                        'useTemplate' => 'test-1.html',
                        'smtpUser' => 'user2@example.test',
                        'smtpPassword' => 'abc12345'
                    ]
                ],
                self::expectedResponse('success', null, 'mail added to queue') // expected
            ]
        ];
    }

    public function testVerifyScheduledMailIsValid(): void {
        $queueFiles = self::scanQueueJsonFiles();
        $expectedData = self::scheduleEmailDataProvider();

        if (count($queueFiles) !== count($expectedData)) {
            throw new \Exception("Expected output JSON files number not matched");
        }

        for ($i = 0; $i < count($queueFiles); $i++) {
            $content = json_decode(file_get_contents(self::QUEUE_DIR . $queueFiles[$i]), true);
            $expected = $expectedData[$i][0]['queueMail'];

            unset($expected['smtpPassword']);
            unset($content['smtpEncryptPassword']);

            $expected['failToDelivered'] = 0;

            ksort($expected);
            ksort($content);

            $this->assertSame(
                $expected,
                $content
            );

            $createTime = intval(explode('_', explode('.', $queueFiles[$i])[0])[1]);

            $this->assertSame(
                $expected['scheduleTime'],
                $createTime
            );
        }
    }

    public static function tearDownAfterClass(): void {
        self::clearTestJsonFiles();
        self::clearTestTemplateFiles();
    }
}

<?php

/**
 * Service env variables
 * This file is part of the SMTP Mailer service.
 *
 * @license MIT
 * (c) 2022 Eric Chow <https://cmchow.com>
 * License at https://opensource.org/licenses/MIT
 */

namespace Core;

use Core\Validator;

/*
 * Block direct access to this file
 */

if (count(get_included_files()) == 1) {
    die();
}

class Config {

    /**
     * singleton instance
     * @var Config
     */
    private static $_instance;

    /**
     * base working directory
     * @var string
     */
    private static $basePath = __DIR__ . '/../';

    /**
     * path to env file
     * @var string
     */
    private static $envPath = '.env';

    /**
     * env variables with default values
     * @var array
     */
    private static $env = [
        'MAILER_ADDR' => '',
        'MAILER_SSL' => false,
        'MAILER_SSL_CERT' => '',
        'MAILER_SSL_KEY' => '',
        'MAILER_THREADS' => 4,
        'MAILER_MAX_MEMORY' => 64,
        'MAILER_MAX_REQUEST' => -1,
        'MAILER_TIMEOUT' => 300,
        'MAILER_LOG' => true,
        'MAILER_LOG_LEVEL' => 'notice',
        'MAILER_LOG_OUTPUT' => 'error_log',
        'MAILER_AUTH' => false,
        'MAILER_AUTH_HASH_METHOD' => 'bcrypt',
        'MAILER_QUEUE' => true,
        'MAILER_QUEUE_API_READ_ONLY' => true,
        'QUEUE_SCAN_INTERVAL' => 60,
        'QUEUE_MAX_BATCH_SIZE' => 20,
        'QUEUE_MAX_FAILED_RETRY' => 1,
        'QUEUE_DIR' => 'Queue/mail/',
        'QUEUE_PROCESS_DIR' => 'Queue/temp/',
        'QUEUE_FULL_ENCRYPT' => false,
        'QUEUE_ENCRYPT_METHOD' => 'AES128',
        'SMTP_HOST' => '',
        'SMTP_USER' => '',
        'SMTP_PASSWORD' => '',
        'SMTP_ENCRYPTION' => 'tls',
        'SMTP_PORT' => 587,
        'MAIL_HTML' => true,
        'MAIL_CHARSET' => 'utf-8',
        'MAIL_ENCODING' => '8bit',
        'MAIL_FROM_ADDR' => '',
        'MAIL_FROM_NAME' => '',
        'EMAIL_TEMPLATE' => true,
        'EMAIL_TEMPLATE_DIR' => 'Template/html/',
        'EMAIL_TEMPLATE_API_READ_ONLY' => true,
        'EMAIL_TEMPLATE_STRING_TAG_OPEN' => '{{',
        'EMAIL_TEMPLATE_STRING_TAG_CLOSE' => '}}'
    ];

    /**
     * Service auth password hash
     * @var string
     */
    private static $authHash = null;

    /**
     * SMTP password
     * @var string
     */
    private static $smtpPassword = null;

    /**
     * Cryptography secret key
     * @var string
     */
    private static $secretKey = null;

    /**
     * Constructor
     * @param string|null $envPath custom env file path
     * @param string|null $basePath custom base path
     */
    private function __construct($envPath = null, $basePath = null) {
        $this::readEnv($envPath, $basePath);
        $this::validateEnv();
    }

    /**
     * Disable cloning
     */
    private function __clone() {
    }

    /**
     * Returns whether the file path is an absolute path.
     * @param string $file file path
     * (https://github.com/symfony/symfony/blob/6.1/src/Symfony/Component/Filesystem/Filesystem.php#method_isAbsolutePath)
     */
    private static function isAbsolutePath(string $file): bool {
        return '' !== $file && (
            strspn($file, '/\\', 0, 1)
            || (
                \strlen($file) > 3 && ctype_alpha($file[0])
                && ':' === $file[1]
                && strspn($file, '/\\', 2, 1)
            )
            || null !== parse_url($file, \PHP_URL_SCHEME)
        );
    }

    /**
     * Validate directory access rights
     * @param string $key name of env variable
     * @throws Exception If unable to read/write directory
     */
    private static function validateReadWriteAccess(string $key) {
        if (!is_readable(self::$env[$key]) || !is_writable(self::$env[$key])) {
            throw new \Exception("Unable to read/write to {$key}");
        }
    }

    /**
     * Validate env variables
     * @throws Exception If any env variable is invalid
     */
    private static function validateEnv() {
        [$valid, $error] = Validator::validate('env', self::$env);

        if ($valid) {
            if (self::$env['MAILER_AUTH']) {
                if (self::$authHash === null) {
                    throw new \Exception('MAILER_AUTH_HASH cannot be empty');
                }
            }
            if (self::$env['MAILER_QUEUE']) {
                self::validateReadWriteAccess('QUEUE_DIR');
                self::validateReadWriteAccess('QUEUE_PROCESS_DIR');
                if (self::$secretKey === null) {
                    throw new \Exception('secret key cannot be empty');
                }
            }
            if (self::$env['EMAIL_TEMPLATE']) {
                self::validateReadWriteAccess('EMAIL_TEMPLATE_DIR');
            }
            if (self::$smtpPassword === null) {
                throw new \Exception('SMTP password cannot be empty');
            }
        } else {
            throw new \Exception(json_encode($error));
        }
    }

    /**
     * Read and parse env variables
     * @param string|null $envPath custom env file path
     * @param string|null $basePath custom base path
     * @throws Exception If failed to load env file
     */
    private static function readEnv($envPath = null, $basePath = null) {
        self::$basePath = __DIR__ . '/../';
        self::$envPath = '.env';
        $pharPath = \Phar::running(false);
        if (!empty($pharPath)) {
            $parts = explode('/', $pharPath);
            array_pop($parts);
            self::$basePath = implode('/', $parts) . '/';
        }
        if (is_string($basePath) && !empty($pharPath)) {
            if (self::isAbsolutePath($basePath)) {
                // absolute path
                self::$basePath = $basePath;
            } else {
                // relative path
                self::$basePath .= $basePath;
            }
        }

        $iniPath = (self::$basePath . self::$envPath);
        if (is_string($envPath) && !empty($envPath)) {
            if (self::isAbsolutePath($envPath)) {
                // absolute path
                $iniPath = $envPath;
            } else {
                // relative path
                self::$envPath = $envPath;
                $iniPath = (self::$basePath . $envPath);
            }
        }
        if (is_readable($iniPath)) {
            $tempEnv = parse_ini_file($iniPath, false, INI_SCANNER_TYPED);
            if ($tempEnv !== false) {
                if (isset($tempEnv['MAILER_ADDR'])) {
                    $tempEnv['MAILER_SSL'] = substr($tempEnv['MAILER_ADDR'], 0, 3) === 'ssl';
                } else {
                    throw new \Exception('MAILER_ADDR cannot be empty');
                }
                if (isset($tempEnv['SECRET_KEY'])) {
                    self::$secretKey = $tempEnv['SECRET_KEY'];
                    unset($tempEnv['SECRET_KEY']);
                }
                if (isset($tempEnv['SMTP_PASSWORD'])) {
                    self::$smtpPassword = $tempEnv['SMTP_PASSWORD'];
                    unset($tempEnv['SMTP_PASSWORD']);
                }
                if (isset($tempEnv['MAILER_AUTH_HASH'])) {
                    self::$authHash = $tempEnv['MAILER_AUTH_HASH'];
                    unset($tempEnv['MAILER_AUTH_HASH']);
                }
                foreach ($tempEnv as $key => $value) {
                    switch ($key) {
                        case 'MAILER_SSL_CERT':
                        case 'MAILER_SSL_KEY':
                        case 'QUEUE_DIR':
                        case 'QUEUE_PROCESS_DIR':
                        case 'EMAIL_TEMPLATE_DIR':
                            if (self::isAbsolutePath($value)) {
                                // absolute path
                                self::$env[$key] = $value;
                            } else {
                                // relative path
                                self::$env[$key] = self::$basePath . $value;
                            }
                            break;
                        default:
                            self::$env[$key] = $value;
                    }
                }
            } else {
                throw new \Exception('env parse error');
            }
        } else {
            throw new \Exception("env file does not exist");
        }
    }

    /**
     * Get calling class
     * https://gist.github.com/hamstar/1122679
     */
    private static function getCallingClass() {

        // get the trace
        $trace = debug_backtrace();

        // Get the class that is asking for who awoke it
        $class = $trace[1]['class'];

        // +1 to i cos we have to account for calling this function
        for ($i = 1; $i < count($trace); $i++) {
            if (isset($trace[$i])) { // is it set?
                if ($class != $trace[$i]['class']) { // is it a different class
                    return $trace[$i]['class'];
                }
            }
        }
    }


    /**
     * call
     */
    public function __call($name, $args) {
        return call_user_func_array([self::$_instance, $name], $args);
    }

    /**
     * callStatic
     */
    public static function __callStatic($name, $args) {
        return call_user_func_array([self::$_instance, $name], $args);
    }

    /**
     * create singleton instance
     * @param string|null $envPath custom env file path
     * @param string|null $basePath custom base path
     */
    public static function createInstance($envPath = null, $basePath = null) {
        if (!(self::$_instance instanceof self)) {
            // error_log('new Config instance');
            self::$_instance = new self($envPath, $basePath);
        }
        return self::$_instance;
    }

    /**
     * reload singleton instance
     * @param string|null $envPath custom env file path
     * @param string|null $basePath custom base path
     */
    public static function reloadInstance($envPath = null, $basePath = null) {
        // error_log('reload Config instance');
        self::$_instance = new self($envPath, $basePath);
        return self::$_instance;
    }

    /**
     * Get env variable by key
     * @param string $key name of env variable
     * @return mixed env values
     * @throws Exception If failed to find env variable
     */
    public static function getEnv(string $key) {
        if (!empty($key) && array_key_exists($key, self::$env)) {
            return self::$env[$key];
        } else {
            throw new \Exception('env variable does not exist');
        }
    }

    /**
     * Get SMTP password
     */
    public static function getSmtpPassword(): string {
        if (self::getCallingClass() === 'Core\Mailer') {
            return self::$smtpPassword;
        }
        return '';
    }

    /**
     * Get secret key
     */
    public static function getSecretKey(): string {
        if (self::getCallingClass() === 'Core\Crypto') {
            return self::$secretKey;
        }
        return '';
    }

    /**
     * Get auth password hash
     */
    public static function getAuthHash(): string {
        if (self::getCallingClass() === 'Core\Hasher') {
            return self::$authHash;
        }
        return '';
    }

    /**
     * get working base directory
     */
    public static function getBasePath(): string {
        return self::$basePath;
    }
}

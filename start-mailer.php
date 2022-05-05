<?php

/**
 * Entry file for email sending worker
 * use terminal command to start service
 * ---------
 * php start-mailer.php start -d
 * php start-mailer.php restart -d
 * php start-mailer.php reload
 * php start-mailer.php stop
 * php start-mailer.php status
 * php start-mailer.php connections
 * ---------
 * This file is part of the SMTP Mailer service.
 *
 * @license MIT
 * (c) 2022 Eric Chow <https://cmchow.com>
 * License at https://opensource.org/licenses/MIT
 */

require_once __DIR__ . '/vendor/autoload.php';

use Core\Config;
use Core\Logger;
use Core\Mailer;
use Core\Validator;
use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;

const MAILER_NAME = 'SMTPMailer';
const QUEUE_NAME = 'SMTPMailQueue';

// init Config and Validator instance
$env = null;
$basePath = null;
$validator = null;
$config = null;

if (isset($argv[1]) && ($argv[1] === 'start' || $argv[1] === 'restart')) {
    $cmdEnv = array_search('--env', $argv);
    if ($cmdEnv !== false && isset($argv[$cmdEnv+1])) {
        $env = $argv[$cmdEnv+1];
    }
    $baseEnv = array_search('--basepath', $argv);
    if ($baseEnv !== false && isset($argv[$baseEnv+1])) {
        $basePath = $argv[$baseEnv+1];
    }
    $validator = Validator::createInstance();
    $config = Config::createInstance($env, $basePath);
}

// check service is running in PHAR
if (!empty(\Phar::running(false))) {
    $parts = explode('/', \Phar::running(false));
    array_pop($parts);
    $pharPath = implode('/', $parts) . '/';
    Worker::$logFile = $pharPath . 'smtp-mailer-workerman.log';
    Worker::$pidFile = $pharPath . 'smtp-mailer-workerman.pid';
}

$enableSSL = Config::getEnv('MAILER_SSL');
$mailer = null; // primary service worker

// enable service SSL if needed
if ($enableSSL) {
    if (is_readable(Config::getEnv('MAILER_SSL_CERT')) && is_readable(Config::getEnv('MAILER_SSL_KEY'))) {
        $context = [
            'ssl' => [
                'local_cert' => Config::getEnv('MAILER_SSL_CERT'),
                'local_pk' => Config::getEnv('MAILER_SSL_KEY'),
                'verify_peer' => false,
                'allow_self_signed' => true
            ]
        ];
        $mailer = new Worker(Config::getEnv('MAILER_ADDR'), $context);
        $mailer->transport = 'ssl';
    } else {
        throw new \Exception('unable to read SSL cert/key file');
    }
} else {
    $mailer = new Worker(Config::getEnv('MAILER_ADDR'));
}

$mailer->count = Config::getEnv('MAILER_THREADS'); // worker threads

$mailer->name = MAILER_NAME; // worker name

$mailer->onWorkerStart = function (Worker $worker) {
    $maxMemory = Config::getEnv('MAILER_MAX_MEMORY');
    Timer::add(60, function () use ($worker, $maxMemory) {
        if (memory_get_usage(true) > $maxMemory * 1024 * 1024 && count($worker->connections) == 0) {
            // Restart current process if memory leak is detected.
            Worker::stopAll();
        }
    });
};

$mailer->onMessage = function (TcpConnection $connection, $payload) {
    $response = Mailer::response('error', null, null);
    try {
        $data = json_decode($payload, true); // decode incoming payload
        $response = Mailer::authenticateRequest($data);
    } catch (\Throwable $e) {
        Logger::log('error', "service worker exception: {$e->getMessage()}, Trace: {$e->getTraceAsString()}");
        $response = Mailer::response('error', null, 'exception occurred');
    }
    $connection->send(json_encode($response)); // return response

    $maxRequest = Config::getEnv('MAILER_MAX_REQUEST');
    if ($maxRequest > 0) {
        static $requestCount = 0;
        if (++$requestCount >= $maxRequest) {
            // Restart current process if max request is exceeded
            Worker::stopAll();
        }
    }
};

$mailer->onError = function (TcpConnection $connection, $code, $msg) {
    Logger::log('error', "service worker error ({$code}): {$msg}");
};


// queue worker (if enabled)
if (Config::getEnv('MAILER_QUEUE')) {
    $queueManager = new Worker();  // omit address for isolated worker

    $queueManager->count = 1; // single worker to avoid duplicate task

    $queueManager->name = QUEUE_NAME; // mailer worker name

    $queueManager->onWorkerStart = function (Worker $worker) {
        Timer::add(Config::getEnv('QUEUE_SCAN_INTERVAL'), function () {
            Logger::log('debug', "start processing queue");
            Mailer::processQueue();
            Logger::log('debug', "queue processed");
        });
    };

    $queueManager->onError = function (TcpConnection $connection, $code, $msg) {
        Logger::log('error', "queue worker error ({$code}): {$msg}");
    };
}

// when service is reloaded
Worker::$onMasterReload = function () use ($env, $basePath) {
    $validator = Validator::reloadInstance();
    $config = Config::reloadInstance($env, $basePath);

    // get active workers
    $activeWorkers = [];
    foreach (Worker::getAllWorkers() as $worker) {
        $activeWorkers[$worker->name] = $worker;
    }

    // change old worker config
    foreach ($activeWorkers as $service => $worker) {
        if ($worker->name === MAILER_NAME) {
            $worker->count = Config::getEnv('MAILER_THREADS');
        }
    }
};

Worker::runAll();

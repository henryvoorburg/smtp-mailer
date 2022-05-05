<?php

/**
 * Email sending functions
 * This file is part of the SMTP Mailer service.
 *
 * @license MIT
 * (c) 2022 Eric Chow <https://cmchow.com>
 * License at https://opensource.org/licenses/MIT
 */

namespace Core;

use Core\Config;
use Core\Crypto;
use Core\Logger;
use Core\Validator;
use PHPMailer\PHPMailer\PHPMailer;
use Workerman\Connection\AsyncTcpConnection;

class Mailer {

    /**
     * max item to return when getQueueList
     * @var int
     */
    private const GET_QUEUE_LIST_LIMIT = 500;

    /**
     * max item to return when getTemplateList
     * @var int
     */
    private const GET_TEMPLATE_LIST_LIMIT = 500;

    /**
     * Convert string to base64 encoded MIME with UTF8 charset
     * https://en.wikipedia.org/wiki/MIME#Encoded-Word
     *
     * @param string $title title to be encoded
     */
    private static function encodeMimeHeader(string $title): string {
        if (!empty($title)) {
            return '=?utf-8?B?' . base64_encode($title) . '?=';
        }
        return '';
    }

    /**
     * Read email HTML template file
     * @param string $file path to template file
     * @param array $content template strings to be replaced
     */
    private static function retrieveTemplate(string $file, array $content = []): string {
        $template = file_get_contents(Config::getEnv('EMAIL_TEMPLATE_DIR') . $file);
        if ($template !== false) {
            foreach ($content as $key => $value) {
                $template = str_replace(Config::getEnv('EMAIL_TEMPLATE_STRING_TAG_OPEN') . $key . Config::getEnv('EMAIL_TEMPLATE_STRING_TAG_CLOSE'), $value, $template);
            }
            return $template;
        } else {
            Logger::log('error', "mail template not found: {$file}");
        }
        return '';
    }

    /**
     * Send email
     * @param array $recipients email recipients list
     * @param array $ccList email CC list
     * @param array $bccList email BCC list
     * @param array $attachments email attachments
     * @param array $embedded email embedded images
     * @param string $subject email subject
     * @param string $body email body
     * @param string $fromName FROM header name
     * @param string $fromAddr FROM header address
     * @param string $smtpUser SMTP user
     * @param string $smtpPassword SMTP password
     * @param string $smtpHost SMTP host
     * @param int $smtpPort SMTP port
     * @param string $smtpEncryption SMTP encryption
     */
    private static function sendPhpMail(
        array $recipients,
        array $ccList,
        array $bccList,
        array $attachments,
        array $embedded,
        string $subject,
        string $body,
        string $fromName = '',
        string $fromAddr = '',
        string $smtpUser = '',
        string $smtpPassword = '',
        string $smtpHost = '',
        int $smtpPort = 0,
        string $smtpEncryption = ''
    ): array {
        $mail = new PHPMailer(true);

        try {
            $mail->SMTPDebug = 0;
            $mail->IsSMTP();

            $mail->SMTPAuth   = true;
            $mail->SMTPSecure = empty($smtpEncryption) ? Config::getEnv('SMTP_ENCRYPTION') : $smtpEncryption;
            $mail->Port       = $smtpPort > 0 ? $smtpPort : Config::getEnv('SMTP_PORT');
            $mail->Host       = empty($smtpHost) ? Config::getEnv('SMTP_HOST') : $smtpHost;
            $mail->Username   = empty($smtpUser) ? Config::getEnv('SMTP_USER') : $smtpUser;
            $mail->Password   = empty($smtpPassword) ? Config::getSmtpPassword() : $smtpPassword;

            // Sent from
            $mail->setFrom(
                empty($fromAddr) ? Config::getEnv('MAIL_FROM_ADDR') : $fromAddr,
                self::encodeMimeHeader(empty($fromName) ? Config::getEnv('MAIL_FROM_NAME') : $fromName)
            );

            // Add recipients
            foreach ($recipients as $user) {
                if (is_string($user)) {
                    $mail->addAddress($user);
                } elseif (is_array($user) && count($user) == 2) {
                    $mail->addAddress($user[0], self::encodeMimeHeader($user[1]));
                }
            }
            // Add CC recipients
            foreach ($ccList as $cc) {
                if (is_string($cc)) {
                    $mail->addCC($cc);
                } elseif (is_array($cc) && count($cc) == 2) {
                    $mail->addCC($cc[0], self::encodeMimeHeader($cc[1]));
                }
            }
            // Add BCC recipients
            foreach ($bccList as $bcc) {
                if (is_string($bcc)) {
                    $mail->addBCC($bcc);
                } elseif (is_array($bcc) && count($bcc) == 2) {
                    $mail->addBCC($bcc[0], self::encodeMimeHeader($bcc[1]));
                }
            }
            // $mail->addReplyTo('info@example.com', 'Information');


            // Add Attachments
            foreach ($attachments as $file) {
                if (is_array($file) && count($file) >= 1) {
                    $path = $file[0];
                    $name = $file[1] ?? false;

                    if (!$name) {
                        $mail->addAttachment($path);
                    } else {
                        $mail->addAttachment($path, $name);
                    }
                }
            }

            // Add Embedded Image
            foreach ($embedded as $img) {
                if (is_array($img) && count($img) >= 2) {
                    $path = $img[0];
                    $cid = $img[1];
                    $name = $img[2] ?? false;

                    if (!$name) {
                        $mail->AddEmbeddedImage($path, $cid);
                    } else {
                        $mail->AddEmbeddedImage($path, $cid, $name);
                    }
                }
            }

            //Content
            $mail->isHTML(Config::getEnv('MAIL_HTML'));
            $mail->CharSet = Config::getEnv('MAIL_CHARSET');
            $mail->Subject = self::encodeMimeHeader($subject);
            $mail->Body    = $body;

            if ($mail->send() !== false) {
                return [true, ''];
            }
            return [false, $mail->ErrorInfo];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
        return [false, 'PHPMailer init error'];
    }

    /**
     * Prepare email content for instant SMTP sending
     * @param mixed $data incoming payload
     */
    protected static function prepareAndSendMail($data): array {
        $response = self::response('error', null, 'failed to prepare mail');

        [$valid, $error] = Validator::validate('sendMail', $data);

        if ($valid) {
            if (!(empty($data['to']) && empty($data['ccList']) && empty($data['bccList']))) {

                // read template as body and replace string if needed
                if (isset($data['useTemplate'])) {
                    $templateIsEnabled = Config::getEnv('EMAIL_TEMPLATE');
                    if ($templateIsEnabled) {
                        if (isset($data['replaceContent'])) {
                            $data['body'] = self::retrieveTemplate($data['useTemplate'], $data['replaceContent']);
                        } else {
                            $data['body'] = self::retrieveTemplate($data['useTemplate']);
                        }
                    } else {
                        Logger::log('info', "failed to send mail using template");
                        $response = self::templateNotEnabled();
                        return $response;
                    }
                }

                [$sent, $error] = self::sendPhpMail(
                    $data['to'] ?? [],
                    $data['ccList'] ?? [],
                    $data['bccList'] ?? [],
                    $data['attachments'] ?? [],
                    $data['embedded'] ?? [],
                    $data['subject'] ?? '',
                    $data['body'] ?? '',
                    $data['fromName'] ?? '',
                    $data['fromEmail'] ?? '',
                    $data['smtpUser'] ?? '',
                    $data['smtpPassword'] ?? '',
                    $data['smtpHost'] ?? '',
                    $data['smtpPort'] ?? 0,
                    $data['smtpEncryption'] ?? ''
                );

                if ($sent) {
                    Logger::log('info', "mail sent successfully");
                    $response = self::response('success', null, 'mail sent successfully');
                } else {
                    Logger::log('error', "PHPMailer Error: {$error}");
                    $response = self::response('error', $error, 'failed to send mail');
                }
            } else {
                Logger::log('warning', "mail must have at least one recipient");
                $response = self::response('error', 'To/CC/BCC must have at least one recipient', 'invalid payload');
            }
        } else {
            Logger::log('warning', "invalid mail payload");
            $response = self::response('error', $error, 'invalid payload');
        }
        return $response;
    }

    /**
     * output mail as JSON file for queue processing
     * @param mixed $data incoming payload
     * @param bool $isFailed is failed mail
     */
    protected static function outputMailToQueue($data, bool $isFailed = false): bool {
        $fullEncrypt = Config::getEnv('QUEUE_FULL_ENCRYPT');
        $queueDir = Config::getEnv('QUEUE_DIR');

        if (!array_key_exists('failToDelivered', $data)) {
            $data['failToDelivered'] = 0;
        }
        if ($isFailed) {
            $data['failToDelivered'] += 1;
        }

        // encrypt SMTP password if needed
        if (!$fullEncrypt && array_key_exists('smtpPassword', $data)) {
            $data['smtpEncryptPassword'] = Crypto::encrypt($data['smtpPassword']);
            unset($data['smtpPassword']);
        }

        // use schedule time or current time for file name
        $filename = 'mail_' . strval(array_key_exists('scheduleTime', $data) ? $data['scheduleTime'] : time()) . '_' . uniqid('', true) . '.json';

        // encrypt full document if needed and save to disk
        return file_put_contents($queueDir . $filename, $fullEncrypt ? Crypto::encrypt(json_encode($data)) : json_encode($data));
    }

    /**
     * add SMTP email to queue or with scheduled time
     * @param mixed $data incoming payload
     */
    protected static function queueMail($data): array {
        $response = self::response('error', null, 'failed to process queueMail');

        [$valid, $error] = Validator::validate('sendMail', $data);

        if ($valid) {
            if (!(empty($data['to']) && empty($data['ccList']) && empty($data['bccList']))) {
                $output = self::outputMailToQueue($data, false);
                if ($output) {
                    Logger::log('info', "mail added to queue");
                    $response = self::response('success', null, 'mail added to queue');
                } else {
                    Logger::log('error', "failed to add mail to queue");
                    $response = self::response('error', null, 'failed to add mail to queue');
                }
            } else {
                Logger::log('warning', "queueMail must have at least one recipient");
                $response = self::response('error', 'To/CC/BCC must have at least one recipient', 'invalid payload');
            }
        } else {
            Logger::log('warning', "invalid queueMail payload");
            $response = self::response('error', $error, 'invalid payload');
        }
        return $response;
    }

    /**
     * Instant SMTP email sending
     * @param mixed $data incoming payload
     */
    protected static function sendMail($data): array {
        $hasQueue = Config::getEnv('MAILER_QUEUE');
        $maxRetry = Config::getEnv('QUEUE_MAX_FAILED_RETRY');

        // send mail via PhpMailer
        $response = self::prepareAndSendMail($data);

        if ($response['status'] != 'success' && $response['message'] === 'failed to send mail') {
            // push back to queue for retry
            if ($hasQueue && ($maxRetry > 0 || $maxRetry === -1)) {
                $output = self::outputMailToQueue($data, true);
                if ($output) {
                    Logger::log('info', "failed mail added to queue");
                } else {
                    Logger::log('error', "unable to add failed mail to queue");
                }
            }
        }

        return $response;
    }

    /**
     * queue is not enabled response
     */
    protected static function queueNotEnabled(): array {
        return self::response('error', null, 'queue service is not enabled');
    }

    /**
     * queue is read-only response
     */
    protected static function queueIsReadOnly(): array {
        return self::response('error', null, 'queue service is read-only');
    }

    /**
     * Get list of all queued mails
     * @param mixed $data incoming payload
     */
    protected static function getQueueList($data): array {
        $limit = self::GET_QUEUE_LIST_LIMIT;

        if (isset($data) && is_int($data)) {
            $limit = $data;
        }

        $queueDir = Config::getEnv('QUEUE_DIR');
        $jsonList = self::scanQueueDir($queueDir);

        if ($jsonList !== false) {
            if (count($jsonList) > 0) {

                // limit display items or negative to display all
                $slicedList = $jsonList;
                if ($limit > 0) {
                    $slicedList = array_slice($jsonList, 0, $limit);
                }
                $total = count($jsonList);
                return self::response('success', ['items' => $slicedList, 'total' => $total], 'found ' . strval($total) . ' mails in queue');
            } else {
                return self::response('success', [], 'queue list is empty');
            }
        } else {
            Logger::log('warning', "unable to read queue dir");
        }
        return self::response('error', null, 'unable to get queue list');
    }

    /**
     * Get queued mail content
     * @param mixed $data incoming payload
     */
    protected static function getQueuedMail($data): array {
        if (isset($data) && is_string($data) && preg_match(Validator::QUEUE_FILE_REGEX, $data)) {
            $queueDir = Config::getEnv('QUEUE_DIR');
            $fullEncrypt = Config::getEnv('QUEUE_FULL_ENCRYPT');
            $filepath = $queueDir . $data;

            if (is_file($filepath)) {
                $content = file_get_contents($filepath);
                if ($content !== false) {
                    // decrypt full document if needed
                    $json = $fullEncrypt ? Crypto::decrypt($content) : $content;

                    if ($json !== false) {
                        $mail = json_decode($json, true);

                        // remove sensitive fields
                        if (array_key_exists('smtpPassword', $mail)) {
                            unset($mail['smtpPassword']);
                        }
                        if (array_key_exists('smtpEncryptPassword', $mail)) {
                            unset($mail['smtpEncryptPassword']);
                        }

                        return self::response('success', $mail, $data);
                    } else {
                        Logger::log('error', "failed to decrypt queue mail ({$data})");
                    }
                } else {
                    Logger::log('error', "failed to read queue mail ({$data})");
                }
            }
        } else {
            Logger::log('warning', "invalid getQueuedMail payload");
            return self::response('error', 'invalid file path string', 'invalid payload');
        }
        return self::response('error', null, 'unable to get queued mail');
    }

    /**
     * update queued mail content
     * @param mixed $payload incoming payload
     */
    protected static function updateQueuedMail($payload): array {
        $data = $payload['updateQueuedMail'];
        if (isset($data) && is_string($data) && preg_match(Validator::QUEUE_FILE_REGEX, $data) && isset($payload['content'])) {
            [$valid, $error] = Validator::validate('updateQueuedMail', $payload['content']);

            if ($valid) {
                $queueDir = Config::getEnv('QUEUE_DIR');
                $fullEncrypt = Config::getEnv('QUEUE_FULL_ENCRYPT');
                $filepath = $queueDir . $data;
                if (is_file($filepath)) {
                    $content = file_get_contents($filepath);
                    if ($content !== false) {
                        // decrypt full document if needed
                        $json = $fullEncrypt ? Crypto::decrypt($content) : $content;

                        if ($json !== false) {
                            $mail = json_decode($json, true);
                            $update = $payload['content'];

                            // update queue mail data
                            if (array_key_exists('smtpPassword', $update)) {
                                if ($fullEncrypt) {
                                    $mail['smtpPassword'] = $update['smtpPassword'];
                                } else {
                                    $mail['smtpEncryptPassword'] = Crypto::encrypt($update['smtpPassword']);
                                }
                                unset($update['smtpPassword']);
                            }

                            foreach ($update as $key => $value) {
                                $mail[$key] = $value;
                            }

                            // use new / old schedule time / current time for file name
                            $filename = 'mail_' . strval(array_key_exists('scheduleTime', $update) ? $update['scheduleTime'] : (array_key_exists('scheduleTime', $mail) ? $mail['scheduleTime'] : time())) . '_' . uniqid('', true) . '.json';

                            if (unlink($filepath)) {
                                if (file_put_contents($queueDir . $filename, $fullEncrypt ? Crypto::encrypt(json_encode($mail)) : json_encode($mail))) {
                                    Logger::log('notice', 'updated mail in queue');
                                    if (array_key_exists('smtpPassword', $mail)) {
                                        unset($mail['smtpPassword']);
                                    }
                                    if (array_key_exists('smtpEncryptPassword', $mail)) {
                                        unset($mail['smtpEncryptPassword']);
                                    }
                                    return self::response('success', $mail, "updated queue mail {$data}");
                                } else {
                                    Logger::log('error', "updateQueuedMail unable to save new file ({$filename})");
                                }
                            } else {
                                Logger::log('error', "updateQueuedMail unable to delete old file ({$data})");
                            }
                        } else {
                            Logger::log('error', "failed to decrypt queue mail ({$data})");
                        }
                    } else {
                        Logger::log('error', "failed to read queue mail ({$data})");
                    }
                }
            } else {
                Logger::log('warning', "invalid updateQueuedMail payload");
                return self::response('error', $error, 'invalid payload');
            }
        } else {
            Logger::log('warning', "invalid updateQueuedMail payload");
            return self::response('error', 'invalid file path string or missing content', 'invalid payload');
        }
        return self::response('error', null, 'unable to update queued mail');
    }

    /**
     * delete queue mail
     * @param mixed $data incoming payload
     */
    protected static function removeQueuedMail($data): array {
        if (isset($data) && is_string($data) && preg_match(Validator::QUEUE_FILE_REGEX, $data)) {
            $queueDir = Config::getEnv('QUEUE_DIR');
            $filepath = $queueDir . $data;

            if (is_file($filepath)) {
                if (unlink($filepath)) {
                    return self::response('success', null, 'queued mail removed');
                } else {
                    Logger::log('error', "failed to remove queued mail ({$data})");
                }
            }
        } else {
            Logger::log('warning', "invalid removeQueuedMail payload");
            return self::response('error', 'invalid file path string', 'invalid payload');
        }
        return self::response('error', null, 'unable to remove queued mail');
    }

    /**
     * clear all queued mail
     */
    protected static function clearQueue(): array {
        $queueDir = Config::getEnv('QUEUE_DIR');
        $jsonList = self::scanQueueDir($queueDir);

        if ($jsonList !== false) {
            if (count($jsonList) > 0) {
                foreach ($jsonList as $file) {
                    if (is_file($queueDir . $file)) {
                        @unlink($queueDir . $file);
                    }
                }

                return self::response('success', null, 'removed ' . strval(count($jsonList)) . ' mails in queue');
            } else {
                return self::response('success', [], 'queue list is empty');
            }
        } else {
            Logger::log('warning', "unable to read queue dir");
        }
        return self::response('error', null, 'unable to read queue dir');
    }

    /**
     * template is not enabled response
     */
    protected static function templateNotEnabled(): array {
        return self::response('error', null, 'template service is not enabled');
    }

    /**
     * template is read-only response
     */
    protected static function templateIsReadOnly(): array {
        return self::response('error', null, 'template service is read-only');
    }

    /**
     * Get list of all email templates
     * @param mixed $data incoming payload
     */
    protected static function getTemplateList($data): array {
        $limit = self::GET_TEMPLATE_LIST_LIMIT;

        if (isset($data) && is_int($data)) {
            $limit = $data;
        }

        $templateDir = Config::getEnv('EMAIL_TEMPLATE_DIR');
        $templateList = self::scanTemplateDir($templateDir);

        if ($templateList !== false) {
            if (count($templateList) > 0) {

                // limit display items or negative to display all
                $slicedList = $templateList;
                if ($limit > 0) {
                    $slicedList = array_slice($templateList, 0, $limit);
                }
                $total = count($templateList);
                return self::response('success', ['items' => $slicedList, 'total' => $total], 'found ' . strval($total) . ' templates');
            } else {
                return self::response('success', [], 'template list is empty');
            }
        } else {
            Logger::log('warning', "unable to read template dir");
        }
        return self::response('error', null, 'unable to get template list');
    }

    /**
     * Get template content
     * @param mixed $data incoming payload
     */
    protected static function getTemplate($data): array {
        if (isset($data) && is_string($data)) {
            $templateDir = Config::getEnv('EMAIL_TEMPLATE_DIR');
            $filepath = $templateDir . $data;

            if (is_file($filepath)) {
                $content = file_get_contents($filepath);
                if ($content !== false) {
                    return self::response('success', $content, 'template found');
                } else {
                    Logger::log('error', "failed to read template ({$data})");
                }
            }
        } else {
            Logger::log('warning', "invalid getTemplate payload");
            return self::response('error', 'invalid file path string', 'invalid payload');
        }
        return self::response('error', null, 'unable to get template');
    }

    /**
     * add template file
     * @param mixed $data incoming payload
     */
    protected static function addTemplate($payload): array {
        $data = $payload['addTemplate'];
        if (isset($data) && is_string($data) && isset($payload['content']) && is_string($payload['content'])) {
            $templateDir = Config::getEnv('EMAIL_TEMPLATE_DIR');
            $filepath = $templateDir . $data;
            $content = $payload['content'];

            if (!is_file($filepath)) {
                if (file_put_contents($filepath, $content)) {
                    Logger::log('notice', "added template ({$data})");
                    return self::response('success', null, "added template {$data}");
                } else {
                    Logger::log('error', "failed to add template ({$data})");
                }
            } else {
                Logger::log('error', "failed to add template ({$data})");
                return self::response('error', 'file already exists', 'unable to add template');
            }
        } else {
            Logger::log('warning', "invalid addTemplate payload");
            return self::response('error', 'invalid file path string or missing content', 'invalid payload');
        }
        return self::response('error', null, 'unable to add template');
    }

    /**
     * update template content
     * @param mixed $payload incoming payload
     */
    protected static function updateTemplate($payload): array {
        $data = $payload['updateTemplate'];
        if (isset($data) && is_string($data) && isset($payload['content']) && is_string($payload['content'])) {
            $templateDir = Config::getEnv('EMAIL_TEMPLATE_DIR');
            $filepath = $templateDir . $data;
            $content = $payload['content'];

            if (is_file($filepath)) {
                if (file_put_contents($filepath, $content)) {
                    Logger::log('notice', "updated template ({$data})");
                    return self::response('success', null, "updated template {$data}");
                } else {
                    Logger::log('error', "failed to write template ({$data})");
                }
            }
        } else {
            Logger::log('warning', "invalid updateTemplate payload");
            return self::response('error', 'invalid file path string or missing content', 'invalid payload');
        }
        return self::response('error', null, 'unable to update template');
    }

    /**
     * delete template
     * @param mixed $data incoming payload
     */
    protected static function removeTemplate($data): array {
        if (isset($data) && is_string($data)) {
            $templateDir = Config::getEnv('EMAIL_TEMPLATE_DIR');
            $filepath = $templateDir . $data;

            if (is_file($filepath)) {
                if (unlink($filepath)) {
                    return self::response('success', null, 'template removed');
                } else {
                    Logger::log('error', "failed to remove template ({$data})");
                }
            }
        } else {
            Logger::log('warning', "invalid removeTemplate payload");
            return self::response('error', 'invalid file path string', 'invalid payload');
        }
        return self::response('error', null, 'unable to remove template');
    }

    /**
     * clear all template
     */
    protected static function clearTemplate(): array {
        $templateDir = Config::getEnv('EMAIL_TEMPLATE_DIR');
        $templateList = self::scanTemplateDir($templateDir);

        if ($templateList !== false) {
            if (count($templateList) > 0) {
                foreach ($templateList as $file) {
                    if (is_file($templateDir . $file)) {
                        @unlink($templateDir . $file);
                    }
                }

                return self::response('success', null, 'removed ' . strval(count($templateList)) . ' templates');
            } else {
                return self::response('success', [], 'no template found');
            }
        } else {
            Logger::log('warning', "unable to read template dir");
        }
        return self::response('error', null, 'unable to read template dir');
    }

    /**
     * Process queue file
     * @param string $file queue JSON filename
     */
    protected static function processQueueFile(string $file): array {
        $response = self::response('error', null, 'unable to find or read queue mail');

        $maxRetry = Config::getEnv('QUEUE_MAX_FAILED_RETRY');
        $fullEncrypt = Config::getEnv('QUEUE_FULL_ENCRYPT');
        $queuePath = Config::getEnv('QUEUE_DIR') . $file;
        $filepath = Config::getEnv('QUEUE_PROCESS_DIR') . $file;

        if (is_file($filepath)) {
            $content = file_get_contents($filepath);
            if ($content !== false) {
                // decrypt full document if needed
                $json = $fullEncrypt ? Crypto::decrypt($content) : $content;

                if ($json !== false) {
                    $data = json_decode($json, true);
                    // decrypt SMTP password if needed
                    if (!$fullEncrypt && array_key_exists('smtpEncryptPassword', $data)) {
                        $pw = Crypto::decrypt($data['smtpEncryptPassword']);
                        if ($pw !== false) {
                            $data['smtpPassword'] = $pw;
                            unset($data['smtpEncryptPassword']);
                        } else {
                            Logger::log('error', "failed to decrypt queue mail ({$file})");
                            return $response;
                        }
                    }

                    // send mail via PhpMailer
                    $response = self::prepareAndSendMail($data);

                    if ($response['status'] === 'success') {
                        Logger::log('info', "queue mail sent successfully ({$file})");
                        // clear queue file
                        if (!unlink($filepath)) {
                            Logger::log('warning', "unable to clear queue mail ({$file})");
                        }
                    } elseif ($response['message'] === 'failed to send mail') {
                        Logger::log('error', "failed to send queue mail ({$file})");
                        // push back to queue for retry
                        if (array_key_exists('failToDelivered', $data)) {
                            $data['failToDelivered'] += 1;
                        } else {
                            $data['failToDelivered'] = 1;
                        }

                        // encrypt SMTP password if needed
                        if (!$fullEncrypt && array_key_exists('smtpPassword', $data)) {
                            $data['smtpEncryptPassword'] = Crypto::encrypt($data['smtpPassword']);
                            unset($data['smtpPassword']);
                        }

                        // encrypt full document if needed
                        file_put_contents($filepath, $fullEncrypt ? Crypto::encrypt(json_encode($data)) : json_encode($data));

                        if ($data['failToDelivered'] <= $maxRetry || $maxRetry === -1) {
                            // move back to queue folder
                            if (!rename($filepath, $queuePath)) {
                                Logger::log('error', "unable to move mail back to queue ({$file})");
                            } else {
                                $response['message'] = 'failed to send mail and added back to queue';
                            }
                        } else {
                            // delete file
                            if (!unlink($filepath)) {
                                Logger::log('warning', "unable to clear failed queue mail ({$file})");
                            } else {
                                $response['message'] = 'failed to send mail and discarded';
                            }
                        }
                    }
                } else {
                    Logger::log('error', "failed to decrypt queue mail ({$file})");
                }
            } else {
                Logger::log('error', "failed to read queue mail ({$file}) in QUEUE_PROCESS_DIR dir");
            }
        } else {
            Logger::log('warning', "queue mail not found");
        }
        return $response;
    }

    /**
     * Process incoming request
     * @param string $request request method name
     * @param mixed $payload incoming payload
     */
    protected static function processRequest(string $request, $data): array {
        $response = self::response('error', null, 'invalid request');

        $queueEnabled = Config::getEnv('MAILER_QUEUE');
        $queueApiReadOnly = Config::getEnv('MAILER_QUEUE_API_READ_ONLY');
        $templateEnabled = Config::getEnv('EMAIL_TEMPLATE');
        $templateApiReadOnly = Config::getEnv('EMAIL_TEMPLATE_API_READ_ONLY');

        switch ($request) {
            case 'sendMail':
                $response = self::sendMail($data);
                break;
            case 'queueMail':
                $response = $queueEnabled ? self::queueMail($data) : self::queueNotEnabled();
                break;
            case 'getQueueList':
                $response = $queueEnabled ? self::getQueueList($data) : self::queueNotEnabled();
                break;
            case 'getQueuedMail':
                $response = $queueEnabled ? self::getQueuedMail($data) : self::queueNotEnabled();
                break;
            case 'updateQueuedMail':
                $response = $queueEnabled ? ($queueApiReadOnly ? self::queueIsReadOnly() : self::updateQueuedMail($data)) : self::queueNotEnabled();
                break;
            case 'removeQueuedMail':
                $response = $queueEnabled ? ($queueApiReadOnly ? self::queueIsReadOnly() : self::removeQueuedMail($data)) : self::queueNotEnabled();
                break;
            case 'clearQueue':
                $response = $queueEnabled ? ($queueApiReadOnly ? self::queueIsReadOnly() : self::clearQueue($data)) : self::queueNotEnabled();
                break;
            case 'getTemplateList':
                $response = $templateEnabled ? self::getTemplateList($data) : self::templateNotEnabled();
                break;
            case 'getTemplate':
                $response = $templateEnabled ? self::getTemplate($data) : self::templateNotEnabled();
                break;
            case 'addTemplate':
                $response = $templateEnabled ? ($templateApiReadOnly ? self::templateIsReadOnly() : self::addTemplate($data)) : self::templateNotEnabled();
                break;
            case 'updateTemplate':
                $response = $templateEnabled ? ($templateApiReadOnly ? self::templateIsReadOnly() : self::updateTemplate($data)) : self::templateNotEnabled();
                break;
            case 'removeTemplate':
                $response = $templateEnabled ? ($templateApiReadOnly ? self::templateIsReadOnly() : self::removeTemplate($data)) : self::templateNotEnabled();
                break;
            case 'clearTemplate':
                $response = $templateEnabled ? ($templateApiReadOnly ? self::templateIsReadOnly() : self::clearTemplate($data)) : self::templateNotEnabled();
                break;
            default:
                Logger::log('warning', "invalid request");
        }

        Logger::log('debug', "request processed: {$request}");
        return $response;
    }

    /**
     * Scan queue directory for JSON
     * @return array|bool return a file list, false when failed
     */
    protected static function scanQueueDir(string $dir) {
        $fileList = scandir($dir);
        if ($fileList !== false) {
            // filter out non-JSON files
            return array_values(preg_grep(Validator::QUEUE_FILE_REGEX, $fileList));
        }
        return false;
    }

    /**
     * Scan template directory for files
     * @return array|bool return a file list, false when failed
     */
    protected static function scanTemplateDir(string $dir) {
        $fileList = scandir($dir);
        if ($fileList !== false) {
            return array_values(preg_grep(Validator::TEMPLATE_FILE_REGEX, $fileList));
        }
        return false;
    }

    /**
     * Process queue
     * @param int $now current timestamp for unit testing
     */
    public static function processQueue(int $now = -1): bool {
        $currentTime = $now > 0 ? $now : time();
        $queueDir = Config::getEnv('QUEUE_DIR');
        $workingDir = Config::getEnv('QUEUE_PROCESS_DIR');
        $jsonList = self::scanQueueDir($queueDir);

        if ($jsonList !== false) {
            if (count($jsonList) > 0) {
                // select a batch of queue mails
                $filesToProcess = array_slice($jsonList, 0, Config::getEnv('QUEUE_MAX_BATCH_SIZE'));
                $fileCount = 0;

                // move mail to temp working directory
                foreach ($filesToProcess as $file) {
                    if (is_file($queueDir . $file)) {
                        $createTime = explode('_', explode('.', $file)[0])[1];
                        // check schedule time
                        if (isset($createTime) && intval($createTime) <= $currentTime) {
                            if (!rename($queueDir . $file, $workingDir . $file)) {
                                Logger::log('error', "unable to move queue mail to work dir");
                            } else {
                                $fileCount++;
                            }
                        }
                    }
                }
                Logger::log('info', "moved {$fileCount} queue mail to work dir");

                // send filename to primary worker for mail sending
                foreach ($filesToProcess as $file) {
                    if (is_file($workingDir . $file) && $now < 0) {
                        Logger::log('debug', "passing {$file} queue");
                        $payload = [
                            'processQueueFile' => Crypto::encrypt($file)
                        ];
                        $enableSSL = Config::getEnv('MAILER_SSL');

                        try {
                            $tcp = null;
                            if ($enableSSL) {
                                // enable ssl on tcp
                                $context = [
                                    'ssl' => [
                                        'local_cert' => Config::getEnv('MAILER_SSL_CERT'),
                                        'local_pk' => Config::getEnv('MAILER_SSL_KEY'),
                                        'verify_peer' => false,
                                        'allow_self_signed' => true
                                    ]
                                ];
                                $tcp = new AsyncTcpConnection(Config::getEnv('MAILER_ADDR'), $context);
                                $tcp->transport = 'ssl';
                            } else {
                                $tcp = new AsyncTcpConnection(Config::getEnv('MAILER_ADDR'));
                            }
                            $tcp->onConnect = function ($connection) use ($payload) {
                                Logger::log('debug', "sending queue mail to primary worker");
                                $connection->send(json_encode($payload));
                            };
                            $tcp->onMessage = function ($connection, $result) {
                                Logger::log('debug', "queue AsyncTcpConnection result: {$result}");
                                $connection->close();
                            };
                            $tcp->onError = function ($connection, $code, $msg) {
                                Logger::log('error', "unable to pass queue mail to primary worker: ({$code}): {$msg}");
                            };
                            $tcp->connect();
                        } catch (\Throwable $e) {
                            Logger::log('error', "unable to pass queue mail to primary worker exception: {$e->getMessage()}, Trace: {$e->getTraceAsString()}");
                        }
                    }
                }
                return true;
            } else {
                Logger::log('debug', "no queue mail to process");
            }
        } else {
            Logger::log('error', "unable to read queue dir");
        }
        return false;
    }

    /**
     * Authenticate incoming request
     * @param mixed $data incoming payload
     */
    public static function authenticateRequest($data): array {
        $response = self::response('error', null, 'invalid request');

        $requireAuth = Config::getEnv('MAILER_AUTH');
        $queueIsEnabled = Config::getEnv('MAILER_QUEUE');

        $auth = false;

        if ($requireAuth) {
            if (!empty($data) && is_array($data) && array_key_exists('auth', $data) && Hasher::verify($data['auth'])) { // verify auth password
                $auth = true;
            }
        } else {
            $auth = true;
        }

        if ($queueIsEnabled) {
            if (!empty($data) && is_array($data) && array_key_first($data) === 'processQueueFile' && isset($data['processQueueFile'])) { // allow request from queue worker with verified encryption
                $file = Crypto::decrypt($data['processQueueFile']);
                if ($file !== false && preg_match(Validator::QUEUE_FILE_REGEX, $file)) {
                    $auth = true;
                    $data['processQueueFile'] = $file;
                    Logger::log('debug', "processQueueFile request received");
                } else {
                    $auth = false;
                    Logger::log('info', "invalid processQueueFile request");
                }
            }
        }

        if ($auth) {
            if (!empty($data) && is_array($data)) {
                $request = array_key_first($data);
                if ($request !== null) {
                    if ($request === 'processQueueFile') {
                        $response = $queueIsEnabled ? self::processQueueFile($data[$request]) : self::queueNotEnabled();
                    } elseif ($request === 'addTemplate' || $request === 'updateTemplate' || $request === 'updateQueuedMail') {
                        $response = self::processRequest($request, $data);
                    } else {
                        $response = self::processRequest($request, $data[$request]);
                    }
                } else {
                    Logger::log('warning', "request with invalid payload");
                    $response = self::response('error', null, 'invalid payload');
                }
            } else {
                Logger::log('warning', "request with empty payload");
                $response = self::response('error', null, 'payload cannot be empty');
            }
        } else {
            Logger::log('warning', "unauthorized request");
            $response = self::response('error', null, 'unauthorized request');
        }

        return $response;
    }

    /**
     * API response object
     * @param string $status status string
     * @param mixed $data response data (null if none)
     * @param mixed $message additional message (null if none)
     */
    public static function response(string $status, $data, $message): array {
        return [
            'status' => $status,
            'data' => $data,
            'message' => $message
        ];
    }
}

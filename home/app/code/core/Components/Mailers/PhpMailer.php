<?php

namespace App\Core\Components\Mailers;

use Phalcon\Config\Config;
use Phalcon\Logger\Logger;
use App\Core\Components\Log;

class PhpMailer extends \App\Core\Components\Base
{
    public function sendmail(string $email, string $subject, string $body, int $debug = 0, bool $isHtml = true, array $bccs = [], array $replyTo = [], array $files = [])
    {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer();
            $mail->CharSet = "UTF-8";
            // Enable verbose debug output
            $mail->SMTPDebug = $debug;
            // Set mailer to use SMTP
            $mail->isSMTP();
            // Specify main and backup SMTP servers
            $mail->Host = $this->di->getConfig()->get('mailer')->get('smtp')->get('host');
            // Enable SMTP authentication
            $mail->SMTPAuth = true;
            // SMTP username
            $mail->Username = $this->di->getConfig()->get('mailer')->get('smtp')->get('username');
            // SMTP password
            $mail->Password = $this->di->getConfig()->get('mailer')->get('smtp')->get('password');
            // Enable TLS encryption, `ssl` also accepted
            $mail->SMTPSecure = 'tls';
            // TCP port to connect to
            $mail->Port = $this->di->getConfig()->get('mailer')->get('smtp')->get('port');
            //Recipients
            $mail->setFrom(
                $this->di->getConfig()->get('mailer')->get('sender_email'),
                $this->di->getConfig()->get('mailer')->get('sender_name')
            );
            // Set the "Reply-To" address
            $mail->addReplyTo(
                $replyTo['replyToEmail'] ?? $this->di->getConfig()->get('mailer')->get('sender_email'),
                $replyTo['replyToName'] ?? $this->di->getConfig()->get('mailer')->get('sender_name')
            );
            // Add a recipient
            $mail->addAddress($email, $email);
            if (!count($bccs)) {
                $bccs = explode(',', $this->di->getConfig()->get('mailer')->get('bcc'));
            }

            // Attachments
            if ($files) {
                foreach ($files as $file) {
                    $mail->addAttachment($file['path'], $file['name']);
                }
            }

            foreach ($bccs as $value) {
                if (!empty($value)) {
                    $mail->addBCC($value);
                }
            }
            //
            //  CONTENT
            //
            // Set email format to HTML
            $mail->isHTML($isHtml);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->AltBody = $body;
            if (!$mail->send()) {
                $this->di->getLog()->logContent(
                    "Mail [to $email] could not be sent : " . $mail->ErrorInfo,
                    Logger::CRITICAL,
                    'mail.log'
                );
                return false;
            }
            return true;
        } catch (\Exception $e) {
            $this->di->getLog()->logContent(
                'Message could not be sent. Mailer Error: ' . $email . '=>' . $subject . ' : ' . (isset($mail) ? $mail->ErrorInfo : "something went wrong"),
                Logger::CRITICAL,
                'mail.log'
            );
        }
    }

    public function send(string $email, string $subject, string $body, array $options = [])
    {
        $logger = $this->di->getLog();
        $config = $this->di->getConfig()->get('mailer');
        try {
            if (is_null($config)) {
                return false;
            }
            $mail = $this->initializeMailer($options['debug'], $config);
            $subject = $this->updateSubjectWithAppName($subject); //this is added to append subject name
            $this->setSenderInfo($mail, $config);
            $this->setReplyTo($mail, $options['replyTo'] ?? [], $config);
            $this->addRecipient($mail, $email);
            $this->setBCCRecipients($mail, $options['bccs'] ?? [], $config);
            $this->setCCRecipients($mail, $options['ccs'] ?? [], $config);
            $this->attachFiles($mail, $options['files'] ?? []);
            $this->setEmailContent($mail, $options['isHtml'], $subject, $body);
            if (!$mail->send()) {
                $this->handleSendError($logger, $email, $subject, $mail->ErrorInfo);
                return false;
            }
            return true;
        } catch (\Exception $e) {
            $this->handleException($logger, $email, $subject,  isset($mail) ? $mail->ErrorInfo : "something went wrong");
        }
        return false;
    }

    private function updateSubjectWithAppName($subject)
    {
        $appName = $this->di->getConfig()->app_name ?? null;
        if (!empty($appName)) {
            if (stripos($subject, $appName) === false) {
                $subject = "$subject - $appName";
            }
        }
        return $subject;
    }

    private function initializeMailer(int $debug, Config $config)
    {
        $mail = new \PHPMailer\PHPMailer\PHPMailer();
        $mail->CharSet = "UTF-8";
        $mail->SMTPDebug = $debug;
        $mail->isSMTP();
        $mail->Host = $config->get('smtp')->get('host');
        $mail->SMTPAuth = true;
        $mail->Username = $config->get('smtp')->get('username');
        $mail->Password = $config->get('smtp')->get('password');
        $mail->SMTPSecure = 'tls';
        $mail->Port = $config->get('smtp')->get('port');

        return $mail;
    }

    private function setSenderInfo($mail, Config $config)
    {
        $mail->setFrom($config->get('sender_email'), $config->get('sender_name'));
    }

    private function setReplyTo($mail, array $replyTo, Config $config)
    {
        $replyToEmail = $replyTo['replyToEmail'] ?? $config->get('sender_email');
        $replyToName = $replyTo['replyToName'] ?? $config->get('sender_name');
        $mail->addReplyTo($replyToEmail, $replyToName);
    }

    private function addRecipient($mail, string $email)
    {
        $mail->addAddress($email, $email);
    }

    private function setBCCRecipients($mail, array $bccs, Config $config)
    {
        $serverBccs = [];
        if (!empty($config->get('bcc'))) {
            $serverBccs = explode(',', $config->get('bcc'));
        }
      
        if (!count($bccs)) {
            $bccs = $serverBccs;
        } elseif (!empty($serverBccs)) {
            foreach ($serverBccs as $serverBcc) {
                if (!in_array($serverBcc, $bccs)) {
                    $bccs[] = $serverBcc;
                }
            }
        }
        foreach ($bccs as $bcc) {
            $mail->addBCC($bcc);
        }
    }

    private function setCCRecipients($mail, array $ccs, Config $config)
    {
        if (empty($ccs) && !empty($config->get('cc'))) {
            $ccs = explode(',', $config->get('cc'));
        }

        foreach ($ccs as $cc) {
            $mail->addCC($cc);
        }
    }

    private function attachFiles($mail, array $files)
    {
        foreach ($files as $file) {
            $mail->addAttachment($file['path'], $file['name']);
        }
    }

    private function setEmailContent($mail, bool $isHtml, string $subject, string $body)
    {
        $mail->isHTML($isHtml);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = $body;
    }

    private function handleSendError($logger, string $email, string $subject, string $errorInfo)
    {
        $logger->logContent("Mail [to $email] could not be sent : $errorInfo", Logger::CRITICAL, 'mail.log');
    }

    private function handleException($logger, string $email, string $subject, string $errorInfo)
    {
        $logger->logContent("Message could not be sent. Mailer Error: $email=>$subject : $errorInfo", Logger::CRITICAL, 'mail.log');
    }
}

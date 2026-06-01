<?php
class SmtpMailer
{
    private string $host;
    private int $port;
    private string $encryption;
    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;
    private $socket = null;

    public function __construct()
    {
        $this->host       = getSetting('smtp_host', '');
        $this->port       = (int) getSetting('smtp_port', '465');
        $this->encryption = getSetting('smtp_encryption', 'ssl');
        $this->username   = getSetting('smtp_username', '');
        $this->password   = getSetting('smtp_password', '');
        $this->fromEmail  = getSetting('smtp_from_email', '');
        $this->fromName   = getSetting('smtp_from_name', 'NAS影视库');
    }

    public function isConfigured(): bool
    {
        return !empty($this->host) && !empty($this->username) && !empty($this->password) && !empty($this->fromEmail);
    }

    public function send(string $to, string $subject, string $htmlBody): bool
    {
        if (!$this->isConfigured()) {
            throw new Exception('SMTP 未配置');
        }

        try {
            $this->connect();
            $this->ehlo();
            $this->login();
            $this->sendCommand("MAIL FROM:<{$this->fromEmail}>");
            $this->sendCommand("RCPT TO:<{$to}>");
            $this->sendCommand("DATA");

            $boundary = md5(uniqid());
            $from = $this->encodeHeader($this->fromName) . " <{$this->fromEmail}>";
            $date = date('r');

            $headers = "From: {$from}\r\n";
            $headers .= "To: <{$to}>\r\n";
            $headers .= "Date: {$date}\r\n";
            $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
            $headers .= "Message-ID: <" . uniqid() . "@" . $this->host . ">\r\n";
            $headers .= "\r\n";

            $body = "--{$boundary}\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= "\r\n";
            $body .= chunk_split(base64_encode($htmlBody));
            $body .= "--{$boundary}--\r\n";
            $body .= ".\r\n";

            $this->sendRaw($headers . $body);
            $this->readResponse(250);

            $this->sendCommand("QUIT");
            $this->disconnect();

            return true;
        } catch (Exception $e) {
            $this->disconnect();
            throw $e;
        }
    }

    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'SMTP 未配置完整'];
        }

        try {
            $this->connect();
            $this->ehlo();
            $this->login();
            $this->sendCommand("QUIT");
            $this->disconnect();
            return ['success' => true];
        } catch (Exception $e) {
            $this->disconnect();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function connect(): void
    {
        $host = $this->host;
        $port = $this->port;

        if ($this->encryption === 'ssl') {
            $host = 'ssl://' . $host;
        }

        $this->socket = @fsockopen($host, $port, $errno, $errstr, 15);
        if (!$this->socket) {
            throw new Exception("连接 SMTP 服务器失败: $errstr ($errno)");
        }

        stream_set_timeout($this->socket, 15);
        $this->readResponse(220);

        if ($this->encryption === 'tls') {
            $this->sendCommand("STARTTLS");
            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new Exception('TLS 加密失败');
            }
        }
    }

    private function ehlo(): void
    {
        $this->sendCommand("EHLO " . $this->host);
    }

    private function login(): void
    {
        $this->sendCommand("AUTH LOGIN");
        $this->sendCommand(base64_encode($this->username));
        $this->sendCommand(base64_encode($this->password));
    }

    private function sendCommand(string $command): string
    {
        $this->sendRaw($command . "\r\n");
        return $this->readResponse();
    }

    private function sendRaw(string $data): void
    {
        if (!is_resource($this->socket)) {
            throw new Exception('SMTP 连接未建立');
        }
        fwrite($this->socket, $data);
    }

    private function readResponse(int $expectCode = 0): string
    {
        $response = '';
        while ($line = fgets($this->socket, 4096)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }

        if ($expectCode > 0) {
            $code = (int) substr($response, 0, 3);
            if ($code !== $expectCode) {
                throw new Exception("SMTP 响应错误: {$response}");
            }
        }

        return $response;
    }

    private function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    private function encodeHeader(string $str): string
    {
        if (preg_match('/[^\x20-\x7E]/', $str)) {
            return '=?UTF-8?B?' . base64_encode($str) . '?=';
        }
        return $str;
    }

    public static function sendPasswordReset(string $toEmail, string $username, string $token, string $siteUrl): bool
    {
        $mailer = new self();
        $resetUrl = rtrim($siteUrl, '/') . '/recovery.php?token=' . $token;
        $siteName = getSetting('site_name', 'NAS影视库');

        $html = "
        <div style='font-family:Arial,sans-serif;max-width:500px;margin:0 auto;padding:20px;'>
            <div style='text-align:center;margin-bottom:24px;'>
                <h2 style='color:#e50914;margin:0;'>{$siteName}</h2>
            </div>
            <div style='background:#f8f9fa;border-radius:8px;padding:24px;'>
                <h3 style='margin-top:0;'>密码重置</h3>
                <p>你好 <strong>{$username}</strong>，</p>
                <p>我们收到了你的密码重置请求。点击下方按钮重置密码：</p>
                <div style='text-align:center;margin:24px 0;'>
                    <a href='{$resetUrl}' style='display:inline-block;background:#e50914;color:#fff;padding:12px 32px;border-radius:6px;text-decoration:none;font-weight:bold;'>重置密码</a>
                </div>
                <p style='font-size:13px;color:#666;'>此链接 30 分钟内有效。如果不是你本人操作，请忽略此邮件。</p>
                <p style='font-size:13px;color:#666;'>如果按钮无法点击，请复制以下链接到浏览器：<br>
                <code style='word-break:break-all;font-size:12px;'>{$resetUrl}</code></p>
            </div>
            <p style='text-align:center;font-size:12px;color:#999;margin-top:16px;'>{$siteName} 系统邮件，请勿回复</p>
        </div>";

        return $mailer->send($toEmail, '密码重置 - ' . $siteName, $html);
    }
}

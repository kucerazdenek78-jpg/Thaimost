<?php
// Minimální SMTP klient (SSL na 465 nebo STARTTLS na 587), bez externích knihoven.

function smtp_send(array $c, string $to, string $subject, string $body): void
{
    $port = (int)($c['port'] ?? 465);
    $host = ($port === 465 ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $port;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $s = @stream_socket_client($host, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) throw new RuntimeException("SMTP spojení: $errstr ($errno)");
    stream_set_timeout($s, 20);

    $read = function () use ($s): string {
        $out = '';
        while (($line = fgets($s, 515)) !== false) { $out .= $line; if (isset($line[3]) && $line[3] === ' ') break; }
        return $out;
    };
    $cmd = function (string $c, array $ok) use ($s, $read): string {
        if ($c !== '') fwrite($s, $c . "\r\n");
        $r = $read();
        if (!in_array((int)substr($r, 0, 3), $ok, true)) throw new RuntimeException('SMTP: ' . trim($r));
        return $r;
    };

    $cmd('', [220]);
    $ehlo = 'EHLO masage.dvere.app';
    $cmd($ehlo, [250]);
    if ($port !== 465) {
        $cmd('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP: STARTTLS selhal');
        $cmd($ehlo, [250]);
    }
    if (!empty($c['user'])) {
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($c['user']), [334]);
        $cmd(base64_encode($c['pass']), [235]);
    }
    $from = $c['from'] ?: $c['user'];
    $cmd("MAIL FROM:<$from>", [250]);
    $cmd("RCPT TO:<$to>", [250, 251]);
    $cmd('DATA', [354]);

    $enc = fn(string $t) => '=?UTF-8?B?' . base64_encode($t) . '?=';
    $headers = [
        'From: ' . $enc($c['from_name'] ?? 'Thai Most') . " <$from>",
        "To: <$to>",
        'Subject: ' . $enc($subject),
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(8)) . '@masage.dvere.app>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ];
    $msg = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body));
    fwrite($s, $msg . "\r\n.\r\n");
    $cmd('', [250]);
    $cmd('QUIT', [221]);
    fclose($s);
}

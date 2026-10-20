<?php
// Minimální SMTP klient (SSL na 465 nebo STARTTLS na 587), bez externích knihoven.

/** $priloha = ['nazev' => 'smeny.ics', 'mime' => 'text/calendar; method=PUBLISH', 'obsah' => '...'] (volitelná) */
function smtp_send(array $c, string $to, string $subject, string $body, ?array $priloha = null): void
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

    $msg = smtp_sestav($c, $from, $to, $subject, $body, $priloha);
    fwrite($s, $msg . "\r\n.\r\n");
    $cmd('', [250]);
    $cmd('QUIT', [221]);
    fclose($s);
}

/** Sestaví celou MIME zprávu (text, případně s přílohou). */
function smtp_sestav(array $c, string $from, string $to, string $subject, string $body, ?array $priloha): string
{
    $enc = fn(string $t) => '=?UTF-8?B?' . base64_encode($t) . '?=';
    $hranice = '=_tm_' . bin2hex(random_bytes(8));
    $headers = [
        'From: ' . $enc($c['from_name'] ?? 'Thai Most') . " <$from>",
        "To: <$to>",
        'Subject: ' . $enc($subject),
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(8)) . '@masage.dvere.app>',
        'MIME-Version: 1.0',
    ];
    if ($priloha && $priloha['obsah'] !== '') {
        $headers[] = "Content-Type: multipart/mixed; boundary=\"$hranice\"";
        $msg = implode("\r\n", $headers) . "\r\n\r\n"
            . "--$hranice\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($body))
            . "--$hranice\r\nContent-Type: " . $priloha['mime'] . '; charset=UTF-8; name="' . $priloha['nazev'] . "\"\r\n"
            . 'Content-Disposition: attachment; filename="' . $priloha['nazev'] . "\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($priloha['obsah']))
            . "--$hranice--\r\n";
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $msg = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body));
    }
    return $msg;
}

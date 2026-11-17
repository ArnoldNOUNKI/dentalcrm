<?php

namespace App\Services\Telephony;

use RuntimeException;

/**
 * Client minimal Asterisk Manager Interface (TCP, port 5038).
 */
class AmiClient
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $secret,
        private readonly int $timeout = 8,
    ) {}

    public function login(): void
    {
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);

        if (! $this->socket) {
            throw new RuntimeException("AMI injoignable ({$this->host}:{$this->port}) : {$errstr}");
        }

        stream_set_timeout($this->socket, $this->timeout);
        fgets($this->socket); // bannière « Asterisk Call Manager/x.y »

        $response = $this->send(['Action' => 'Login', 'Username' => $this->username, 'Secret' => $this->secret, 'Events' => 'off']);

        if (stripos($response, 'Response: Success') === false) {
            $this->close();

            throw new RuntimeException('Authentification AMI refusée : '.trim($response));
        }
    }

    public function originate(array $params): string
    {
        return $this->send(['Action' => 'Originate'] + $params);
    }

    public function logoff(): void
    {
        if ($this->socket) {
            $this->send(['Action' => 'Logoff']);
            $this->close();
        }
    }

    /** Message AMI : lignes « Clé: valeur » terminées par une ligne vide. */
    public static function encode(array $fields): string
    {
        $out = '';
        foreach ($fields as $k => $v) {
            $out .= $k.': '.str_replace(["\r", "\n"], '', (string) $v)."\r\n";
        }

        return $out."\r\n";
    }

    private function send(array $fields): string
    {
        fwrite($this->socket, self::encode($fields));

        $response = '';
        while (($line = fgets($this->socket)) !== false) {
            if ($line === "\r\n" && $response !== '') {
                break;
            }
            $response .= $line;
        }

        return $response;
    }

    private function close(): void
    {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
    }
}

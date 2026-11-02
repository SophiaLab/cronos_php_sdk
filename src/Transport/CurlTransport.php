<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow\Transport;

use SophiaLabs\CronosFlow\Exception\TransportException;

/**
 * Transporte padrão, sobre a extensão cURL.
 *
 * Sem Guzzle de propósito: a dependência que o SDK arrasta vira conflito de
 * versão no projeto de quem instala, e conflito de versão é o motivo mais comum
 * de um SDK não entrar. A extensão cURL está em qualquer PHP que faça HTTP.
 *
 * Verificação de certificado fica ligada, sempre. Não há opção para desligar:
 * a chave de API viaja nesta conexão.
 */
final class CurlTransport implements Transport
{
    public function send(Request $request): Response
    {
        $handle = $this->handle($request);
        $headers = [];

        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, function ($_, string $line) use (&$headers): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[mb_strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($line);
        });

        $body = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($body === false) {
            throw new TransportException("falha de rede falando com a API: {$error}");
        }

        return new Response($status, (string) $body, $headers);
    }

    public function stream(Request $request, callable $onLine): void
    {
        $handle = $this->handle($request);
        $buffer = '';
        $parar = false;

        curl_setopt($handle, CURLOPT_WRITEFUNCTION, function ($_, string $chunk) use (&$buffer, &$parar, $onLine): int {
            if ($parar) {
                return 0;
            }

            $buffer .= $chunk;
            while (($quebra = strpos($buffer, "\n")) !== false) {
                $linha = substr($buffer, 0, $quebra);
                $buffer = substr($buffer, $quebra + 1);
                if ($onLine(rtrim($linha, "\r")) === false) {
                    $parar = true;

                    return 0;
                }
            }

            return strlen($chunk);
        });

        curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        // Encerrar o stream devolvendo 0 no callback é um "abort" para o cURL,
        // e não um erro nosso: foi o chamador que pediu para parar.
        if ($error !== '' && ! $parar) {
            throw new TransportException("falha no stream: {$error}");
        }

        if ($status >= 400) {
            throw new TransportException("o stream respondeu {$status}");
        }
    }

    /** @return \CurlHandle */
    private function handle(Request $request)
    {
        $handle = curl_init($request->url);
        if ($handle === false) {
            throw new TransportException('não consegui inicializar o cURL.');
        }

        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            // O stream de QR vive até o servidor fechar (120 segundos): um
            // timeout total aqui o mataria no meio.
            CURLOPT_TIMEOUT => $request->stream ? 0 : (int) ceil($request->timeout),
        ]);

        if ($request->body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $request->body);
        }

        return $handle;
    }
}

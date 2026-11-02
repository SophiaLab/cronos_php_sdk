<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow\Transport;

/**
 * Monta `multipart/form-data` na mão, a partir de bytes em memória.
 *
 * A alternativa seria `CURLFile`, que só aceita **caminho de arquivo**: quem
 * tem os bytes na memória (vindos de um banco, de um S3, de um upload) seria
 * obrigado a gravar um temporário em disco só para mandar — e temporário com
 * conteúdo de cliente é coisa que sobra no servidor quando o processo morre no
 * meio.
 */
final class Multipart
{
    /**
     * @param array<string, string>                                    $fields
     * @param array<string, array{name: string, content: string, type: string}> $files
     *
     * @return array{body: string, contentType: string}
     */
    public static function build(array $fields, array $files): array
    {
        $boundary = 'cronosflow' . bin2hex(random_bytes(16));
        $body = '';

        foreach ($files as $campo => $arquivo) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$campo}\"; filename=\"{$arquivo['name']}\"\r\n";
            $body .= "Content-Type: {$arquivo['type']}\r\n\r\n";
            $body .= $arquivo['content'] . "\r\n";
        }

        foreach ($fields as $campo => $valor) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$campo}\"\r\n\r\n";
            $body .= $valor . "\r\n";
        }

        $body .= "--{$boundary}--\r\n";

        return ['body' => $body, 'contentType' => "multipart/form-data; boundary={$boundary}"];
    }
}

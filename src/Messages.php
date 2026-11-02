<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow;

use Generator;
use SophiaLabs\CronosFlow\Exception\AccountBlocked;
use SophiaLabs\CronosFlow\Exception\CronosException;
use SophiaLabs\CronosFlow\Exception\DailyLimitReached;
use SophiaLabs\CronosFlow\Exception\SubscriptionRequired;
use SophiaLabs\CronosFlow\Exception\UnsupportedMedia;
use SophiaLabs\CronosFlow\Exception\ValidationException;
use SophiaLabs\CronosFlow\Model\SendResult;

/**
 * As rotas de envio.
 *
 * Todas passam pelo funil do cliente, então todas carregam o ritmo entre
 * mensagens do mesmo número, a chave de idempotência e a repetição controlada.
 */
final class Messages
{
    public function __construct(private readonly CronosClient $cronos)
    {
    }

    /** Envia uma mensagem de texto. */
    public function text(
        int|string $clientId,
        string $to,
        string $message,
        ?string $idempotencyKey = null,
    ): SendResult {
        $sender = Validation::clientId($clientId);

        return $this->cronos->send(
            '/api/message/text',
            $sender,
            json: [
                'client_id' => $sender,
                'to' => Validation::destination($to),
                'message' => Validation::text($message),
            ],
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * Cria e dispara uma enquete.
     *
     * O voto **não volta** por esta API: o WhatsApp entrega resposta de enquete
     * como evento próprio, e hoje nada lê esse evento. Quem envia vê o
     * resultado no aparelho.
     *
     * @param list<string> $options
     */
    public function poll(
        int|string $clientId,
        string $to,
        string $question,
        array $options,
        ?string $idempotencyKey = null,
    ): SendResult {
        $sender = Validation::clientId($clientId);
        [$cleanedQuestion, $cleanedOptions] = Validation::poll($question, $options);

        return $this->cronos->send(
            '/api/message/poll',
            $sender,
            json: [
                'client_id' => $sender,
                'to' => Validation::destination($to),
                'question' => $cleanedQuestion,
                'options' => $cleanedOptions,
            ],
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * Envia uma imagem (png, jpeg, webp ou gif), até 10MB.
     *
     * Vai em `multipart/form-data`, e não em base64: base64 infla o corpo em um
     * terço sem nenhuma vantagem.
     *
     * `$image` é caminho de arquivo ou os bytes da imagem.
     */
    public function image(
        int|string $clientId,
        string $to,
        string $image,
        ?string $caption = null,
        ?string $filename = null,
        ?string $idempotencyKey = null,
    ): SendResult {
        $sender = Validation::clientId($clientId);
        [$content, $name] = $this->read($image, Media::MAX_IMAGE_BYTES, 'imagem');
        $mime = Media::guessImageMime($content);
        if ($mime === null) {
            throw new UnsupportedMedia(
                'imagem precisa ser png, jpeg, webp ou gif. O que você mandou não é nenhum desses.',
                415,
            );
        }

        $fields = ['client_id_form' => $sender, 'to_form' => Validation::destination($to)];
        $legend = Validation::caption($caption);
        if ($legend !== null) {
            $fields['caption_form'] = $legend;
        }

        return $this->cronos->send(
            '/api/message/image/upload',
            $sender,
            fields: $fields,
            files: ['image' => ['name' => $filename ?? $name, 'content' => $content, 'type' => $mime]],
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * Envia áudio que toca como áudio, com o controle de play.
     *
     * Aceita `.ogg` com Opus e `.mp3`, que são os dois formatos que o WhatsApp
     * reproduz. Qualquer outro chegaria como anexo para baixar, e por isso é
     * recusado aqui, antes de subir o arquivo.
     */
    public function audio(
        int|string $clientId,
        string $to,
        string $audio,
        ?string $caption = null,
        ?string $idempotencyKey = null,
    ): SendResult {
        $sender = Validation::clientId($clientId);
        [$content] = $this->read($audio, Media::MAX_AUDIO_BYTES, 'áudio');
        $format = Media::detectPlayableAudio($content);
        if ($format === null) {
            throw new UnsupportedMedia(
                'áudio precisa ser .ogg com Opus ou .mp3 para tocar como áudio no WhatsApp. '
                . 'Converta antes de enviar: ' . Media::FFMPEG_AUDIO_HINT,
                415,
            );
        }

        return $this->cronos->send(
            '/api/message/audio',
            $sender,
            fields: $this->mediaFields($sender, $to, $caption),
            files: ['audio' => [
                'name' => 'audio' . $format['extension'],
                'content' => $content,
                'type' => $format['mime'],
            ]],
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * Envia vídeo que aparece como vídeo, com miniatura e play.
     *
     * Aceita `.mp4`. O `.mov` do iPhone não serve, mesmo renomeado: ele traz a
     * mesma caixa `ftyp` do MP4 e ainda assim o aparelho pode recusar.
     */
    public function video(
        int|string $clientId,
        string $to,
        string $video,
        ?string $caption = null,
        ?string $idempotencyKey = null,
    ): SendResult {
        $sender = Validation::clientId($clientId);
        [$content] = $this->read($video, Media::MAX_VIDEO_BYTES, 'vídeo');
        $format = Media::detectPlayableVideo($content);
        if ($format === null) {
            throw new UnsupportedMedia(
                'vídeo precisa ser .mp4 (H.264) para aparecer como vídeo no WhatsApp. '
                . 'Converta antes de enviar: ' . Media::FFMPEG_VIDEO_HINT,
                415,
            );
        }

        return $this->cronos->send(
            '/api/message/video',
            $sender,
            fields: $this->mediaFields($sender, $to, $caption),
            files: ['video' => [
                'name' => 'video' . $format['extension'],
                'content' => $content,
                'type' => $format['mime'],
            ]],
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * Envia um arquivo como anexo, de qualquer tipo, até 25MB.
     *
     * É o caminho para o que não reproduz: PDF, planilha, zip — e também para
     * mandar um `.wav` ou um `.mov` do jeito que eles estão.
     */
    public function document(
        int|string $clientId,
        string $to,
        string $document,
        ?string $caption = null,
        ?string $filename = null,
        ?string $idempotencyKey = null,
    ): SendResult {
        $sender = Validation::clientId($clientId);
        [$content, $name] = $this->read($document, Media::MAX_FILE_BYTES, 'arquivo');

        $query = [
            'client_id' => $sender,
            'to' => Validation::destination($to),
            'media_type' => 'DOCUMENT',
        ];
        $legend = Validation::caption($caption);
        if ($legend !== null) {
            $query['caption'] = $legend;
        }

        return $this->cronos->send(
            '/api/message/file',
            $sender,
            files: ['file' => [
                'name' => $filename ?? $name,
                'content' => $content,
                'type' => 'application/octet-stream',
            ]],
            query: $query,
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * Manda o mesmo texto para uma lista, no ritmo da política.
     *
     * Entrega `[destino, resultado]` conforme cada um sai, e **não interrompe a
     * lista** quando um destino falha: número inválido no meio de mil não pode
     * derrubar os outros novecentos e noventa e nove. O erro vem no lugar do
     * resultado, para quem chamou decidir o que fazer.
     *
     * Cota diária, assinatura vencida e conta bloqueada param o laço: insistir
     * nelas só queima o teto por minuto.
     *
     *     foreach ($cronos->messages()->sendMany('1', $lista, 'Promoção') as [$destino, $resultado]) {
     *         if ($resultado instanceof CronosException) {
     *             registrar($destino, $resultado);
     *         }
     *     }
     *
     * @param iterable<string> $destinations
     *
     * @return Generator<int, array{0: string, 1: SendResult|CronosException}>
     */
    public function sendMany(int|string $clientId, iterable $destinations, string $message): Generator
    {
        foreach ($destinations as $destination) {
            try {
                yield [$destination, $this->text($clientId, $destination, $message)];
            } catch (DailyLimitReached|SubscriptionRequired|AccountBlocked $fatal) {
                yield [$destination, $fatal];

                return;
            } catch (CronosException $error) {
                yield [$destination, $error];
            }
        }
    }

    /** @return array<string, string> */
    private function mediaFields(string $sender, string $to, ?string $caption): array
    {
        $fields = ['client_id' => $sender, 'to' => Validation::destination($to)];
        $legend = Validation::caption($caption);
        if ($legend !== null) {
            $fields['caption'] = $legend;
        }

        return $fields;
    }

    /**
     * Lê caminho de arquivo ou bytes, e confere o teto antes de qualquer rede.
     *
     * A distinção é por existência do arquivo, não por heurística de conteúdo:
     * quem passa bytes de imagem que por acaso formam um caminho válido é um
     * caso que não existe, e tentar adivinhar criaria um que existe.
     *
     * @return array{0: string, 1: string}
     */
    private function read(string $media, int $limit, string $label): array
    {
        if (is_file($media)) {
            $content = @file_get_contents($media);
            if ($content === false) {
                throw new ValidationException("não consegui ler {$media}.", 422);
            }
            $name = basename($media);
        } else {
            $content = $media;
            $name = $label . '.bin';
        }

        if ($content === '') {
            throw new ValidationException("{$label} vazio.", 422);
        }

        if (strlen($content) > $limit) {
            $megabytes = intdiv($limit, 1024 * 1024);
            $tamanho = number_format(strlen($content) / 1048576, 1, ',', '');

            throw new ValidationException("{$label} com {$tamanho}MB; o teto é {$megabytes}MB.", 413);
        }

        return [$content, $name];
    }
}

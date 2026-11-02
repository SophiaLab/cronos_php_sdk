<?php

declare(strict_types=1);

namespace SophiaLabs\CronosFlow;

/**
 * Reconhece áudio e vídeo pelos bytes, antes de subir o arquivo.
 *
 * A API faz essa mesma prova e responde 415. O SDK repete localmente por três
 * razões, nessa ordem:
 *
 * 1. **não gastar o que é caro**: um vídeo de 15MB sobe inteiro para voltar com
 *    415, e checar doze bytes custa nada;
 * 2. **dizer o que fazer**: a mensagem local traz o comando do ffmpeg;
 * 3. **não deixar o nome decidir**: o WhatsApp escolhe entre "toca como áudio"
 *    e "chega como anexo" pela extensão do arquivo, e um `.wav` renomeado para
 *    `.ogg` passaria por qualquer validação que olhe o nome — chegando mudo no
 *    celular.
 */
final class Media
{
    public const MAX_IMAGE_BYTES = 10 * 1024 * 1024;
    public const MAX_AUDIO_BYTES = 16 * 1024 * 1024;
    public const MAX_VIDEO_BYTES = 16 * 1024 * 1024;
    public const MAX_FILE_BYTES = 25 * 1024 * 1024;

    public const FFMPEG_AUDIO_HINT = 'ffmpeg -i entrada.wav -c:a libopus saida.ogg';
    public const FFMPEG_VIDEO_HINT = 'ffmpeg -i entrada.mov -c:v libx264 -c:a aac saida.mp4';

    /**
     * A caixa `ftyp` do MP4 carrega o "brand". O QuickTime (`qt  `, o `.mov`)
     * fica fora: ele também traz `ftyp`, mas não é MP4, e o aparelho pode
     * recusar o vídeo depois de a API já ter aceitado.
     */
    private const MP4_BRANDS = ['isom', 'iso2', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42', 'avc1', 'mmp4', 'dash'];

    private const SNIFF_WINDOW = 256;

    /** @return array{mime: string, extension: string}|null */
    public static function detectPlayableAudio(string $content): ?array
    {
        // OGG é contêiner: com Vorbis dentro, a mensagem de voz não toca.
        if (str_starts_with($content, 'OggS') && str_contains(substr($content, 0, self::SNIFF_WINDOW), 'OpusHead')) {
            return ['mime' => 'audio/ogg', 'extension' => '.ogg'];
        }

        // MP3 com tag ID3, ou começando direto no quadro: onze bits de
        // sincronismo em 1 (0xFF seguido de byte com os três bits altos ligados).
        if (str_starts_with($content, 'ID3')) {
            return ['mime' => 'audio/mpeg', 'extension' => '.mp3'];
        }
        if (strlen($content) >= 2 && ord($content[0]) === 0xFF && (ord($content[1]) & 0xE0) === 0xE0) {
            return ['mime' => 'audio/mpeg', 'extension' => '.mp3'];
        }

        return null;
    }

    /** @return array{mime: string, extension: string}|null */
    public static function detectPlayableVideo(string $content): ?array
    {
        if (strlen($content) >= 12 && substr($content, 4, 4) === 'ftyp'
            && in_array(substr($content, 8, 4), self::MP4_BRANDS, true)
        ) {
            return ['mime' => 'video/mp4', 'extension' => '.mp4'];
        }

        return null;
    }

    /** MIME da imagem pelos bytes. A API aceita png, jpeg, webp e gif. */
    public static function guessImageMime(string $content): ?string
    {
        return match (true) {
            str_starts_with($content, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($content, "\xff\xd8\xff") => 'image/jpeg',
            str_starts_with($content, 'RIFF') && substr($content, 8, 4) === 'WEBP' => 'image/webp',
            str_starts_with($content, 'GIF87a') || str_starts_with($content, 'GIF89a') => 'image/gif',
            default => null,
        };
    }
}

# CronosFlow SDK para PHP

SDK oficial da [API pública do CronosFlow](https://cronosflow.sophialabs.com.br/docs/api):
envio de WhatsApp a partir do seu servidor, com o ritmo que protege o número.

```bash
composer require sophialabs/cronos-flow-sdk
```

```php
use SophiaLabs\CronosFlow\CronosClient;

$cronos = new CronosClient(getenv('CRONOS_API_KEY'));

if (! $cronos->clients()->status('1')->loggedIn) {
    exit("número desconectado: leia o QR code na plataforma\n");
}

$cronos->messages()->text('1', '+5511999999999', 'Seu pedido saiu para entrega.');
```

## Por que um SDK, e não um `curl_exec`

A API tem 18 rotas e um `curl` resolve qualquer uma delas. O que não cabe num
`curl` é o que faz uma integração sobreviver ao segundo mês:

| | |
|---|---|
| **Ritmo entre envios** | 35 a 45 segundos entre mensagens **do mesmo número**, sorteado. É o mesmo padrão que a plataforma usa no disparo. Rajada pelo mesmo chip é o padrão que o WhatsApp usa para bloquear, e conta bloqueada não volta por reclamação. |
| **Idempotência** | Uma `Idempotency-Key` por mensagem, gerada antes da primeira tentativa e repetida em todas. Falha de rede deixa de virar mensagem duplicada. |
| **Repetição que não piora as coisas** | Espera crescente só em 429 e 5xx. Um 422 de validação não é repetido: repetir não conserta e ainda gasta o seu teto por minuto. |
| **Recusa antes da rede** | Destino fora do E.164, áudio que não tocaria, arquivo acima do teto, enquete com opção repetida: tudo isso falha na sua máquina, sem gastar rede nem cota do plano. |
| **Erro com nome** | O mesmo `403` da API é "chave inválida", "conta bloqueada" e "cota do dia acabou" — três reações diferentes. Aqui são três exceções diferentes. |

## O ritmo de envio

É a parte que mais importa, então vale a explicação completa.

O teto da API é 160 mensagens por minuto na rota de texto. Esse é o limite do
**servidor**, não um ritmo seguro para o **seu número**. O SDK nasce com a
mesma janela que a plataforma aplica no disparo:

```php
use SophiaLabs\CronosFlow\SendPolicy;

$cronos = new CronosClient(
    apiKey: getenv('CRONOS_API_KEY'),
    policy: new SendPolicy(minInterval: 35.0, maxInterval: 45.0),  // o padrão
);
```

Três detalhes do desenho:

- **a espera é por número.** Quem opera cinco chips manda pelos cinco sem um
  esperar pelo outro;
- **o intervalo é sorteado**, não fixo. Mensagem a cada 40 segundos cravados é
  tão artificial quanto rajada;
- **a primeira sai na hora.** A espera é *entre* envios.

Para uma lista, `sendMany` cuida do ritmo e não para no primeiro erro:

```php
use SophiaLabs\CronosFlow\Exception\CronosException;

foreach ($cronos->messages()->sendMany('1', $lista, 'Promoção de hoje') as [$destino, $resultado]) {
    if ($resultado instanceof CronosException) {
        registrarFalha($destino, $resultado);
    }
}
```

Dá para desligar o ritmo (`new SendPolicy(minInterval: 0.0, maxInterval: 0.0)`),
e só faz sentido quando **outra coisa** já controla a cadência — uma fila com
agendamento, por exemplo. Sem ritmo nenhum, o bloqueio do chip é questão de
tempo.

## O que dá para fazer

```php
// Números da conta
$cronos->clients()->list(q: 'comercial');
$cronos->clients()->create(name: 'Comercial', number: '+5511999990000');
$cronos->clients()->update(1, ['name' => 'Comercial (SP)']);
$cronos->clients()->delete(1);
$cronos->clients()->status(1);

// Conectar sem abrir a plataforma
$cronos->clients()->onQrEvent('1', function ($evento): bool {
    if ($evento->qr() !== null) {
        mostrarQr($evento->qr());
    }

    return ! $evento->isLoggedIn();   // devolver false fecha o stream
});

// Mensagens — aceitam caminho de arquivo ou os bytes
$cronos->messages()->text(1, '+5511999999999', 'Oi');
$cronos->messages()->poll(1, '+5511999999999', 'Qual horário?', ['Manhã', 'Tarde']);
$cronos->messages()->image(1, '+5511999999999', 'promo.png', caption: 'Promoção');
$cronos->messages()->audio(1, '+5511999999999', 'recado.ogg');
$cronos->messages()->video(1, '+5511999999999', 'clipe.mp4');
$cronos->messages()->document(1, '+5511999999999', 'boleto.pdf');
```

Áudio precisa ser `.ogg` (Opus) ou `.mp3`, e vídeo precisa ser `.mp4` — são os
formatos que o WhatsApp reproduz. O SDK confere isso **pelos bytes do arquivo**,
antes de subir, e a mensagem de erro traz o comando do ffmpeg. Qualquer outro
formato vai como anexo por `document()`.

## Erros

```php
use SophiaLabs\CronosFlow\Exception\{
    CronosException,         // a base de tudo
    AuthenticationException, // 401: chave não enviada
    SubscriptionRequired,    // 402: assinatura vencida ou não ativada
    ApiKeyRejected,          // 403: chave inválida, revogada ou vencida
    AccountBlocked,          // 403: conta bloqueada
    DailyLimitReached,       // 403: teto de envios por dia do plano
    NotFoundException,       // 404: client_id não é seu
    IdempotencyInFlight,     // 409: a mesma chave ainda está em voo
    ValidationException,     // 422: pedido inválido (da API ou do próprio SDK)
    UnsupportedMedia,        // 415: formato que não reproduziria
    PayloadTooLarge,         // 413: arquivo acima do teto
    RateLimited,             // 429: teto por minuto, já repetido pelo SDK
    SendFailed,              // 502: o WhatsApp não aceitou
};
```

`DailyLimitReached` é o único que passa sozinho com o tempo: a cota do plano
vira à meia-noite UTC, que são 21h no horário de Brasília.

## Segurança

- **a chave vale como senha da conta**: ela envia mensagem, cria e apaga
  cliente. Leia do ambiente do servidor; nunca escreva em repositório;
- o SDK **recusa `baseUrl` sem https** (`allowInsecure: true` libera, e serve só
  para desenvolvimento local);
- a chave **não aparece** em `var_dump` — só o prefixo;
- a verificação de certificado TLS fica ligada, sem opção de desligar;
- toda requisição tem timeout.

## Integração com o seu cliente HTTP

O SDK não depende de Guzzle nem de nada além da extensão cURL: dependência que o
SDK arrasta vira conflito de versão no seu projeto. Se você já tem um cliente
HTTP configurado (proxy corporativo, log, métrica), implemente
`SophiaLabs\CronosFlow\Transport\Transport` e passe no construtor.

## Compatibilidade

PHP 8.1 ou mais novo, com as extensões `curl` e `json`.

## Quem mantém

Team Sophia, da [SophiaLabs](https://sophialabs.com.br). Dúvida de uso vai em
[issue](https://github.com/SophiaLab/cronos_php_sdk/issues); falha de segurança
vai para contato@sophialabs.com.br, nunca em issue pública
([SECURITY.md](SECURITY.md)).

## Licença

Veja [LICENSE](LICENSE).

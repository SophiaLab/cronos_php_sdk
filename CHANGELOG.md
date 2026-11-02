# Changelog

Todos os principais lançamentos e mudanças deste projeto serão documentados neste arquivo.

## [0.1.0] - 2026-11-02

### Publicação

- **O pacote se chama `sophialabs/cronos-flow-sdk`**, igual ao `cronos-flow-sdk`
  do PyPI e do npm, e é assinado por Team Sophia.
- **O que o Composer baixa não traz teste nem CI.** Em PHP não existe build: o
  que está em `src/` é o que vai para o `vendor/` de quem instala. O que dá
  para controlar é o resto, e o `.gitattributes` mantém `tests/`, `.github/`,
  `phpunit.xml` e os templates fora do pacote. O CI falha se algum deles
  aparecer lá dentro, e também se aparecer algo parecido com chave ou token.
- **A publicação sai da tag.** O Packagist não recebe upload: ele lê o
  repositório e transforma cada `vX.Y.Z` numa versão instalável. Merge na
  `main` cria a tag da versão declarada em `CronosClient::VERSION`, e o webhook
  avisa o Packagist. Não existe credencial neste caminho.

### Adicionado

- **Primeira versão do SDK da API pública do CronosFlow.**
  `CronosClient` cobre as rotas que a chave de API abre: números da conta
  (listar, cadastrar, editar, apagar, status e stream de QR) e mensagens
  (texto, enquete, imagem, áudio, vídeo e documento).

- **Ritmo de envio ligado por padrão**, que é a razão de existir um SDK em vez
  de um `curl_exec`. De 35 a 45 segundos entre mensagens **do mesmo número**,
  sorteado dentro da faixa — o mesmo padrão que a plataforma aplica no disparo.
  O relógio é por número, para quem opera vários chips não perder vazão; o
  tempo já gasto entre um envio e outro é descontado; e a primeira mensagem sai
  na hora.

- **`Idempotency-Key` por mensagem**, gerada antes da primeira tentativa e
  repetida em todas as seguintes.

- **Repetição com espera crescente só em 429 e 5xx**, respeitando o
  `Retry-After` quando a API manda um. 4xx de validação não é repetido.

- **Recusa local do que a API recusaria**, antes de gastar rede e cota: destino
  fora do E.164, `client_id` não numérico, texto acima de 4096, legenda acima
  de 2000, enquete fora das regras do WhatsApp, arquivo acima do teto, e áudio
  ou vídeo que não reproduziriam. O formato de mídia é reconhecido **pelos
  bytes**, não pela extensão.

- **Uma exceção por motivo de recusa**, porque o mesmo `403` da API é "chave
  inválida", "conta bloqueada" e "cota do dia acabou".

- **`sendMany`**, que percorre uma lista no ritmo da política, entrega
  `[destino, resultado]` conforme sai e não para no primeiro erro. Cota diária,
  assinatura vencida e conta bloqueada param o laço.

- **`Transport` como interface**, com `CurlTransport` por padrão. Quem já tem um
  cliente HTTP no projeto pluga o dele; o teste usa um dublê e não toca a rede.

### Segurança

- `baseUrl` sem `https` é recusada (`allowInsecure: true` libera, para
  desenvolvimento local).
- A chave não aparece em `var_dump`: só o prefixo.
- Verificação de certificado TLS ligada, sem opção de desligar.
- Toda requisição tem timeout.

### Decisões que valem registro

- **Sem Guzzle**, e sem nenhuma dependência de produção: dependência que o SDK
  arrasta vira conflito de versão no projeto de quem instala, que é o motivo
  mais comum de um SDK não entrar.
- **Multipart montado na mão**, em vez de `CURLFile`: `CURLFile` só aceita
  caminho de arquivo, e quem tem os bytes na memória seria obrigado a gravar um
  temporário em disco — temporário com conteúdo de cliente é coisa que sobra no
  servidor quando o processo morre no meio.
- **`onQrEvent` é callback, não `Generator`**: o QR precisa aparecer na tela
  enquanto o stream está aberto, e um gerador alimentado por callback só
  entregaria os eventos depois de o stream fechar, quando o código já expirou.

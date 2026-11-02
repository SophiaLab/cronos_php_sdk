# Política de segurança

## Versões com suporte

A versão publicada mais recente no Packagist. Correção de segurança sai como
versão nova; não há backport para versões antigas.

## Reportando uma vulnerabilidade

**Não abra issue pública.** Issue é indexada pelo Google no mesmo dia, e quem
lê primeiro não é quem conserta.

Escreva para **contato@sophialabs.com.br** com o assunto
`[seguranca] cronos-flow-sdk php`, incluindo:

- o que dá para fazer com a falha, em uma frase;
- o passo a passo para reproduzir;
- versão do SDK e do PHP.

Respondemos o recebimento em até 3 dias úteis e mantemos você informado até a
correção sair. Pedimos que a falha não seja divulgada antes disso.

## O que é e o que não é falha deste SDK

Falha nossa:

- a chave de API aparecendo em `var_dump`, em log, em mensagem de erro ou
  em qualquer coisa que vá para disco;
- requisição saindo por HTTP sem `allowInsecure: true` explícito;
- verificação de certificado TLS desligada;
- conteúdo que não deveria estar no pacote publicado.

Não é falha nossa:

- chave de API escrita no código-fonte ou commitada no repositório de quem
  usa. A chave é credencial do servidor: leia do `getenv`;
- chave usada em código que roda no navegador. Ela vale como senha da conta, e
  qualquer visitante da página a lê — a API não libera `X-API-Key` em CORS
  justamente por isso.

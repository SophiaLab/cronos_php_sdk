# Contribuindo

Este é o SDK oficial da [API pública do CronosFlow](https://cronosflow.sophialabs.com.br/docs/api),
mantido pela **Team Sophia**, da **SophiaLabs**.

## O caminho do código até o Packagist

```
sua branch  →  develop  →  main  →  tag  →  Packagist
```

- **`develop`** é a branch de integração. Toda mudança entra por uma PR nela,
  nunca direto;
- **`main`** é o espelho do que está publicado. Merge na `main` cria a tag da
  versão que o `CronosClient::VERSION` declara;
- o **Packagist publica a partir da tag**. Ele não tem upload: lê o repositório
  e transforma cada tag `vX.Y.Z` numa versão instalável. Por isso não existe
  token nem credencial em lugar nenhum deste fluxo — o que existe é um webhook
  que avisa o Packagist a cada push.

O gatilho é a versão, não o merge: se a tag da versão declarada já existe, o
fluxo termina sem fazer nada. Publicar de verdade é subir o número do
`CronosClient::VERSION`, que é uma linha revisada na PR.

Versão publicada no Packagist **não se apaga**: ela é a tag, e apagar uma tag
que já foi instalada quebra o `composer.lock` de quem instalou.

## Rodando localmente

```bash
composer install

composer validate --strict                              # o composer.json
find src tests -name "*.php" -print0 | xargs -0 -n1 php -l   # sintaxe
vendor/bin/phpunit                                      # testes
```

São os mesmos três comandos que o CI roda, nas versões 8.1 a 8.4 do PHP.

## O que a PR precisa ter

- **teste do comportamento**, não da implementação. O que o SDK promete é o
  ritmo entre envios, a idempotência e a recusa local — e é isso que o teste
  prova;
- **nenhuma dependência de produção nova.** Hoje são zero, fora as extensões
  `curl` e `json`. O SDK não depende nem do Guzzle: dependência que ele arrasta
  vira conflito de versão no projeto de quem instala, e isso é motivo para não
  instalar;
- **compatibilidade com PHP 8.1**, que é o que o `composer.json` promete;
- **`CHANGELOG.md` atualizado** quando a mudança é visível para quem usa.

## O que não entra no pacote instalado

Em PHP não existe build: o que está em `src/` é literalmente o que vai parar
dentro do `vendor/` de quem instala. O que dá para controlar é o resto, e isso
está no `.gitattributes` com `export-ignore`: teste, CI, phpunit.xml e os
templates ficam de fora do pacote de distribuição.

O CI confere isso — há um passo que falha se teste, CI ou qualquer coisa
parecida com credencial aparecer no arquivo que o Composer baixa.

## Segurança

Falha de segurança **não** vai em issue pública. Veja [SECURITY.md](SECURITY.md).

Em issue, log ou teste, nunca cole uma chave de API de verdade (`cf_...`): ela
vale como senha da conta — envia mensagem, cria e apaga número. Use
`cf_00000000_exemplo`.

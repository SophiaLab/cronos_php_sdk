<!--
PR vai para a `develop`. A `main` recebe merge da `develop`, e merge na `main`
cria a tag da versao declarada em CronosClient::VERSION -- e e da tag que o
Packagist publica.
-->

## O que muda

Em uma frase, do ponto de vista de quem usa o SDK.

## Por que

O problema que isso resolve.

## Tipo

- [ ] Correção
- [ ] Nova funcionalidade
- [ ] Mudança de comportamento existente (quebra quem já usa?)
- [ ] Documentação
- [ ] CI / build

## Checklist

- [ ] `vendor/bin/phpunit` passa
- [ ] `composer validate --strict` passa
- [ ] `php -l` limpo em `src` e `tests`
- [ ] Compatível com PHP 8.1
- [ ] Nenhuma dependência de produção nova
- [ ] Nada novo entrando no pacote instalado (o `export-ignore` do `.gitattributes`)
- [ ] `CHANGELOG.md` atualizado, se a mudança é visível para quem usa
- [ ] Nenhuma chave, token ou arquivo de ambiente no diff

## Publicação

- [ ] Esta PR **não** mexe em `CronosClient::VERSION`: a `main` não vai criar tag
- [ ] Esta PR sobe a `VERSION` para `x.y.z` e o merge na `main` vai publicar

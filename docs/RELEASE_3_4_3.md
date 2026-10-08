# Caption IA 3.4.3 — registro do instalador oficial

## Onde encontrar os arquivos no GitHub

- Código do plugin: [vitoryvensreis/captionia](https://github.com/vitoryvensreis/captionia) (repositório **privado**)
- Registro da versão e checksum: [releases/3.4.3/README.md](https://github.com/vitoryvensreis/captionia/blob/main/releases/3.4.3/README.md)
- Instalação de cliente: `CaptionIA-3.4.3.zip`, com pasta `CaptionIA`, `INSTALAR_CAPTION_IA.bat`, `INSTALAR_CAPTION_IA.ps1` e `LEIA_PRIMEIRO.txt`
- Artefato CI que originou o pacote conferido: [run 37729644565](https://github.com/vitoryvensreis/captionia/actions/runs/37729644565)
- Backup GitHub de longa duração: *draft Release* `v3.4.3` (restrito à equipe do repositório), com o ZIP recompilado e `SHA256SUMS.txt`.

## Assinatura do pacote disponibilizado ao cliente

SHA-256 do ZIP de produção conferido:

```
47010c8d06f666209a77b3e6a2cd14e37417c707b00560e7e2d9e782ec5a6b1d  CaptionIA-3.4.3.zip
```

Pacotes recompilados pelo GitHub podem produzir bytes ZIP diferentes em razão dos metadados de arquivo. Use o `SHA256SUMS.txt` publicado **junto do respectivo arquivo** para comparar.

## Como publicar e distribuir

1. Entrar em `https://caption.escotilhaprodutora.com/admin` como administrador.
2. Publicar a versão `3.4.3`, enviando o **ZIP completo**.
3. Testar com um usuário real/licenciado em `/minha-conta`.
4. Confirmar que download requer sessão e que a licença aceita essa versão.
5. Testar instalação, login, GROQ Cloud e a geração de SFX no Premiere.
6. Para uma atualização futura, subir o código na `main` do plugin, validar o build e publicar novo ZIP pelo mesmo painel.

O código do servidor já controla downloads por sessão/licença. Este registro **não executa deploy na VPS** nem modifica chaves de produção.

## Observações

- A integração GROQ e a ativação de licença dependem da VPS, não das chaves do computador.
- O plugin ainda inclui templates/áudios e executor Adobe localmente, portanto não é cloud-only completo.
- Mídias SFX geradas no Premiere usam a pasta permanente do usuário `%LOCALAPPDATA%\CaptionIA\Media\SFX`.
- O repositório privado contém assets que exigem revisão de direitos de distribuição; **não publique o release GitHub publicamente**.
- A chave GROQ anteriormente compartilhada deve permanecer revogada/substituída.

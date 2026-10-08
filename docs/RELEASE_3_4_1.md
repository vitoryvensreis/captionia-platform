# Caption IA 3.4.1 — check-list de publicação e cliente

Este release integra transcrição GROQ e planos de legenda na API autenticada.
NÃO representa ainda uma arquitetura cloud-only completa: o executor JSXBIN
e a maior parte dos templates permanecem no instalador, por compatibilidade.

## Antes de colocar em produção

1. **Revogar a chave Groq que foi compartilhada na conversa.** Gerar outra no console Groq e salvar APENAS no `.env` da VPS. Não colar a chave em prints, tickets ou commits.
2. Testar login, expiração de licença, dispositivos revogados, bloqueio 403/401,
   corte de conexão durante upload e limite diário 429.
3. Testar transcrição na timeline, seleção de arquivo, prévia/editor de legendas,
   AutoEdit e AutoSFX 2 trilhas em cópia de projeto Windows.
4. Fazer backup NOVO do banco e dos arquivos **no dia da publicação**.
5. Conferir `php -m | grep -i zip` — o site exige ZipArchive para validar release;
   `php -m | grep -E 'curl|mbstring|pdo_mysql'`.
6. Confirmar que já existem `cloud_rate_windows` e `cloud_assets`, sem
   executar migração destrutiva em tabelas financeiras.
7. Configurar `upload_max_filesize=256M`, `post_max_size=280M` e
   limite correspondente do Nginx para subir o instalador via /admin.
   Esses limites amplos são para o UPLOAD do instalador, não para áudio
   (este continua limitado no endpoint a 24MB).
8. Validar publicação em ambiente de homologação antes de oferecer download público.

## Implantar PHP na VPS após merge dos PRs no main

O site em produção está na branch local `cloud-core-live`.
No terminal, com o usuário `root`, **não execute reset ou force pull**:

```bash
SITE=/home/escotilhaprodutora-caption/htdocs/caption.escotilhaprodutora.com
git -C "$SITE" status --short
sudo -u escotilhaprodutora-caption -H git -C "$SITE" fetch origin main
sudo -u escotilhaprodutora-caption -H git -C "$SITE" diff --stat HEAD origin/main
sudo -u escotilhaprodutora-caption -H git -C "$SITE" merge --ff-only origin/main
sudo -u escotilhaprodutora-caption -H bash -c 'cd /home/escotilhaprodutora-caption/htdocs/caption.escotilhaprodutora.com && composer install --no-dev --optimize-autoloader'
php -l "$SITE/app/Core/App.php"
php -l "$SITE/views/account.php"
curl -fsS https://caption.escotilhaprodutora.com/api/v1/health
```

Se `merge --ff-only` falhar, INTERROMPA e confira histórico;
não faça `reset --hard` na produção.

## Configuração mínima de .env (sem divulgar valores secretos)

```dotenv
CAPTIONIA_CLOUD_CORE_ENABLED=true
CAPTIONIA_CLOUD_CORE_MAJOR=3
CAPTIONIA_CLOUD_TRANSCRIPTION_ENABLED=true
CAPTIONIA_CLOUD_TRANSCRIPTION_DAILY_CALLS=12
CAPTIONIA_CLOUD_ASSETS_ENABLED=false
GROQ_API_KEY=CHAVE_NOVA_GUARDADA_SOMENTE_NA_VPS
```

Mantenha o domínio principal com HTTPS e certificados válidos. Todos os
endpoints Cloud exigem Bearer válido e licença/dispositivo autorizados.

## Como publicar o ZIP para o cliente

1. Validar o arquivo **CaptionIA-3.4.1.zip** criado pelo GitHub Actions
   da branch `main` do repositório `vitoryvensreis/captionia`.
2. Entrar com perfil administrador em `https://caption.escotilhaprodutora.com/admin`.
3. Na área **Releases/Atualizações**, enviar **o ZIP completo**, versão
   `3.4.1`, com notas de mudança, e publicar.
4. Entrar como **cliente com licença ativa** em
   `https://caption.escotilhaprodutora.com/minha-conta`.
5. A seção **Instalar ou atualizar o Caption IA** deverá mostrar o botão
   **Baixar Caption IA 3.4.1 para Windows**.
6. O site entrega o ZIP via sessão autenticada e checa novamente
   `versionAllowed()` antes do download.
7. O plugin instalado também verifica lançamentos novos pelo seu
   `js/updater.js`; faz download com Bearer, verifica SHA-256 do ZIP
   e aplica arquivos com rollback de falhas. Testar isso de ponta a ponta.

O cliente recebe somente `CaptionIA-3.4.1.zip`, contendo
`CaptionIA/`, `INSTALAR_CAPTION_IA.bat` e `LEIA_PRIMEIRO.txt`.
Não distribuir `CAPTIONIA-Cloud-Beta` nem patches de desenvolvimento.

## BLOQUEIOS para lançamento comercial amplo

- A extensão CEP ainda é distribuída **sem assinatura**, e o instalador
  precisa habilitar PlayerDebugMode. Planejar assinatura ZXP apropriada
  antes de uma distribuição pública profissional.
- Templates, bibliotecas de áudio e hostscript.jsxbin continuam no pacote
  local. A migração de assets para a nuvem não está concluída; proteção
  contra cópia é parcial e depende de licenciamento/asset rights.
- Falta auditoria de direitos para modelos, trilhas e efeitos antes de vendê-los.
- O AutoEdit no Premiere após o novo roteamento de transcrição ainda exige
  teste real na versão 3.4.1, mesmo que as etapas isoladas tenham sido
  homologadas na 3.4.0 Beta.
- Testes de renovação de licença, pagamentos, rate-limit em produção e
  atualização automática entre versões devem ser registrados antes de venda.

Em caso de falha, desabilitar `CAPTIONIA_CLOUD_TRANSCRIPTION_ENABLED`
e `CAPTIONIA_CLOUD_CORE_ENABLED` no .env, remover/desativar a release
pelo /admin e usar backup da instalação anterior. Não apagar tabelas financeiras.

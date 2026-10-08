# Caption IA Cloud Core — staging e rollback

## Estado da migração

- O site existente, pagamentos e licenças permanecem intactos.
- Motor remoto de planejamento de legendas e cortes: implementado na API.
- Catálogo/armazenamento privado de assets: implementado, **sem arquivos importados automaticamente**.
- Groq no backend, aplicação de planos na timeline, substituição do AutoEdit e remoção dos assets locais: **não implementados**.
- O Cloud Core não pode ser ativado em produção antes dos testes abaixo.

## Preparo obrigatório (staging)

1. Faça backup consistente do banco, do .env e de storage.
2. Publique esta branch em um ambiente de staging, nunca diretamente em main/produção.
3. Rode `composer install --no-dev --optimize-autoloader`.
4. Rode `php scripts/install.php` para criar as tabelas `cloud_rate_windows` e `cloud_assets` (CREATE TABLE IF NOT EXISTS).
5. Mantenha no .env:
   `CAPTIONIA_CLOUD_CORE_ENABLED=false` e `CAPTIONIA_CLOUD_ASSETS_ENABLED=false`.
6. Execute os testes:
   `php tests/cloud_caption_planner_test.php`;
   `php tests/cloud_edit_planner_test.php`;
   `php tests/cloud_asset_crypto_test.php`.
7. Com token de licença ativo, `GET /api/v1/core/config` deve retornar flags desligadas.
8. `POST /api/v1/core/captions/plan` deve retornar 503 enquanto o Core estiver desligado.

## Homologação do core

- Em staging, habilite `CAPTIONIA_CLOUD_CORE_ENABLED=true`.
- Confirme rejeição de contas sem licença, dispositivo revogado e token expirado.
- Teste frases em 9:16, 16:9 e 1:1; timecodes em 23.976, 25, 29.97 e 60 fps.
- `POST /api/v1/core/captions/plan` aceita `words:[{text,start,end}]` ou `text`.
- `POST /api/v1/core/edit/plan` aceita `duration`, `fps` e `silences:[{start,end}]`.
- Os planos são recomendações JSON e **não editam automaticamente** projetos Adobe.
- Acima de `CAPTIONIA_CLOUD_MAX_REQUESTS_PER_MINUTE` por usuário/feature, retornar 429.
- Monitore logs de acesso e erros, e teste o painel CEP em Windows e macOS antes de distribuir.

## Assets (somente material com direitos regularizados)

Extraia a cópia do CaptionIA 3.4.0 em uma pasta temporária privada do servidor. Após avaliar as licenças comerciais dos arquivos, execute:

```bash
php scripts/import-cloud-assets.php /caminho/para/CaptionIA --confirm-rights
```

O importador analisa somente `templates/`, `guias/` e `presets/`.
`TRILHAS VIRAIS/` fica de fora por precaução com direitos autorais.

- Assets são armazenados em `storage/cloud-assets`, **fora de `public/`**.
- Conteúdo criptografado em AES-256-GCM, derivação HKDF de APP_KEY e verificação SHA-256.
- Mantenha APP_KEY guardada: sua troca impossibilita descriptografar blobs antigos sem reimportação.
- Com `CAPTIONIA_CLOUD_ASSETS_ENABLED=true` em staging, valide catálogo e download autenticado.
- Nunca copie o acervo para o repositório público, pasta web pública ou release ZIP acessível sem licença.
- A proteção reduz cópias triviais; um asset carregado no computador do licenciado ainda poderá ser extraído.

## Rollback seguro

1. Desative imediatamente os dois flags no .env.
2. Reinicie/recarregue o PHP-FPM se seu ambiente mantiver configuração em cache.
3. O plugin atual 3.4.0 mantém os caminhos locais e continua como antes.
4. Se necessário, restaure o deploy e o banco previamente copiados; as novas tabelas podem permanecer sem impactar o legado.
5. Não apague `storage/cloud-assets` ou redefina APP_KEY durante rollback sem backup.

## Pendências antes do lançamento

- Integração real do Cloud Core aos controles/fluxos Adobe e validação na timeline.
- Transcrição Groq no servidor, política de quota financeira e consentimento de dados.
- Migração de MOGRT/FSM/FSA para carregamento autenticado e limpeza segura de temporários.
- Auditoria de licenças dos assets e plano de privacidade para mídia enviada.
- Testes end-to-end, cancelamento, undo, falha de conexão, restart e atualização automática.

**Não publicar a branch em produção como release final.**

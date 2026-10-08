> ATUALIZAÇÃO 2026-10-08: a versão candidata 3.4.1 usa Groq somente no backend para transcrição, com licença e dispositivo autorizados. O fluxo visual de homologação foi removido do cliente. Os assets e o executor JSXBIN continuam distribuídos localmente; o projeto NÃO é ainda um cloud-only integral. Requer validação Windows/Mac, auditoria comercial de mídia, rotacionar chave Groq vazada e assinatura/distribuição CEP antes de liberar publicamente.

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


## Nova transcrição Groq pela plataforma

A partir desta branch há um endpoint **opt-in**:
`POST /api/v1/core/transcription` (multipart/form-data).

Campos:
- `file`: áudio em mp3, wav, m4a, ogg, flac, mp4 ou webm, até 24 MB.
- `language`: vazio ou ISO-639-1 (por exemplo `pt`).
- `consent=yes`: confirmação explícita de envio ao provedor.

A API só executa com Bearer de usuário licenciado e dispositivo ativo. Usa a
`GROQ_API_KEY` guardada **somente no .env do servidor**, nunca no plugin.
O áudio chega ao servidor como arquivo temporário do PHP e é enviado ao
endpoint oficial de transcrição Groq; a aplicação não salva o upload.
A API devolve apenas `text`, `language`, `words` com timestamps,
`model` e `provider`. O plugin guarda a transcrição na sessão local.

Em **staging** e só após confirmar custos e condições de privacidade:
```dotenv
CAPTIONIA_CLOUD_CORE_ENABLED=true
CAPTIONIA_CLOUD_TRANSCRIPTION_ENABLED=true
CAPTIONIA_CLOUD_TRANSCRIPTION_DAILY_CALLS=12
GROQ_API_KEY=<definir somente no .env do servidor>
```

Não comitar o valor da chave, prints de tokens ou áudio de clientes.
Configure `upload_max_filesize=25M`, `post_max_size=27M`,
`max_execution_time=210`, `client_max_body_size 27m` no Nginx ou
limite equivalente no servidor HTTP.

**Limites:** há contagem por usuário/dia e por minuto via banco,
mas ainda não há cálculo de custo em moeda, controle por minutos de áudio,
chunking de arquivos grandes ou fila assíncrona.
Não habilite em escala sem adicionar essas proteções.

**Teste necessário:** áudio com poucas palavras, áudio PT/EN,
arquivo inválido, dispositivo revogado, 429 diário e cancelamento de rede.
Observe também a política de privacidade e o consentimento informado.

**Rollback:** desative `CAPTIONIA_CLOUD_TRANSCRIPTION_ENABLED` e mantenha a
transcrição legada no painel até a homologação. A chave Groq local antiga não
foi apagada para não causar perda de configuração dos clientes.

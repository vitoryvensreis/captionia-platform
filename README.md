# Caption IA Platform

Plataforma web do Caption IA: página de venda, conta do cliente, Checkout PagBank hospedado com PIX, licenças, dispositivos, painel administrativo e API para o plugin Premiere.

## Planos

- **Mensal — R$ 30,00:** libera 30 dias. Como o checkout é exclusivamente PIX, a renovação é feita por um novo PIX; pagamentos aprovados acrescentam 30 dias.
- **Vitalício — R$ 70,00:** licença permanente para a geração principal adquirida, sem futuras gerações principais.
- Ambos permitem **2 dispositivos**.

## URLs amigáveis

- `/`
- `/entrar`
- `/criar-conta`
- `/comprar/mensal`
- `/comprar/vitalicio`
- `/pagamento/{referencia}`
- `/minha-conta`
- `/admin`
- `/webhooks/pagbank`
- `/api/v1/auth/login`
- `/api/v1/auth/refresh`
- `/api/v1/license/check`

O VHost deve apontar o document root para `public/` e usar front controller para `index.php`.

## Requisitos

- PHP 8.2+ (testado para PHP 8.4)
- Composer
- MySQL 8+
- extensões: curl, json, mbstring, openssl, pdo_mysql

## Instalação no CloudPanel

```bash
cd /home/escotilhaprodutora-caption/htdocs/caption.escotilhaprodutora.com

git fetch origin main
git reset --hard origin/main

composer install --no-dev --optimize-autoloader

cp .env.example .env
nano .env
```

Gere uma APP_KEY:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Cole o valor em `APP_KEY`.

Depois aplique o banco:

```bash
php scripts/install.php
```

### Administrador

Defina seu e-mail em `ADMIN_EMAIL` antes de criar sua conta. Alternativamente, depois de criar a conta:

```bash
php scripts/make-admin.php seu@email.com
```

## PagBank / PagSeguro — Checkout hospedado

No `.env`:

```dotenv
PAGBANK_ENV=sandbox
PAGBANK_TOKEN=SEU_TOKEN
PAGBANK_WEBHOOK_VERIFY=true
```

Em produção troque `PAGBANK_ENV=production`.

O sistema envia `notification_urls` apontando para:

`https://caption.escotilhaprodutora.com/webhooks/pagbank`

Por padrão o sistema usa `PAGBANK_CHECKOUT_MODE=hosted`, cria um checkout em `/checkouts`, habilita `payment_methods=[PIX]` e redireciona o cliente para o link `PAY` devolvido pelo PagBank. O modo transparente antigo continua disponível apenas como fallback técnico usando `PAGBANK_CHECKOUT_MODE=transparent`.

Crie uma chave pública de validação de webhook uma vez por ambiente:

```bash
php scripts/pagbank-create-webhook-key.php
```

A licença só é liberada quando um pagamento PIX com valor correto chega como `PAID` por sincronização com o PagBank. O webhook é validado pela assinatura `x-payload-signature` usando ECDSA/SHA-256.

## Banco

O projeto **não armazena senha de banco no Git**. Preencha `DB_PASSWORD` apenas no arquivo `.env` do servidor.

O instalador usa os arquivos:

- `database/schema.sql`
- `database/migrations/20261007_upgrade.sql`

## API do plugin

### Login

`POST /api/v1/auth/login`

JSON:

```json
{
  "email": "cliente@email.com",
  "password": "senha",
  "device_uuid": "uuid-persistente-do-dispositivo",
  "device_name": "PC Editor",
  "os": "Windows 11"
}
```

O retorno contém access token curto e refresh token longo. O plugin deve guardar o refresh token com DPAPI no Windows e Keychain no macOS.

### Renovar sessão

`POST /api/v1/auth/refresh`

### Verificar licença

`GET /api/v1/license/check?version=3.2.3`

Header: `Authorization: Bearer ACCESS_TOKEN`

O vitalício é limitado pela geração principal comprada; o mensal ativo aceita a versão atual.

## Segurança

- senha com Argon2id quando disponível;
- prepared statements PDO;
- CSRF no site;
- cookies HttpOnly/Secure/SameSite;
- token PagBank e senhas apenas no `.env`;
- webhook PagBank autenticado;
- processamento de pagamento idempotente;
- limite de dispositivos aplicado pela API.


## Validação do painel Admin

O deploy executa `php scripts/admin-selftest.php` após as migrations. O script valida tabelas/colunas e executa as mesmas consultas principais usadas pelo dashboard, sem alterar dados.

O endpoint `/admin` exige usuário autenticado com `role=admin`. Sem sessão, deve redirecionar para `/entrar`.


## Pix direto / BR Code

O Admin pode escolher em `/admin` entre:

- `PagBank`: checkout hospedado e confirmação automática por webhook.
- `Pix direto`: BR Code estático conforme o padrão Pix/BR Code, com valor e TXID do pedido.

O Pix direto usa:

```dotenv
PAYMENT_GATEWAY=bacen_pix
BACEN_PIX_KEY=
BACEN_PIX_NAME=CAPTION IA
BACEN_PIX_CITY=FORTALEZA
BACEN_PIX_ORDER_EXPIRATION_MINUTES=1440
```

Por privacidade, a chave Pix não deve ser versionada no Git. Configure-a pelo painel Admin ou no `.env`.

No modo Pix direto, o site não possui acesso ao extrato bancário. Portanto, a confirmação do recebimento é manual no painel Admin. Ao confirmar, o sistema registra a transação e libera a licença idempotentemente.

## API v1 para o plugin

Endpoints públicos:

- `GET /api/v1/health`
- `GET /api/v1/config`
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/refresh`
- `POST /api/v1/auth/logout`

Endpoints com Bearer token:

- `GET /api/v1/me`
- `GET|POST /api/v1/license/check`
- `GET /api/v1/devices`
- `DELETE /api/v1/devices/{device_uuid}`
- `GET /api/v1/releases/latest`

A API usa CORS sem cookies, access token curto e refresh token rotativo. O plugin deve persistir `device_uuid` e `refresh_token` com armazenamento seguro do sistema operacional.

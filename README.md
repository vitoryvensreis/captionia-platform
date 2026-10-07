# Caption IA Platform

Site comercial, API de autenticação/licenças e painel administrativo do Caption IA.

## Planos iniciais

- Mensal: R$ 30,00/mês, 2 dispositivos, atualizações incluídas.
- Vitalício: R$ 70,00, 2 dispositivos, sem futuras versões principais.

## Requisitos

- PHP 8.2+
- Composer
- MySQL 8+
- Nginx/CloudPanel

## Produção

Document root:

`/home/escotilhaprodutora-caption/htdocs/caption.escotilhaprodutora.com/public`

Instalação:

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
```

Depois configure o banco no `.env`.

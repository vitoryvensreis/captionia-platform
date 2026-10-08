# Caption IA — Afiliados + Financeiro (2026-10-08)

## Implementado

**Cliente:** `/afiliados` mostra o link individual, comissões por pedidos pagos,
saldo em retenção, valor disponível, retiradas anteriores e solicitação de saque
por Pix. Na área `/minha-conta`, clientes elegíveis veem um acesso ao painel.

**Admin:** `/admin/afiliados` oferece:
- Ativar/desativar o programa (DESLIGADO por padrão);
- Taxa global de comissão de 1% até 80%, capturada quando cada pedido é criado;
- Saque mínimo de R$ 10 a R$ 100.000;
- Prazo de retenção de 0 a 90 dias (14 dias inicialmente);
- Ativação automática para quem tem compra paga e ativação manual por usuário;
- Pedidos de saque: marcar pago SOMENTE após Pix realmente feito ou recusar
  com motivo, retornando ao saldo liberado.

**Admin Financeiro:** `/admin/financeiro` resume pedidos, receita confirmada,
vendas por plano e dia, valores aguardando, comissões e retiradas. Oferece
CSV de até 3.000 pedidos e faixas de 7/30/90/365 dias.

**Comportamento:** eventos de visitas, visualização de planos, clique para compra,
cadastro, checkout, pagamento e faixas de rolagem 25/50/75/100% são registrados
somente mediante aceitação do aviso de métricas. Nenhum texto de formulário,
CPF, chave Pix, endereço IP nem URL livre é gravado nesses eventos. Vendas e
receitas usam pedidos persistidos no banco (independentemente do consentimento).

## Regras financeiras fundamentais

1. O clique de indicação em `/r/CODIGO` cria cookie de atribuição válido por
   30 dias. O pedido captura a indicação **uma única vez**, ao ser criado.
2. Autoindicação não gera comissão; perfis desativados não capturam pedidos novos.
3. O valor de comissão é calculado em centavos, segundo percentual congelado
   no pedido. Apenas pedidos `paid` E `license_applied=1` podem gerar crédito.
4. Um índice UNIQUE em `affiliate_commissions.order_id` impede crédito duplo.
5. Pedidos ainda não pagos não aparecem no saldo.
6. Saldo disponível = comissões maduras − saques solicitados ou pagos.
   Duas solicitações simultâneas são serializadas com `FOR UPDATE`.
7. Saques NÃO transferem dinheiro automaticamente. O Admin deve conferir a
   transferência Pix antes de marcar `Pago`.
8. A chave de recebimento é criptografada com AES-256-GCM na aplicação;
   nunca deve entrar em logs ou screenshots compartilhados.
9. A retenção de 14 dias ajuda com estornos, mas estornos/chargebacks exigem
   conferência operacional: NÃO marque pagamentos disputados como liberados.

## Ativação na VPS — obrigatória antes de habilitar

1. O deploy automático executa backup do MySQL e `php scripts/install.php`,
   criando as tabelas novas sem excluir os dados existentes.
2. No SSH da VPS, gere uma chave estável com:
   `openssl rand -hex 32`
3. Copie o valor gerado **somente** ao `.env` do site como
   `AFFILIATE_PAYOUT_KEY=<64 caracteres hexadecimais>`. Não publique a chave.
   **Nunca rotacione sem migrar os Pix criptografados**, pois ficariam ilegíveis.
4. Entre em `/admin/afiliados` e configure percentual, saque mínimo,
   retenção e público elegível; habilite o programa por último.
5. Teste com DUAS contas e um pagamento de teste: link compartilhado,
   criação do pedido, pagamento real/confirmado, comissão, maturação,
   solicitação de saque e decisão do admin. NÃO use fundos reais para teste
   sem um procedimento reversível.
6. Para verificar o painel de acessos, abra a homepage numa aba anônima,
   escolha *Aceitar métricas*, role a página e clique no plano; confira
   `/admin/financeiro`.
7. Verifique permissões administrativas/CSRF e mantenha revisões periódicas
   de comissões e transferências.

## Testes e auditoria

- `tests/affiliate_finance_integration_test.php`: teste em MySQL isolado,
  contendo idempotência de comissões, autoindicação, saldo reservado,
  chave Pix cifrada e métricas consentidas.
- Workflow `Validate Caption IA Platform` executa migrations DUAS vezes,
  confirmando que são idempotentes, antes da homologação do deploy.
- `tests/seo_metadata_test.php` preserva SEO e Open Graph existentes.
- CSV inclui proteção contra injeção de fórmulas em nomes/e-mails.

## Limitações assumidas

- Saques ainda são **manuais**, não possuem integração de pagamento/split.
- Atos de estorno/chargeback e conciliação bancária automática não fazem
  parte deste módulo; devem ser verificados financeiramente antes do saque.
- Eventual taxa do gateway, tributos e custos operacionais não são abatidos
  das comissões; o percentual é aplicado ao valor bruto da compra.
- Visitas não consentidas não entram nas estatísticas de navegação.
- Métricas de eventos não representam gravação de sessão, mapas de calor
  ou o conteúdo individual de campos digitados.
- Há retenção técnica dos eventos sem expurgo automático nesta primeira versão.
  Defina uma política de retenção antes de ampliar o rastreamento.

## Rollback

Desabilite o programa de afiliados no painel Admin. Isso bloqueia novas
atribuições e pedidos de saque, mas não apaga dívidas ou o histórico.
Nunca remova tabelas de comissões/saques com transações pendentes.

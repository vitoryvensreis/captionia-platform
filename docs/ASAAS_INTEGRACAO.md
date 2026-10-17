# Caption IA + Asaas: publicação controlada

## Estado

Integração em branch de homologação. **Não ativar produção sem um pagamento de teste,
confirmação do recebimento no Asaas e verificação da licença na área do cliente.**
Pix direto e PagBank permanecem disponíveis. Os pedidos antigos não são migrados.

## Configuração segura

1. Crie uma conta **Asaas Sandbox** e sua própria API Key de sandbox. Nunca use a
   chave de produção para testes.
2. No \`.env\` da VPS, fora do Git, configure:

   \`\`\`dotenv
   ASAAS_ENV=sandbox
   ASAAS_API_KEY=CHAVE_PRIVADA_ASAAS_SANDBOX
   ASAAS_WEBHOOK_TOKEN=TOKEN_ALEATORIO_DISTINTO_COM_MAIS_DE_32_CARACTERES
   \`\`\`

   Gere o token com \`openssl rand -hex 32\`. O token de webhook NÃO é a chave API.
3. Configure o webhook no painel Asaas:
   - URL pública: \`https://caption.escotilhaprodutora.com/webhooks/asaas\`
   - Método: \`POST\`; HTTPS; tipo \`SEQUENTIALLY\`
   - \`authToken\`: exatamente o valor de \`ASAAS_WEBHOOK_TOKEN\`
   - Eventos: \`PAYMENT_CREATED\`, \`PAYMENT_CONFIRMED\`,
     \`PAYMENT_RECEIVED\`, \`PAYMENT_REFUNDED\`,
     \`PAYMENT_CHARGEBACK_REQUESTED\`, \`PAYMENT_DELETED\`.
4. Depois de backup e deploy validado, rode \`php scripts/install.php\`.
   A migração adiciona os identificadores Asaas a usuários e pedidos, sem remover
   dados do PagBank.
5. Em **Admin > Forma de pagamento**, selecione **Asaas**. O painel não permite
   selecionar Asaas se a API Key e o token de webhook não estiverem configurados.

## Comportamento

- **Vitalício:** cobrança avulsa no Asaas com checkout hospedado.
- **Mensal/anual:** assinatura Asaas, cobrança inicial e novas cobranças
  conforme o ciclo. A plataforma vincula cada pagamento a um pedido próprio.
- O cliente paga na página hospedada do Asaas. Nenhum dado do cartão é recebido
  pelo Caption IA.
- Quando o Asaas enviar um webhook autenticado, o servidor consulta a cobrança
  diretamente na API; compara o ID, o cliente, a assinatura e o valor. Somente
  pagamentos efetivamente recebidos ou cartões confirmados liberam a licença.
  Confirmações de **Pix e boleto** aguardam \`RECEIVED\` para evitar bloqueio
  cautelar temporário.
- Eventos com o mesmo ID são deduplicados. Cada pedido só estende a licença uma
  vez, mesmo após reenvio do webhook.
- O status também pode ser consultado no site (com limite de requisições à API)
  caso um webhook demore a chegar.
- Cartões usados na página de fatura não são necessariamente tokenizados para
  débito automático futuro. A assinatura cria cobranças recorrentes; para
  cobrança automática no cartão sem nova ação do cliente, homologar o
  **checkout recorrente/tokenização do Asaas** como etapa adicional.

## Homologação

1. Rodar \`php tests/asaas_gateway_test.php\` e os testes existentes.
2. No Sandbox, criar um usuário de teste e comprar um plano; conferir
   \`checkout_orders.asaas_payment_id\` e \`provider_mode\`.
3. Conferir o link de pagamento (domínio \`asaas.com\`) e a transição da
   cobrança de pendente para recebida. **Criar cobrança não equivale a pagar.**
4. Conferir \`webhook_events\`, \`payment_transactions\`, \`checkout_orders.status\`
   e \`licenses.expires_at\`. Enviar o mesmo webhook duas vezes e confirmar
   que não estende a licença novamente.
5. Conferir uma renovação mensal/anual e um pagamento de outro valor ou
   outro cliente (que deve ser rejeitado).
6. Conferir reembolso e chargeback. Por segurança, neste primeiro bloco eles
   geram registro de revisão administrativa, **sem revogação automática**:
   calcular reversão de acesso exige conferir outras compras válidas do cliente.
7. Em produção, usar conta, chave, webhook e token **de produção**, distintos
   dos de sandbox; repetir testes de pagamento real de pequeno valor.
8. Se Cloudflare/WAF impedir a entrega, configurar exceção restrita para
   \`POST /webhooks/asaas\` e confirmar HTTP 200 em Integrações > Logs de Webhooks
   no Asaas. A URL não pode fazer redirect 301/302.

## Recuperação / rollback

- Para retornar ao pagamento anterior, em Admin selecione **PagBank** ou
  **Pix direto**, sem apagar pedidos nem webhooks já recebidos.
- Não remova a chave/token Asaas enquanto houver assinaturas ativas no provedor.
- Ao desabilitar assinaturas no site, cancele as cobranças recorrentes também
  no Asaas: trocar o gateway não cancela assinaturas existentes.

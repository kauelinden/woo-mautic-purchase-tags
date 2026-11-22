# Tags de compras do WooCommerce para o Mautic

Associe produtos a uma ou mais tags do Mautic diretamente na edição do produto. Quando um pedido pago contém pelo menos um produto mapeado, o plugin acrescenta ao contato as tags desses produtos, a da loja e a do ano do pagamento. Um contato pode acumular compras em várias lojas. A versão 0.1 usa a API REST com autenticação básica sobre HTTPS.

## Instalação e conexão

1. Instale e ative o WooCommerce. Use PHP 7.4 ou superior e WordPress 5.3 ou superior. Baixe o ZIP pelo botão **Code → Download ZIP** do GitHub e envie o arquivo em **Plugins → Adicionar plugin → Enviar plugin**. Ative o plugin.
2. No Mautic, habilite a API e a autenticação HTTP Basic. Use um usuário dedicado com permissão para ler, criar e editar os contatos das lojas. Configure o e-mail como identificador único no Mautic, sobretudo se várias lojas compartilharem a instância.
3. Configure as variáveis de ambiente no servidor. No wp-config.php da instalação, antes da linha final de configuração, associe os nomes abaixo às variáveis fornecidas pela hospedagem. WCMT_URL é a URL HTTPS da instalação, sem o sufixo /api. Cada loja pode usar credenciais próprias.

```php
define('WCMT_URL', getenv('WCMT_URL') ?: '');
define('WCMT_USER', getenv('WCMT_USER') ?: '');
define('WCMT_PASSWORD', getenv('WCMT_PASSWORD') ?: '');
```

4. Em **Configurações → Tags de compras**, preencha a tag da loja e o padrão anual. Exemplo: “Loja - Exemplo” e “Compradores {ano}”. Deixe um desses campos vazio para omitir a tag correspondente.
5. No editor clássico de produtos, abra **Dados do produto → Geral → Tags no Mautic**. Digite os nomes separados por vírgula e atualize o produto. Exemplo: “Produto - Curso de Fotografia, Interesse - Fotografia”. As variações usam o mapeamento do produto pai.

## Exemplo para testar

Em uma loja de homologação, configure um produto com “Produto - Fotografia” e compre-o com um contato de teste. Confirmado o pagamento em 2026, confira no Mautic as três tags “Produto - Fotografia”, “Loja - Exemplo” e “Compradores 2026”. Repita com um contato que já tenha outra tag e verifique se ela foi preservada. Um pedido que só contém produtos sem mapeamento fica fora da sincronização.

O ano vem da data paga do pedido, no fuso da loja. Uma renovação que gere um novo pedido pago com produto mapeado também gera as tags do respectivo ano. As tags representam o histórico de compras. Reembolsos posteriores não removem esse histórico. Assinaturas ativas e consentimento de marketing devem ser tratados separadamente.

## Operação

O WooCommerce agenda o trabalho com Action Scheduler. Mantenha o executor de tarefas da loja funcionando e consulte **WooCommerce → Status → Ações agendadas**, grupo wc-mautic-purchase-tags. A chamada externa ocorre em segundo plano. O plugin guarda a lista de tags calculada no pedido e registra a confirmação do Mautic em uma nota interna.

Falhas de transporte, HTTP 408, 429 e 5xx têm até quatro novas tentativas (1, 5, 30 e 120 minutos). Em falha definitiva, confira a nota do pedido, corrija a conexão ou o contato e use a ação **Mautic: tentar sincronizar tags pendentes**. Pedidos já confirmados são ignorados. Se houver dois contatos com o mesmo e-mail, resolva a duplicidade no Mautic antes de tentar novamente.

O plugin envia o e-mail de cobrança para identificar o contato e os nomes das tags para registrar a compra. Mantenha nomes de tags adequados à finalidade da loja e acesso restrito aos sistemas. Para migrar de uma automação existente, valide um produto em homologação antes de desativar o fluxo anterior.

## Desenvolvimento

Rode `php -l woo-mautic-purchase-tags.php` e `php tests/test.php`. A suíte usa pedidos e respostas HTTP fictícios. Valide separadamente o checkout, o executor de filas e a API da versão do Mautic utilizada em sua instalação.

Contribuições por issues e pull requests são bem-vindas. Use dados fictícios nos relatos.

## Licença

GPL-2.0-or-later. Consulte LICENSE.

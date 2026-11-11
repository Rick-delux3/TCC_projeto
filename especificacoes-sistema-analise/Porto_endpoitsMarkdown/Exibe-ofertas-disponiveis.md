# Exibe as ofertas disponiveis para um orçamento.

- **Method:** `GET`
- **Path:** `/orcamentos/{origem}/{orcamento}/{susep}/ofertas`
- **Tags:** Orçamento e Emissão

Quando sucesso, retorna status 200 (HTTP Status Code) com uma lista de ofertas disponíves para o orçamento informado, contendo as opções de quantidade de parcelas e formas de pagamento.

## Effective servers

- `https://portoapicloud-hml.portoseguro.com.br/fianca-locaticia/emissao/aluguel-essencial/v1`

## Authentication

- **Bearer**: API key in header `Authorization`

## Path parameters

- **`origem` (required)**: `string`, minLength: `1`, maxLength: `15`, pattern: `([0-9])`

  Origem da proposta, composta por 2 caracteres, sendo 22 para emissão e 40 para renovação.  *Campo obrigatório*.
- **`orcamento` (required)**: `string`, minLength: `1`, maxLength: `15`, pattern: `([0-9])`

  Número do orçamento interno da Porto Seguro, composto por 15 caracteres numéricos, preenchido com zeros à esquerda sem espaços, máscaras ou caracteres especiais. *Campo obrigatório*.
- **`susep` (required)**: `string`, minLength: `6`, maxLength: `6`, pattern: `([A-Z0-9])`

  Código do corretor de seguros responsável pelo orçamento. Este é o código do corretor de seguros no cadastro interno da Porto Seguro, que é composto por 6 letras / números e termina com um F ou J.
  *Campo obrigatório*.

## Responses

### 200 Consulta realizada com sucesso.

**Content type:** `application/json`

- **`ofertas`**: `array`

  **Items:**

  schema: `Ofertas`
  - **`acordo`**: `object`, schema: `acordo`
    - **`percentualMaximo`**: `integer`, format: `int32`, minimum: `0`, maximum: `100`, maxLength: `3`, pattern: `([0-9])`
    - **`percentualMinimo`**: `integer`, format: `int32`, minimum: `0`, maximum: `100`, maxLength: `3`, pattern: `([0-9])`
  - **`agravos`**: `array`, maxItems: `999`

    **Items:**

    schema: `agravos`
    - **`descricao`**: `string`, maxLength: `50`, pattern: `([A-Za-z])`

      descrição do agravo
    - **`valor`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, maxLength: `7`, pattern: `([0-9])`

      valor do agravo
  - **`beneficios`**: `array`, maxItems: `999`

    **Items:**

    schema: `beneficios`
    - **`codigo`**: `string`, maxLength: `3`, pattern: `([0-9])`

      Código identificador do benefício concedido pela Porto Seguro.
    - **`descricao`**: `string`, maxLength: `50`, pattern: `([A-Za-z\,\.\!])`

      Descrição do benefício concedido pela Porto Seguro.
    - **`titulo`**: `string`, maxLength: `50`, pattern: `([A-Za-z0-9\,\.\!])`

      Título do benefício concedido pela Porto Seguro
  - **`calculo`**: `object`, schema: `Calculo`

    Detalhes dos valores brutos calculados no orçamento de seguro.
    - **`adicionalFracionamento`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

      Valor dos juros calculado no orçamento (adicional de fracionamento).
    - **`custoApolice`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

      Valor do custo de emissão da apólice de seguros calculado no orçamento.
    - **`taxaJuros`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

      Percentual da taxa de juros utilizado no cálculo do orçamento.
    - **`taxaReativacao`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

      Taxa reativação da apólice calculada no orçamento.
    - **`valorIS`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, maxLength: `7`, pattern: `([0-9])`

      Valor total da importância segurada calculada no orçamento.
    - **`valorLMG`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

      Limite máximo de indenização para a apólice calculada no orçamento.
  - **`clausulas`**: `array`, maxItems: `999`

    **Items:**

    schema: `clausulas`
    - **`descricao`**: `string`, maxLength: `50`, pattern: `([A-Za-z])`

      descrição do agravo
    - **`valor`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, maxLength: `7`, pattern: `([0-9])`

      valor do agravo
  - **`coberturas`**: `array`, maxItems: `999`

    **Items:**

    schema: `coberturas`
    - **`codCobertura`**: `integer`, format: `int32`, minimum: `1`, maximum: `99`, pattern: `([0-9])`

      Código identificador da cobertura de seguro.
    - **`maximoLMI`**: `integer`, format: `int32`, minimum: `0`, maximum: `99`, pattern: `([0-9])`
    - **`minimoLMI`**: `integer`, format: `int32`, minimum: `0`, maximum: `99`, pattern: `([0-9])`
    - **`multiploLMI`**: `integer`, format: `int32`, minimum: `0`, maximum: `99`, pattern: `([0-9])`
    - **`nomeCobertura`**: `string`, maxLength: `20`, pattern: `([A-Za-z])`

      Descrição da cobertura de seguro.
    - **`obrigatorio`**: `string`, maxLength: `1`, pattern: `([0-9])`

      Indica se a cobertura em questão é obrigatória na geração da proposta
    - **`valorIS`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, maxLength: `7`, pattern: `([0-9])`

      Valor Importância Segurada dessa cobertura
    - **`valorIsOriginal`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, maxLength: `7`, pattern: `([0-9])`

      Valor original da Importância Segurada dessa cobertura
    - **`valorLMI`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, maxLength: `7`, pattern: `([0-9])`

      Limite máximo de indenização desta cobertura no seguro
    - **`valorPremio`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, maxLength: `7`, pattern: `([0-9])`

      Valor do prêmio desta cobertura
  - **`comissao`**: `object`, schema: `comissao`
    - **`percentualMaximo`**: `integer`, format: `int32`, minimum: `0`, maximum: `100`, pattern: `([0-9])`

      percentual máximo de comissão
    - **`percentualMinimo`**: `integer`, format: `int32`, minimum: `0`, maximum: `100`, pattern: `([0-9])`

      percentual mínimo de comissão
  - **`descontos`**: `array`, maxItems: `999`

    **Items:**

    schema: `descontos`
    - **`descricao`**: `string`, maxLength: `50`, pattern: `([A-Za-z])`

      descrição do agravo
    - **`valor`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, maxLength: `7`, pattern: `([0-9])`

      valor do agravo
  - **`formasPagamento`**: `array`, maxItems: `999`

    **Items:**

    schema: `formasPagamento`
    - **`condicoesPagamento`**: `object`, schema: `condicoesPagamento`
      - **`adicionalFracionamento`**: `integer`, format: `int32`, minimum: `0`, maximum: `999`, pattern: `([0-9])`

        Valor dos juros da condição de pagamento
      - **`codigoExterno`**: `integer`, format: `int32`, minimum: `0`, maximum: `999`, pattern: `([0-9])`

        Código externo da da condição de pagamento
      - **`condicaoPagamento`**: `string`, maxLength: `3`, pattern: `([0-9])`

        Código identificador da condição de pagamento. É composto por 3 caracteres numéricos com zeros à esquerda.
      - **`desconto`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, maxLength: `7`, pattern: `([0-9])`

        É o valor do desconto aplicado para o orçamento nesta forma e condição de pagamento.
      - **`iof`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, maxLength: `7`, pattern: `([0-9])`

        É o valor do IOF aplicado no orçamento nesta forma e condição de pagamento.
      - **`meioRecebimento`**: `string`, maxLength: `10`, pattern: `([A-Za-z])`

        Meio de recebimento da condição de pagamento
      - **`nomeCondicaoPagamento`**: `string`, maxLength: `4`, pattern: `([0-9A-Za-z])`

        Nome da condição de pagamento
      - **`percentualDesconto`**: `integer`, format: `int32`, minimum: `0`, maximum: `999`, pattern: `([0-9])`

        Percentual de desconto da condição de pagamento
      - **`premioBruto`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, maxLength: `7`, pattern: `([0-9])`

        É o valor do prêmio liquido do orçamento, com o IOF e sem a aplicação de descontos, nesta forma e condição de pagamento.
      - **`premioLiquido`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, maxLength: `7`, pattern: `([0-9])`

        Valor do prêmio líquido da condição de pagamento
      - **`premioTotal`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, maxLength: `7`, pattern: `([0-9])`

        É o valor total do orçamento a ser pago pelo segurado, já considerando o valor do IOF e aplicando os valores de descontos, nesta forma e condição de pagamento.
      - **`primeiroVencimento`**: `string`, maxLength: `10`, pattern: `([0-9])`

        Data do primeiro vencimento da condição de pagamento no formato DD/MM/AAAA
      - **`quantidadeParcela`**: `integer`, format: `int32`, minimum: `0`, maximum: `999`, pattern: `([0-9])`

        Quantidade de parcelas da condição de pagamento.
      - **`valorParcela`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, maxLength: `7`, pattern: `([0-9])`

        É o valor total incluindo IOF o os descontos de cada parcela nesta forma e condição de pagamento.
    - **`formaPagamento`**: `string`, maxLength: `3`, pattern: `([A-Za-z])`

      código da forma de pagamento
    - **`nomeFormaPagamento`**: `string`, maxLength: `30`, pattern: `([A-Za-z])`

      nome da forma de pagamento
  - **`nomeSeguro`**: `string`, maxLength: `10`, pattern: `([A-Za-z])`

    Nome seguro ou da oferta
  - **`seguro`**: `string`, maxLength: `4`, pattern: `([A-Za-z0-9])`

    Código identificador da oferta de seguro. Este código deverá ser informado na etapa de proposta para emissão da apólice conforme escolha do segurado. 0001 = Essencial 12; 0005 = Essencial 30; 0007 = Essencial 20; 0008 = Fiança.
- **`orcamento`**: `string`, minLength: `15`, maxLength: `15`, pattern: `([0-9])`

  Número do orçamento. Utilizá-lo para as demais iterações do orçamento
- **`origem`**: `string`, maxLength: `2`, pattern: `([0-9])`

  Origem do orçamento
- **`susep`**: `string`, minLength: `6`, maxLength: `6`, pattern: `([A-Z0-9])`

  Código susep da corretora responsável pelo orçamento.

**Example:**

```json
{
  "susep": "COL10J",
  "orcamento": "000000010763700",
  "ofertas": [
    {
      "seguro": "0008",
      "nomeSeguro": "ESSENCIAL 12",
      "comissao": null,
      "formasPagamento": [],
      "coberturas": [],
      "beneficios": [],
      "agravos": [],
      "descontos": [],
      "clausulas": [],
      "acordo": null,
      "calculo": null
    }
  ],
  "origem": 22
}
```

### 400 Avaliar a mensagem retornada no campo errorMessage. Pode ser a validação de algum campo ou a falha na autorização do cartão de crédito, por exemplo.

**Content type:** `application/json`

schema: `ProtheusCritica`

- **`errorCode`**: `integer`, format: `int32`, minimum: `400`, maximum: `599`, maxLength: `3`, pattern: `([0-9])`

  Código de erro relacionado à critérios da API
- **`errorMessage`**: `string`, maxLength: `50`, pattern: `([A-Z])`

  Mensagem de crítica da API

**Example:**

```json
{
  "errorCode": 400,
  "errorMessage": "O Campo XXXXXXX não foi preenchido!"
}
```

### 401 Não foi informado o token de acesso ou o token está expirado. Necessário utilizar a api de autenticação e obter um novo token que deverá ser enviado através do header Authorization.

**Content type:** `application/json`

`string`, maxLength: `50`, pattern: `([A-Z])`

**Example:**

```json
"Could not find a required Access Token in the request, identified by HEADER access_token"
```

### 500 Indica erro sistêmico e genérico. Caso a mensagem retornada não seja auto explicativa, acionar a equipe de TI da Porto Seguro para análise.

**Content type:** `application/json`

schema: `ProtheusError`

- **`errorCode`**: `integer`, format: `int32`, minimum: `400`, maximum: `599`, maxLength: `3`, pattern: `([0-9])`

  Código de erro não tratado.
- **`errorMessage`**: `string`, maxLength: `50`, pattern: `([A-Z])`

  Mensagem de erro da API

**Example:**

```json
{
  "errorCode": 500,
  "errorMessage": "Internal Server Error"
}
```

### 502 Status 502 indica alguma indisponibilidade sistêmica, seja por mudanças agendadas ou incidentes.&#x20;

**Content type:** `application/json`

`string`, maxLength: `50`, pattern: `([A-Z])`

**Example:**

```json
"Error forwarding call to back-end server"
```

## Schemas

- `comissao` — shown above.
- `condicoesPagamento` — shown above.
- `formasPagamento` — shown above.
- `coberturas` — shown above.
- `beneficios` — shown above.
- `agravos` — shown above.
- `clausulas` — shown above.
- `descontos` — shown above.
- `Ofertas` — shown above.
- `acordo` — shown above.
- `Calculo` — shown above.

### Unauthorized

**Type:** `string`, maxLength: `50`, pattern: `([A-Z])`

**Example:**

```json
"Could not find a required Access Token in the request, identified by HEADER access_token"
```

- `ProtheusCritica` — shown above.
- `ProtheusError` — shown above.

### BadGateway

**Type:** `string`, maxLength: `50`, pattern: `([A-Z])`

**Example:**

```json
"Error forwarding call to back-end server"
```

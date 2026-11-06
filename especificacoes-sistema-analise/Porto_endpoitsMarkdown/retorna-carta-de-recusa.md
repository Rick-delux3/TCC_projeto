# Retorna o PDF de carta de recusa cadastral de um orçamento de seguro.

- **Method:** `GET`
- **Path:** `/orcamentos/{proposta}/{corretor}/pdf/carta-recusa`
- **Tags:** PDF's e Impressão

Quando sucesso, retorna status 200 (HTTP Status Code) com o arquivo pdf em formato base64. Disponível apenas para orçamentos recusados.

Para saber mais sobre os possíveis status de retorno, consulte a seção *responses* abaixo.

## Effective servers

- `https://portoapicloud-hml.portoseguro.com.br/fianca-locaticia/emissao/aluguel-essencial/v1`

## Authentication

- **Bearer**: API key in header `Authorization`

## Path parameters

- **`proposta` (required)**: `string`, minLength: `15`, maxLength: `15`, pattern: `([0-9])`

  Número do orçamento interno da Porto Seguro, composto por 15 caracteres numéricos, preenchido com zeros à esquerda sem espaços, formatação, máscaras ou caracteres especiais. *Campo obrigatório*.
- **`corretor` (required)**: `string`, minLength: `6`, maxLength: `6`, pattern: `([A-Z0-9])`

  Código do corretor de seguros responsável pelo orçamento. Este é o código do corretor de seguros no cadastro interno da Porto Seguro, que é composto por 6 letras / números e termina com um F ou J.
  *Campo obrigatório*.

## Responses

### 200 Sucesso na geração do PDF da carta de recusa cadastral do orçamento de seguro.

**Content type:** `application/json`

schema: `SucessoGeracaoPDF`

- **`data`**: `object`, schema: `DataGeracaoPDF`
  - **`file`**: `string`, maxLength: `500`, pattern: `([A-Za-z0-9])`

    Arquivo PDF no padrão base64.
- **`message`**: `string`, maxLength: `50`, pattern: `([A-Z])`

  Mensagem de descritiva do sucesso na geração do PDF.
- **`status`**: `integer`, format: `int32`, minimum: `200`, maximum: `299`, maxLength: `3`, pattern: `([0-9])`

  Código padrão de sucesso na operação para gerar PDF.

**Example:**

```json
{
  "status": 200,
  "message": "OK",
  "data": null
}
```

### 400 Ocorreu inconsistência com os dados informados e/ou regras de negócio. Avalie a mensagem retornada no campo message e corrija a informação apontada.

**Content type:** `application/json`

schema: `CriticaGeracaoPDF`

- **`message`**: `string`, maxLength: `50`, pattern: `([A-Z])`

  Mensagem de descritiva da inconsistência encontrada.
- **`status`**: `integer`, format: `int32`, minimum: `400`, maximum: `599`, maxLength: `3`, pattern: `([0-9])`

  Código padrão de inconsistências na requisição da API

**Example:**

```json
{
  "status": 400,
  "message": "A proposta não pertence a esse corretor"
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

### 502 Status 502 indica alguma indisponibilidade sistêmica, seja por mudanças agendadas ou incidentes.

**Content type:** `application/json`

`string`, maxLength: `50`, pattern: `([A-Z])`

**Example:**

```json
"Error forwarding call to back-end server"
```

## Schemas

### Unauthorized

**Type:** `string`, maxLength: `50`, pattern: `([A-Z])`

**Example:**

```json
"Could not find a required Access Token in the request, identified by HEADER access_token"
```

- `ProtheusError` — shown above.
- `SucessoGeracaoPDF` — shown above.
- `DataGeracaoPDF` — shown above.
- `CriticaGeracaoPDF` — shown above.

### BadGateway

**Type:** `string`, maxLength: `50`, pattern: `([A-Z])`

**Example:**

```json
"Error forwarding call to back-end server"
```

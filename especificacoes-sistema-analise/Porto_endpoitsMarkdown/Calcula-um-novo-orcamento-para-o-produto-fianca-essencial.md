# Calcula um novo orçamento para o produto Fiança Essencial.

- **Method:** `POST`
- **Path:** `/orcamentos`
- **Tags:** Orçamento e Emissão

Quando sucesso, retorna status 200 (HTTP Status Code) com todos os dados do orçamento, tais como: ofertas, benefícios, formas e condições de pagamento no corpo da resposta. Para saber mais sobre os possíveis status de retorno, consulte a seção *responses* abaixo.

## Effective servers

- `https://portoapicloud-hml.portoseguro.com.br/fianca-locaticia/emissao/aluguel-essencial/v1`

## Authentication

- **Bearer**: API key in header `Authorization`

## Request body

Requisição mínima para gerar um orçamento. Trata-se dos dados básicos dos pretendentes, coberturas e configuração do seguro

**Required:** `true`

**Content type:** `application/json`

- **`cep` (required)**: `string`, maxLength: `8`, pattern: `([0-9])`

  Código do CEP do imóvel, composto por 8 caracteres numéricos, sem máscaras, sem caracteres especiais e sem espaços. Para CEPs com menos de 8 digitos, utilizar zeros à esquerda. A Validaçào será feita utilizando as bases disponibilizadas pelos Correios, incluindo CEPS's genéricos', CEPs de cidades e CEP's especiais. *Campo obrigatório*.
- **`coberturas` (required)**: `array`

  Lista de coberturas para cotação / orçamento. É obrigatório informar pelo menos a cobertura do ALUGUEL (Código 001). Campo obrigatório.

  **Items:**

  schema: `OrcamentoCobertura`

  Lista de coberturas para cotação / orçamento. É obrigatório informar pelo menos a cobertura do ALUGUEL (Código 001) e não permitidas coberturas com valor menor ou igual à zero. Caso não queira a cobertura, basta não enviá-la.
  - **`codCobertura` (required)**: `string`, possible values: `"001", "002", "003", "004", "005", "006", "007", "008", "009", "010"`, maxLength: `3`, pattern: `([0-9])`

    Código da cobertura. É composto por 3 caracteres numéricos, com zeros à esquerda. *Campo obrigatório*.
    As coberturas disponíveis estão listadas abaixo
    - 001 - ALUGUEL (Obrigatória)
    - 002 - IPTU
    - 003 - CONDOMÍNIO
    - 004 - ÁGUA
    - 005 - LUZ
    - 006 - GÁS
    - 007 - DANOS AO IMÓVEL
    - 008 - PINTURA INTERNA
    - 009 - PINTURA EXTERNA
    - 010 - MULTA RESCISÓRIA
  - **`valorIS` (required)**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, maxLength: `7`, pattern: `([0-9])`

    Valor da importância segurada da cobertura informada. Composto por um valor numérico, sendo 9 digitos inteiros e dois decimais, separados por ponto e sem máscaras ou formatação.\
    *Campo obrigatório* para a cobertura básica
    - 001 - ALUGUEL - Valor mínimo R$200,00 e Máximo R$10000,00

    As coberturas abaixo são opcionais. Em caso de contratação devem possuir um valor mínimo de R$0,01 e menor ou igual ao valor da cobertura 001 - ALUGUEL
    - 002 - IPTU
    - 003 - CONDOMÍNIO
    - 004 - ÁGUA
    - 005 - LUZ
    - 006 - GÁS

    As coberturas abaixo são opcionais. Em caso de contratação o valor segurado será igual ao valor da cobertura 001 - ALUGUEL
    - 007 - DANOS AO IMÓVEL
    - 008 - PINTURA INTERNA
    - 009 - PINTURA EXTERNA
    - 010 - MULTA RESCISÓRIA
- **`contrato` (required)**: `object`, schema: `Contrato`

  Dados do contrato de locação do imóvel. *Campo obrigatório*
  - **`codigoReajuste` (required)**: `string`, maxLength: `1`, pattern: `([0-9])`

    Código do reajuste, composto por 3 caracteres alfanuméricos (campo no formato string). *Campo obrigatório*. Preencher conforme lista abaixo:
    - 001 - MAIOR ÍNDICE
    - 002 - IGP-M (FGV)
    - 003 - IGP-DI (FGV)
    - 004 - IPC (FIPE)
    - 005 - IPCA (IBGE)
    - 006 - INPC (IBGE)
    - 007 - ICV (DIEESE)
    - 008 - INCC
    - 009 - IPC (FGV)
    - 010 - IVAR
  - **`fimContrato` (required)**: `string`, maxLength: `10`, pattern: `([0-9\/])`

    Data de término da vigencia do contrato de locação no formato DD/MM/AAAA. Esta data deve ser posterior a data de início do contrato. *Campo obrigatório*
  - **`fimSeguro` (required)**: `string`, maxLength: `10`, pattern: `([0-9\/])`

    Data de término da vigencia do seguro de fiança no formato DD/MM/AAAA. Esta data deve ser posterior a data de início do seguro de fiança. *Campo obrigatório*
  - **`inicioContrato` (required)**: `string`, maxLength: `10`, pattern: `([0-9\/])`

    Data de inicio da vigencia do contrato de locação no formato DD/MM/AAAA. Esta data será validada de acordo com o tipoVigenciaContrato informado (LOCAÇÃO FUTURA, LOCAÇÃO EM VIGOR E LOCAÇÃO POR TEMPO INDETERMINADO). *Campo obrigatório*
  - **`inicioSeguro` (required)**: `string`, maxLength: `10`, pattern: `([0-9\/])`

    Data de inicio da vigencia do seguro de fiança no formato DD/MM/AAAA. Esta data deve ser maior ou igual à data de início do contrato de locação. *Campo obrigatório*
  - **`tipoVigenciaContrato` (required)**: `string`, maxLength: `1`, pattern: `([0-9])`

    Código da vigencia do contrato de locação, composto por 1 caracter alfanumérico (campo no formato string). *Campo obrigatório*. Preencher conforme lista abaixo:
    - 0 - LOCAÇÃO FUTURA
    - 1 - LOCAÇÃO EM VIGOR
    - 2 - LOCAÇÃO POR TEMPO INDETERMINADO
- **`cpfCotador` (required)**: `string`, maxLength: `11`, pattern: `([0-9])`

  Cpf do funcionário da imobiliaria ou corretora que esta fazendo a cotação para efeito de bonificação. Deve conter 11 digitos numéricos, sem máscaras ou caracteres especiais e caso tenha menos que 11 digitos preencher com zeros à esquerda. *Campo obrigatório*
- **`locacaoComercial` (required)**: `object`, schema: `LocacaoComercial`

  Dados complementares para locações comerciais (PJ ou PFCOM). Campo obrigatório apenas para locações (PJ ou PFCOM)
  - **`capitalAberturaNegocio` (required)**: `integer`, format: `int32`, minimum: `0`, maximum: `99999999`, pattern: `([0-9])`

    Capital para abertura de negócio. *Campo obrigatório* para locações PFCOM.
  - **`capitalSocial` (required)**: `integer`, format: `int32`, minimum: `0`, maximum: `99999999`, pattern: `([0-9])`

    Capital Social da empresa. *Campo obrigatório* para locações PJ.
  - **`motivoLocacao` (required)**: `string`, minLength: `1`, maxLength: `1`, pattern: `([0-9A-Z])`

    Motivo de locação pretendida. *Campo obrigatório* para locações PJ dentro das opções disponíveis:
    - 1 - Abertura de Matriz
    - 2 - Cedido
    - 3 - Redução de Custos
    - 4 - Venda do imóvel atual
    - 5 - Troca de local de sede
    - 6 - Locação adicional
    - 7 - Abertura de filial
    - 8 - Troca de garantia
    - 9 - Abertura de nova empresa
    - A - Locação para moradia
  - **`atividadeEconomica`**: `string`, minLength: `1`, maxLength: `1`, pattern: `([1-3])`

    Atividade econômica da empresa. *Campo opcional* para locações PFCOM dentro das opções disponíveis:
    - 1 - Serviço
    - 2 - Indústria
    - 3 - Comercio
  - **`cnpj`**: `string`, minLength: `14`, maxLength: `14`, pattern: `([0-9])`

    CNPJ da empresa. *Campo opcional* para locações PFCOM.
- **`locatarios` (required)**: `array`

  Lista dos locatários / inquilinos que devem constar como responsáveis financeiros na proposta. Estes CPF's serão utilizados para aceitação. *Campo obrigatório*.

  **Items:**

  schema: `OrcamentoLocatario`
  - **`cpfCnpj` (required)**: `string`, maxLength: `11`, pattern: `([A-Z])`

    CPF do pretendente / locatário / inquilino. Deve conter 11 digitos numéricos, sem máscaras ou caracteres especiais e caso tenha menos que 11 digitos preencher com zeros à esquerda. *Campo obrigatório*.
  - **`dadosProfissionais` (required)**: `object`, schema: `DadosProfissionais`

    Informações referentes aos dados profissionais. Obrigatório para locações (PFRES e PFCOM).
    - **`totalRendimentos` (required)**: `integer`, format: `int32`, minimum: `0`, maximum: `99999999`, pattern: `([0-9])`

      É a soma de todos os valores que recebido em um determinado período.
    - **`dataAdmissao`**: `string`, maxLength: `10`, pattern: `([0-9\/])`

      A data em que o trabalhador foi oficialmente contratado e iniciou seu vínculo com a empresa.
    - **`empresa`**: `string`, minLength: `0`, maxLength: `90`, pattern: `([A-Za-z])`

      Nome da empresa.
    - **`expatriado`**: `boolean`

      É o profissional que trabalha temporariamente em um país diferente daquele onde possui residência ou nacionalidade, geralmente a pedido da própria empresa.
    - **`vinculoEmpregaticio`**: `string`, minLength: `0`, maxLength: `40`, pattern: `([A-Za-z])`

      É a relação formal entre um trabalhador e uma empresa (ou empregador), reconhecida pela legislação trabalhista brasileira, especialmente pela Consolidação das Leis do Trabalho.
  - **`dataNascimento` (required)**: `string`, maxLength: `10`, pattern: `([0-9\/])`

    Data de nascimento do locatário no formato DD/MM/AAAA. Campo obrigatório.
  - **`email` (required)**: `string`, maxLength: `1`, pattern: `([a-z0-9\@\.\-])`

    É o endereço de e-mail do locatário. É composto por no máximo 64 caracteres, deve conter um @, um . após o @, ao menos 5 caracteres antes do @, não deve terminar com um caracter especial, não deve conter nenhum caracter especial além do arroba, hífen e underline/underscore, não deve conter espaços. *Campo obrigatório*.
  - **`nome` (required)**: `string`, minLength: `5`, maxLength: `60`, pattern: `([A-Za-z])`

    Nome e sobrenome do pretendente / locatário / inquilino. É composto por no mínimo 5 caracteres, no máximo 60 caracteres, pelo menos 1 espaço entre o nome e sobrenome e sem caracteres especiais. Campo obrigatório.
  - **`telefoneCelular` (required)**: `string`, maxLength: `11`, pattern: `([0-9])`

    Número do telefone celular do locatário, incluindo o código DDD. É composto por 11 caracteres numéricos, sendo os dois primeiros o código DDD e os demais o número do celular, preenchido com zeros à esquerda, sem máscaras, sem caracteres especiais ou espaços. *Campo obrigatório*.
  - **`titular` (required)**: `string`, possible values: `"S", "N"`, maxLength: `1`, pattern: `([A-Z])`

    Campo que identifica o titular do orçamento. É composto por 1 caracter maiúsculo, sendo ''S'' para afirmar que o CPF é o titular e 'N' para afirmar que não é o titular. *Campo obrigatório*, sendo necessário ter apenas um único titular.
  - **`nomeSocial`**: `string`, maxLength: `60`, pattern: `([A-Za-z])`

    Nome social do pretendente / locatário / inquilino. *Campo opcional*.
- **`susep` (required)**: `string`, minLength: `6`, maxLength: `6`, pattern: `([A-Z0-9])`

  Código do corretor de seguros responsável pelo orçamento. Este é o código do corretor de seguros no cadastro interno da Porto Seguro, que é composto por 6 letras / números e termina com um F ou J.
  *Campo obrigatório*.
- **`tipoLocacao` (required)**: `string`, maxLength: `1`, pattern: `([0-9])`

  Tipo de locação do seguro, sendo:
  - 1 - PFRES
  - 2 - PFCOM
- **`diaVencimentoAluguel`**: `string`, minLength: `2`, maxLength: `2`, pattern: `([0-9])`

  Dia do vencimento do aluguel estipulado no contrato de locação.
- **`imobiliaria`**: `string`, maxLength: `6`, pattern: `([0-9])`

  Código da imobiliária vinculado ao contrato de locação. Este é o código da imobiliária no cadastro interno da Porto Seguro e é utilizado para disponibilizar a forma de pagamento Fatura Mensalizada (código 'FAT' no retorno do orçamento). Ele é composto por 6 caracteres numéricos, sem espaços, sem caracteres especiais, sem máscaras e deve ser preenchido com zeros à esquerda. *Campo opcional*
- **`orcamentoExterno`**: `string`, maxLength: `15`, pattern: `([0-9A-Za-z\.\-\/])`

  Número ou código do orçamento do sistema do parceiro caso exista, composto por até 32 caracteres com números ou letras maiúsculas / minúsculas. *Campo opcional*.

**Example:**

```json
{
  "susep": "COL10J",
  "orcamentoExterno": "SD3lo43.5565865A/JDI7-36",
  "cpfCotador": "02033008231",
  "imobiliaria": "000001",
  "cep": "02013000",
  "diaVencimentoAluguel": "05",
  "tipoLocacao": "1",
  "locatarios": [
    {
      "cpfCnpj": "43988733091",
      "nome": "João Antônio da Silva",
      "nomeSocial": "Haley",
      "dataNascimento": "07/02/1947",
      "titular": "S",
      "email": "jose.antonio-01@gmail.com",
      "telefoneCelular": "11998467395",
      "dadosProfissionais": null
    }
  ],
  "coberturas": [
    {
      "codCobertura": "001",
      "valorIS": 200.85
    }
  ],
  "contrato": {
    "tipoVigenciaContrato": "1",
    "inicioContrato": "07/06/2021",
    "fimContrato": "07/06/2023",
    "inicioSeguro": "07/06/2021",
    "fimSeguro": "07/06/2023",
    "codigoReajuste": "001"
  },
  "locacaoComercial": {
    "capitalSocial": "500000",
    "motivoLocacao": "1",
    "capitalAberturaNegocio": "500000",
    "cnpj": "14987000187448",
    "atividadeEconomica": "3"
  }
}
```

## Responses

### 201 Sucesso no cálculo / orçamento. Os dados do orçamento, incluindo ofertas, serão retornados conforme estrutura no exemplo abaixo. Para mais detalhes sobre campos e formatos, clique na opção Model abaixo.

**Content type:** `application/json`

- **`diaCorte`**: `integer`, format: `int32`, pattern: `([0-9])`

  Dia limite para processamento da fatura.
- **`localRisco`**: `object`, schema: `LocalRisco`

  Dados do imóvel a ser locado, que é o objeto do contrato e do seguro. Os dados do endereço serão preenchidos automaticamente à partir da base dos Correios utilizando o CEP informado no orçamento.
  - **`bairro`**: `string`, maxLength: `15`, pattern: `([A-Za-z])`

    Bairro do endereço do CEP informado.
  - **`cep`**: `string`, maxLength: `7`, pattern: `([0-9])`

    Código do CEP do imóvel, composto por 8 caracteres numéricos, sem máscaras, sem caracteres especiais e sem espaços. Para CEPs com menos de 8 digitos é preenchido com zeros à esquerda.
  - **`complemento`**: `string`, maxLength: `15`, pattern: `([A-Za-z0-9])`

    Complemento do local de risco da proposta de seguro. É composto por até 20 caracteres.
  - **`estado`**: `string`, maxLength: `2`, pattern: `([A-Z])`

    Sigla do estado do endereço do CEP informado.
  - **`logradouro`**: `string`, maxLength: `15`, pattern: `([A-Z])`

    Nome do logradouro do endereço do CEP informado.
  - **`municipio`**: `string`, maxLength: `15`, pattern: `([A-Z])`

    Municipio do endereço do CEP informado.
  - **`numero`**: `string`, maxLength: `5`, pattern: `([0-9])`

    Número do endereço do local de risco da proposta de seguro.
  - **`tipoLogradouro`**: `string`, maxLength: `1`, pattern: `([A-Z])`

    Tipo logradouro do endereço do CEP informado.
- **`ofertas`**: `array`

  **Items:**

  schema: `Oferta`
  - **`beneficios`**: `array`, maxItems: `999`

    **Items:**

    schema: `Beneficios`
    - **`codigo`**: `string`, maxLength: `3`, pattern: `([0-9])`

      Código identificador do benefício concedido pela Porto Seguro.
    - **`descricao`**: `string`, maxLength: `50`, pattern: `([0-9A-Za-z\,\.\!])`

      Descrição do benefício concedido pela Porto Seguro.
    - **`titulo`**: `string`, maxLength: `30`, pattern: `([0-9A-Z-a-z\!])`

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
  - **`coberturas`**: `array`, maxItems: `999`

    **Items:**

    schema: `Cobertura`

    Detalhes das coberturas de seguro
    - **`codCobertura`**: `string`, maxLength: `3`, pattern: `([0-9])`

      Código identificador da cobertura de seguro.
    - **`maximoLMI`**: `integer`, format: `int32`, minimum: `0`, maximum: `99`, pattern: `([0-9])`
    - **`minimoLMI`**: `integer`, format: `int32`, minimum: `0`, maximum: `99`, pattern: `([0-9])`
    - **`multiploLMI`**: `integer`, format: `int32`, minimum: `0`, maximum: `99`, pattern: `([0-9])`
    - **`nomeCobertura`**: `string`, maxLength: `10`, pattern: `([A-Za-z])`

      Descrição da cobertura de seguro.
    - **`valorIS`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

      Valor Importância Segurada dessa cobertura
    - **`valorLMI`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

      Limite máximo de indenização desta cobertura no seguro
    - **`valorPremio`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

      Valor do prêmio desta cobertura
  - **`formasPagamento`**: `array`, maxItems: `999`

    **Items:**

    schema: `FormaPagamento`
    - **`condicoesPagamento`**: `array`, maxItems: `999`

      **Items:**

      schema: `CondicaoPagamento`

      Detalhes das condições de pagamento / parcelamento.
      - **`adicionalFracionamento`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

        É o valor dos juros aplicado no orçamento, nesta forma e condição de pagamento. Para o Aluguel Essencial não há cobrança de juros, portanto esse valor será sempre 0.00.
      - **`condicaoPagamento`**: `string`, maxLength: `3`, pattern: `([0-9])`

        Código identificador da condição de pagamento. Na etapa de emissão, será necessário informar esse código junto com a respectiva forma de pagamento. É composto por 3 caracteres numéricos com zeros à esquerda.
      - **`desconto`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

        É o valor do desconto aplicado para o orçamento nesta forma e condição de pagamento.
      - **`iof`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

        É o valor do IOF aplicado no orçamento nesta forma e condição de pagamento.
      - **`nomeCondicaoPagamento`**: `string`, maxLength: `7`, pattern: `([A-Za-z])`

        Descrição da condição de pagamento.
      - **`percentualDesconto`**: `integer`, format: `int32`, minimum: `0`, maximum: `50`, pattern: `([0-9])`

        Percentual de desconto da condição de pagamento que foi aplicado no orçamento.
      - **`premioBruto`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

        É o valor do prêmio liquido do orçamento, com o IOF e sem a aplicação de descontos, nesta forma e condição de pagamento.
      - **`premioLiquido`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

        É o valor do prêmio total calculado para o orçamento sem o IOF e sem o valor de descontos, nesta forma e condição de pagamento.
      - **`premioTotal`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

        É o valor total do orçamento a ser pago pelo segurado, já considerando o valor do IOF e aplicando os valores de descontos, nesta forma e condição de pagamento.
      - **`primeiroVencimento`**: `string`, maxLength: `10`, pattern: `([0-9\/])`

        Data de vencimento da primeira parcela no formato DD/MM/AAAA.
      - **`quantidadeParcela`**: `integer`, format: `int32`, minimum: `1`, maximum: `48`, pattern: `([0-9])`

        Quantidade de parcelas da condição de pagamento.
      - **`valorParcela`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

        É o valor total incluindo IOF o os descontos de cada parcela nesta forma e condição de pagamento.
    - **`formaPagamento`**: `string`, maxLength: `2`, pattern: `([A-Za-z])`

      Sigla da forma de pagamento disponível na oferta, com até 3 letras maíusculas, podendo ser:
      - CP -  Cartão de crédito da Porto Seguro.
      - CC -  Demais cartões de crédito.
      - FAT - Fatura Imobiliária
      - ADC - Débito em Conta
      - BFT - 1ª Boleto demais faturas
      - BCA - 1ª Boleto demais Carnê
      - BOL - Boleto
    - **`nomeFormaPagamento`**: `string`, maxLength: `10`, pattern: `([A-Za-z])`

      Descrição completa da forma de pagamento na oferta
  - **`nomeSeguro`**: `string`, maxLength: `15`, pattern: `([0-9A-Za-z])`

    Nome do produto de seguro ofertado
  - **`seguro`**: `string`, maxLength: `4`, pattern: `([A-Za-z\.\,])`

    Código identificador da oferta de seguro. Este código deverá ser informado na etapa de proposta para emissão da apólice conforme escolha do segurado. 0001 = Essencial 12; 0005 = Essencial 30; 0007 = Essencial 20; 0008 = Fiança.
- **`orcamento`**: `string`, minLength: `15`, maxLength: `15`, pattern: `([0-9])`

  Número do orçamento interno da Porto Seguro, composto por 15 caracteres numéricos, preenchido com zeros à esquerda sem espaços, formatação, máscaras ou caracteres especiais.
- **`regraFixa`**: `boolean`, format: `binary`, maxLength: `10`, pattern: `([0-9])`

  Campo utilizado internamente pela Porto Seguro. Esse campo pode ser ignorado por Imobiliárias, corretores e parceiros externos sem impactos para suas operações

**Example:**

```json
{
  "orcamento": "000000010009512",
  "localRisco": {
    "cep": "02013000",
    "tipoLogradouro": "R",
    "logradouro": "TIRADENTES",
    "numero": "33",
    "complemento": "BL 5 APTO 3",
    "bairro": "Vila Assunção",
    "municipio": "SAO PAULO",
    "estado": "SP"
  },
  "ofertas": [
    {
      "seguro": "0008",
      "nomeSeguro": "FIANÇA",
      "calculo": null,
      "formasPagamento": [],
      "coberturas": [],
      "beneficios": []
    }
  ],
  "regraFixa": false,
  "diaCorte": 31
}
```

### 400 Ocorreu inconsistência com os dados informados e/ou regras de negócio. Avalie a mensagem retornada no campo errorMessage e corrija a informação apontada.

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

### 409 Houve recusa na aceitação do orçamento devido aos critérios de aceitação da cia.

**Content type:** `application/json`

schema: `ProtheusRecusaAceitacao`

- **`errorCode`**: `integer`, format: `int32`, minimum: `400`, maximum: `599`, maxLength: `3`, pattern: `([0-9])`

  Código de erro relacionado à recusa na análise cadastral
- **`errorMessage`**: `string`, maxLength: `50`, pattern: `([A-Z])`

  Mensagem de erro da API de análise de crédito

**Example:**

```json
{
  "errorCode": 409,
  "errorMessage": "Devido aos critérios de aceitação da companhia, infelizmente não será possível prosseguir com a contratação para o cadastro informado"
}
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

- `OrcamentoCobertura` — shown above.
- `OrcamentoLocatario` — shown above.
- `DadosProfissionais` — shown above.
- `LocacaoComercial` — shown above.
- `Beneficios` — shown above.
- `Calculo` — shown above.
- `CondicaoPagamento` — shown above.
- `Contrato` — shown above.
- `FormaPagamento` — shown above.
- `LocalRisco` — shown above.
- `Oferta` — shown above.
- `Cobertura` — shown above.

### Unauthorized

**Type:** `string`, maxLength: `50`, pattern: `([A-Z])`

**Example:**

```json
"Could not find a required Access Token in the request, identified by HEADER access_token"
```

- `ProtheusCritica` — shown above.
- `ProtheusError` — shown above.
- `ProtheusRecusaAceitacao` — shown above.

### BadGateway

**Type:** `string`, maxLength: `50`, pattern: `([A-Z])`

**Example:**

```json
"Error forwarding call to back-end server"
```

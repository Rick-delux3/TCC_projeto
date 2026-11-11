# Retorna os dados atualizados do orçamento de seguro.

- **Method:** `GET`
- **Path:** `/orcamentos/{orcamento}/{susep}`
- **Tags:** Orçamento e Emissão

Quando sucesso, retorna status 200 (HTTP Status Code) com os dados do orçamento de seguro.

Para saber mais sobre os possíveis status de retorno, consulte a seção *responses* abaixo.

## Effective servers

- `https://portoapicloud-hml.portoseguro.com.br/fianca-locaticia/emissao/aluguel-essencial/v1`

## Authentication

- **Bearer**: API key in header `Authorization`

## Path parameters

- **`orcamento` (required)**: `string`, minLength: `15`, maxLength: `15`, pattern: `([0-9])`

  Número do orçamento interno da Porto Seguro, composto por 15 caracteres numéricos, preenchido com zeros à esquerda sem espaços, máscaras ou caracteres especiais. *Campo obrigatório*.
- **`susep` (required)**: `string`, minLength: `6`, maxLength: `6`, pattern: `([A-Z0-9])`

  Código do corretor de seguros responsável pelo orçamento. Este é o código do corretor de seguros no cadastro interno da Porto Seguro, que é composto por 6 letras / números e termina com um F ou J.
  *Campo obrigatório*.

## Responses

### 200 Sucesso na consulta dos dados atualizados do orçamento de seguro.

**Content type:** `application/json`

schema: `CanonicoOrcamento`

Retorna todos os dados disponíveis da proposta.

- **`apolice`**: `object`, schema: `ApoliceCanonico`
  - **`codigo`**: `string`, maxLength: `10`, pattern: `([0-9])`
  - **`data`**: `string`, maxLength: `10`, pattern: `([a-zA-Z])`
- **`beneficios`**: `array`, maxItems: `999`

  **Items:**

  schema: `Beneficios`
  - **`codigo`**: `string`, maxLength: `3`, pattern: `([0-9])`

    Código identificador do benefício concedido pela Porto Seguro.
  - **`descricao`**: `string`, maxLength: `50`, pattern: `([0-9A-Za-z\,\.\!])`

    Descrição do benefício concedido pela Porto Seguro.
  - **`titulo`**: `string`, maxLength: `30`, pattern: `([0-9A-Z-a-z\!])`

    Título do benefício concedido pela Porto Seguro
- **`calculo`**: `object`, schema: `CalculoCanonico`

  Detalhes dos valores brutos calculados no orçamento de seguro.
  - **`adicionalFracionamento`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

    Valor dos juros calculado no orçamento (adicional de fracionamento).
  - **`custoApolice`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

    Valor do custo de emissão da apólice de seguros calculado no orçamento.
  - **`premioBruto`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

    Valor do prêmio bruto
  - **`taxaJuros`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

    Percentual da taxa de juros utilizado no cálculo do orçamento.
  - **`taxaReativacao`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

    Taxa reativação da apólice calculada no orçamento.
  - **`valorIS`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

    Valor total da importância segurada calculada no orçamento.
  - **`valorLMG`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

    Limite máximo de indenização para a apólice calculada no orçamento.
- **`coberturas`**: `array`, maxItems: `999`

  **Items:**

  schema: `CoberturaCanonico`

  Detalhes das coberturas de seguro
  - **`codCobertura`**: `string`, maxLength: `15`, pattern: `([0-9])`

    Código identificador da cobertura de seguro.
  - **`maximoLMI`**: `integer`, format: `int32`, minimum: `0`, maximum: `99`, pattern: `([0-9])`
  - **`minimoLMI`**: `integer`, format: `int32`, minimum: `0`, maximum: `99`, pattern: `([0-9])`
  - **`multiploLMI`**: `integer`, format: `int32`, minimum: `0`, maximum: `99`, pattern: `([0-9])`
  - **`nomeCobertura`**: `string`, maxLength: `15`, pattern: `([A-Za-z])`

    Descrição da cobertura de seguro.
  - **`obrigatorio`**: `string`, maxLength: `1`, pattern: `([A-Z])`

    Indica se a cobertura em questão é obrigatória na geração da proposta
  - **`valorIS`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9\.])`

    Valor Importância Segurada dessa cobertura
  - **`valorIsOriginal`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9\.])`

    Valor original da Importância Segurada dessa cobertura
  - **`valorLMI`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9\.])`

    Limite máximo de indenização desta cobertura no seguro
  - **`valorPremio`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, maxLength: `7`, pattern: `([0-9\.])`

    Valor do prêmio desta cobertura
- **`cobranca`**: `object`, schema: `Cobranca`

  Informações referentes à forma e condição de pagamento da proposta da preferência do cliente. *Campo obrigatório*.
  - **`condicaoPagamento` (required)**: `string`, maxLength: `3`, pattern: `([0-9])`

    Código da condição de pagamento escolhida pelo cliente. A lista das condições de pagamento disponíveis é retornada dentro da forma de pagamento ao qual pertence. É composto por 3 caracteres numéricos e preenchido com zeros à esquerda. *Campo obrigatório*.
  - **`formaPagamento` (required)**: `string`, possible values: `"CP", "CC", "FAT", "ADC"`, maxLength: `3`, pattern: `([A-Z])`

    Código da forma de pagamento escolhida pelo cliente. A lista das formas de pagamento são disponibilizadas dentro da oferta no retorno da API de orçamento. É composto por até 3 caracteres maiúsculos. Campo obrigatório e dentro das opções disponíveis:
    - CP - Cartão de crédito da Porto Seguro
    - CC - Demais cartões de crédito.
    - FAT - Fatura mensal (Disponível apenas quando informado no orçamento o parceiro / imobiliária com vínculo com o corretor da proposta)
    - ADC - Débito em Conta
    - BFT - 1ª Boleto demais faturas
    - BCA - 1ª Boleto demais Carnê
    - BOL - Boleto

    ATENÇÃO: No ambiente de homologação, a aprovação da forma de pagamento CP - Cartão de crédito da Porto Seguro está condicioanda as seguintes regras:
    - Utilização de um cartão de crédito disponível no site da e-Rede: <https://developer.userede.com.br/e-rede#cartoes>
    - Valor total do prêmio precisa ser um número par e menor que 300.00 ou par e maior que 500.00. Para maior detalhes, consulte a documentação de negócio disponível no portal.
  - **`cartaoCredito`**: `object`, schema: `CartaoCredito`

    Informações para pagamento com cartão de crédito. *Campo obrigatório* para emissão caso a forma de pagamento seja **CP - Cartão de crédito da Porto Seguro** ou **CC - Demais cartões de crédito**
    - **`cpfCnpjTitular` (required)**: `string`, maxLength: `2`, pattern: `([A-Z])`

      CPF do titular do cartão de crédito. Deve conter 11 digitos numéricos, sem máscaras ou caracteres especiais e caso tenha menos que 11 digitos preencher com zeros à esquerda. *Campo obrigatório*.
    - **`numeroCartao` (required)**: `string`, maxLength: `2`, pattern: `([A-Za-z0-9\=])`

      Número do cartão de crédito criptografado através da API de criptografia. É composto por até 45 caracteres com números, letras maiúsculas ou minúsculas e caracteres especiais, sem espaços. Para criptografar é necessário acionar a API /fianca/v1/comum/criptografias informando os 16 caracteres numéricos do cartão de crédito do cliente, sem máscaras, sem espaços ou caracteres especiais. *Campo obrigatório*.
    - **`titularCartao` (required)**: `string`, maxLength: `30`, pattern: `([A-Za-z])`

      Nome do titular do cartão de crédito conforme escrito no plástico. É composto por até 60 caracteres alfanuméricos, sem caracteres especiais. *Campo obrigatório*.
    - **`validadeCartao` (required)**: `string`, maxLength: `25`, pattern: `([A-Za-z0-9\=])`

      Validade do cartão de crédito criptografada através da API de criptografia. É composto por até 25 caracteres com números, letras maiúsculas ou minúsculas e caracteres especiais, sem espaços. Para criptografar é necessário acionar a API /fianca/v1/comum/criptografias informando a data de validade do cartão de crédito do cliente no formato MMAAAA, sem máscaras, sem espaços ou caracteres especiais. *Campo obrigatório*.
- **`cocorretagens`**: `object`, schema: `Cocorretagens`
  - **`nomeCorretor`**: `string`, maxLength: `30`, pattern: `([A-Za-z\&])`
  - **`percentual`**: `integer`, format: `int32`, minimum: `0`, maximum: `100`, maxLength: `3`, pattern: `([0-9])`
  - **`susep`**: `string`, minLength: `6`, maxLength: `6`, pattern: `([A-Z0-9])`
- **`contrato`**: `object`, schema: `ContratoCanonico`

  Dados do contrato de locação do imóvel
  - **`codigoReajuste`**: `string`, maxLength: `1`, pattern: `([0-9])`

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
  - **`diaVencimento`**: `integer`, maxLength: `2`, pattern: `([0-9])`

    Corresponde ao dia de vencimento da fatura, para as formas de pagamento: Fatura com entrada, Fatura sem entrada ou Aluguel Garantido.

    ⚠️Para a forma de pagamento *Aluguel Garantido*, este atributo refere-se ao dia de vencimento da fatura que será cobrada do cliente (locatário).
  - **`diaVencimentoAluguel`**: `string`, minLength: `2`, maxLength: `2`, pattern: `([0-9])`

    Dia do vencimento do aluguel estipulado no contrato de locação.
  - **`fimContrato`**: `string`, maxLength: `10`, pattern: `([0-9\/])`

    Data de término da vigencia do contrato de locação no formato DD/MM/AAAA.
  - **`fimSeguro`**: `string`, maxLength: `10`, pattern: `([0-9\/]),`

    Data de término da vigencia do seguro de fiança no formato DD/MM/AAAA. Esta data deve ser posterior a data de início do seguro de fiança. *Campo obrigatório*
  - **`inicioContrato`**: `string`, maxLength: `10`, pattern: `([0-9\/])`

    Data de inicio da vigencia do contrato de locação no formato DD/MM/AAAA.
  - **`inicioSeguro`**: `string`, maxLength: `10`, pattern: `([0-9\/])`

    Data de inicio da vigencia do seguro de fiança no formato DD/MM/AAAA. Esta data deve ser maior ou igual à data de início do contrato de locação. *Campo obrigatório*
  - **`nomecodigoReajuste`**: `string`, maxLength: `15`, pattern: `([A-Za-z])`

    Texto do código do reajuste, equivalento do código na tag "codigoReajuste"
  - **`nomeTipoLocacao`**: `string`, maxLength: `1`, pattern: `([A-Za-z])`

    Texto para o tipo de locação.
  - **`quantidadeDiasContrato`**: `string`, maxLength: `4`, pattern: `([0-9])`

    Quantidade de dias do contrato, sendo a diferença entre a data de início de contrato e a de fim do contrato
  - **`quantidadeDiasSeguro`**: `integer`, format: `int32`, minimum: `0`, maximum: `9999`, maxLength: `4`, pattern: `([0-9])`

    Quantidade de dias da vigência do seguro, sendo a diferença entre a data de início da vigência e do fim da vigência
  - **`tipoVigenciaContrato`**: `string`, maxLength: `1`, pattern: `([0-9])`

    Código da vigencia do contrato de locação. Valores conforme lista abaixo:
    - 0 - LOCAÇÃO FUTURA
    - 1 - LOCAÇÃO EM VIGOR
    - 2 - LOCAÇÃO POR TEMPO INDETERMINADO
- **`corretor`**: `object`, schema: `CorretorCanonico`

  Dados do corretor responsável pelo orçamento
  - **`bairro`**: `string`, maxLength: `15`, pattern: `([a-zA-Z])`

    Bairro do corretor
  - **`cep`**: `string`, maxLength: `7`, pattern: `([0-9])`

    Cep do corretor
  - **`cidade`**: `string`, maxLength: `15`, pattern: `([a-zA-Z])`

    Cidade do corretor
  - **`cpfCnpj`**: `string`, maxLength: `14`, pattern: `([0-9])`

    CPF ou CNPJ do corretor
  - **`email`**: `string`, maxLength: `30`, pattern: `([0-9a-zA-Z\@\.\_])`

    Email do corretor
  - **`estado`**: `string`, maxLength: `2`, pattern: `([A-Z])`

    Estado do Corretor, composto por 2 letras maíusculas
  - **`logradouro`**: `string`, maxLength: `15`, pattern: `([a-zA-Z])`

    Logradouro do corretor
  - **`nome`**: `string`, maxLength: `50`, pattern: `([0-9a-zA-Z])`

    Nome do corretor
  - **`nomeSocial`**: `string`, maxLength: `60`, pattern: `([A-Za-z])`

    Nome social do pretendente / locatário / inquilino. *Campo opcional*.
  - **`susepPorto`**: `string`, minLength: `6`, maxLength: `6`, pattern: `([A-Z0-9])`

    Susep do corretor
  - **`telefone`**: `string`, maxLength: `11`, pattern: `([0-9])`

    Telefone co corretor
- **`cpfCotador`**: `string`, maxLength: `11`, pattern: `([0-9])`

  CPF Cotador
- **`dataAceitacao`**: `string`, maxLength: `10`, pattern: `([0-9\/])`

  Data Aceitação no formato DD/MM/AAAA
- **`dataCriacao`**: `string`, maxLength: `10`, pattern: `([0-9\/])`

  Data criação
- **`descontosAgravos`**: `array`, maxItems: `999`

  descontos agravos

  **Items:**

  schema: `DescontosAgravos`
  - **`descricao`**: `string`, maxLength: `50`, pattern: `([0-9A-Z-a-z\!])`

    descrição do agravo
  - **`valor`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, maxLength: `7`, pattern: `([0-9])`

    valor do agravo
- **`endosso`**: `string`, maxLength: `7`, pattern: `([0-9])`

  Número do endosso
- **`formasPagamento`**: `object`, schema: `FormaPagamentoCanonico`
  - **`condicoesPagamento`**: `array`, maxItems: `999`

    **Items:**

    schema: `CondicaoPagamentoCanonico`

    Detalhes das condições de pagamento / parcelamento.
    - **`adicionalFracionamento`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9\.])`

      Valor dos juros da condição de pagamento
    - **`codigoExterno`**: `string`, maxLength: `2`, pattern: `(0-9])`

      Código externo da da condição de pagamento
    - **`condicaoPagamento`**: `string`, maxLength: `7`, pattern: `([0-9])`

      Código identificador da condição de pagamento. É composto por 3 caracteres numéricos com zeros à esquerda.
    - **`desconto`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9\.])`

      Valor do desconto da condição de
    - **`iof`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9\.])`

      Valor do IOF da condição de pagamento
    - **`meioRecebimento`**: `string`, maxLength: `7`, pattern: `([a-zA-Z])`

      Meio re recebimento da condição de pagamento
    - **`nomeCondicaoPagamento`**: `string`, maxLength: `10`, pattern: `([a-zA-Z])`

      Descrição da condição de pagamento.
    - **`percentualDesconto`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9\.])`

      Percentual de desconto da condição de pagamento
    - **`premioBruto`**: `string`, maxLength: `7`, pattern: `([0-9\.])`

      Valor do prêmio bruto da condição de pagamento
    - **`premioLiquido`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9\.])`

      Valor do prêmio líquido da condição de pagamento
    - **`premioTotal`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9\.])`

      Valor do prêmio total da condição de pagamento.
    - **`primeiroVencimento`**: `string`, maxLength: `10`, pattern: `([0-9\/])`

      Data do primeiro vencimento da condição de pagamento no formato DD/MM/AAAA
    - **`quantidadeParcela`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9\.])`

      Quantidade de parcelas da condição de pagamento
    - **`valorParcela`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9\.])`

      Valor total da parcela da condição de pagamento
  - **`formaPagamento`**: `string`, maxLength: `3`, pattern: `([a-zA-Z])`

    Sigla da forma de pagamento disponível na oferta, com até 3 letras maíusculas, podendo ser:
    - CP -  Cartão de crédito da Porto Seguro.
    - CC -  Demais cartões de crédito.
    - FAT - Fatura Imobiliária
    - ADC - Débito em Conta
    - BFT - 1ª Boleto demais faturas
    - BCA - 1ª Boleto demais Carnê
    - BOL - Boleto
  - **`nomeFormaPagamento`**: `string`, maxLength: `10`, pattern: `([a-zA-Z])`

    Descrição completa da forma de pagamento na oferta
- **`localRisco`**: `object`, schema: `LocalRiscoCanonico`

  Dados do imóvel a ser locado, que é o objeto do contrato e do seguro
  - **`bairro`**: `string`, maxLength: `20`, pattern: `([A-Za-z])`

    Bairro do endereço do CEP informado.
  - **`cep`**: `string`, maxLength: `7`, pattern: `([A0-9])`

    Código do CEP do imóvel, composto por 8 caracteres numéricos
  - **`codigoLogradouro`**: `string`, maxLength: `2`, pattern: `([0-9])`

    Código do tipo de logradouro
  - **`codigoPais`**: `string`, maxLength: `5`, pattern: `([0-9])`

    Código do país do endereço do local de risco
  - **`complemento`**: `string`, maxLength: `20`, pattern: `([A-Za-z0-9])`

    Complemento do endereço do local de risco
  - **`estado`**: `string`, maxLength: `2`, pattern: `([A-Za-z])`

    Sigla do estado do endereço do CEP informado.
  - **`logradouro`**: `string`, maxLength: `20`, pattern: `([A-Za-z])`

    Nome do logradouro do endereço do CEP informado.
  - **`municipio`**: `string`, maxLength: `20`, pattern: `([A-Za-z])`

    Municipio do endereço do CEP informado.
  - **`nomeLogradouro`**: `string`, maxLength: `20`, pattern: `([A-Za-z])`

    Nome do logradouro correspondente a tag 'tipoLogradouro'.
  - **`numero`**: `string`, maxLength: `5`, pattern: `([0-9])`

    Número do endereço do local de risco
  - **`pais`**: `string`, maxLength: `20`, pattern: `([A-Za-z])`

    País do endereço do local de risco
  - **`tipoLogradouro`**: `string`, maxLength: `1`, pattern: `([A-Za-z])`

    Tipo logradouro do endereço do CEP informado.
- **`locatarios`**: `array`, maxItems: `999`

  **Items:**

  schema: `LocatariosCanonico`

  Lista de locatários
  - **`cpfCnpj`**: `string`, maxLength: `14`, pattern: `([0-9])`

    CFP ou CNPJ do locatário
  - **`dataNascimento`**: `string`, maxLength: `10`, pattern: `([0-9\/])`

    Data de nascimento do locatário
  - **`email`**: `string`, maxLength: `20`, pattern: `([A-Za-z0-9\.\@\_])`

    Email do locatário
  - **`faixaRenda`**: `string`, maxLength: `3`, pattern: `([0-9])`

    Código da faixa de renda do locatário conforma abaixo: \* 001 - Sem Renda
    - 002 - Até R$2.500,00
    - 003 - De R$2.500,01 até R$5.000,00
    - 004 - De R$5.000,01 até R$10.000,00
    - 005 - Acima de R$10.000,01
  - **`faixaRendaNome`**: `any`, minimum: `100`, maximum: `10000`, maxLength: `7`, pattern: `([0-9\.])`

    Texto do código de faixa de renda conforme tag 'faixaRenda'
  - **`mae`**: `string`, maxLength: `30`, pattern: `([A-Za-z])`

    Nome da mãe do locatário
  - **`nome`**: `string`, maxLength: `30`, pattern: `([A-Za-z])`

    Nome do locatário
  - **`nomeSocial`**: `string`, maxLength: `60`, pattern: `([A-Za-z])`

    Nome social do pretendente / locatário / inquilino. *Campo opcional*.
  - **`pep`**: `object`, schema: `PepCanonico`

    Dados relacionados à pessoa exposta politicamente (PEP). São PEP os ocupantes de cargos e funções públicas como Deputados e Senadores.
    - **`cpfCnpj`**: `string`, maxLength: `11`, pattern: `([0-9])`

      CPF do PEP com 11 caracteres numéricos
    - **`faixaRenda`**: `string`, maxLength: `3`, pattern: `([0-9])`

      Faixa renda do PEP, conforme lista abaixo:
      - 001 - Sem Renda
      - 002 - Até R$2.500,00
      - 003 - De R$2.500,01 até R$5.000,00
      - 004 - De R$5.000,01 até R$10.000,00
      - 005 - Acima de R$10.000,01
    - **`faixaRendaNome`**: `string`, maxLength: `20`, pattern: `([A-Za-z])`
    - **`nacionalidade`**: `string`, maxLength: `3`, pattern: `([0-9])`

      Nacionalidade do PEP, composto conforme lista abaixo.
      - 001 - Brasileira
      - 002 - Estrangeira
    - **`nacionalidadeNome`**: `string`, maxLength: `20`, pattern: `([A-Za-z])`
    - **`nome`**: `string`, maxLength: `30`, pattern: `([A-Za-z])`

      Nome e sobrenome do PEP com no mínimo 5 caracteres e no máximo 60 caracteres
    - **`nomeSocial`**: `string`, maxLength: `60`, pattern: `([A-Za-z])`

      Nome social do pretendente / locatário / inquilino. *Campo opcional*.
    - **`paisResidencia`**: `string`, maxLength: `5`, pattern: `([0-9])`

      País de residência do PEP com 5 caracteres numéricos preenchidos com zeros à esquerda
    - **`paisResidenciaNome`**: `string`, maxLength: `20`, pattern: `([A-Za-z])`
    - **`profissao`**: `string`, maxLength: `4`, pattern: `([0-9])`

      Código da profissão conforme Cadastro Brasileiro de Ocupações (CBO) que é utilizado pela receita federal
    - **`profissaoNome`**: `string`, maxLength: `20`, pattern: `([A-Za-z])`
    - **`relacionamento`**: `string`, maxLength: `20`, pattern: `([A-Za-z])`
    - **`relacionamentoNome`**: `string`, maxLength: `20`, pattern: `([A-Za-z])`
  - **`sexo`**: `string`, maxLength: `1`, pattern: `([A-Z])`

    Sexo  do locatário
  - **`telefoneCelular`**: `string`, maxLength: `11`, pattern: `([0-9])`

    Telefone celular do locatário
  - **`telefoneComercial`**: `string`, maxLength: `11`, pattern: `([0-9])`

    Telefone comercial do locatário
  - **`telefoneResidencial`**: `string`, maxLength: `11`, pattern: `([0-9])`

    Telefone residencial do locatário
  - **`tipoPessoa`**: `string`, maxLength: `1`, pattern: `([A-z])`

    Tipo de pessoa do locatário, sendo F pessoa física e J pessoa jurídica
  - **`titular`**: `string`, maxLength: `1`, pattern: `([A-Z])`

    Indica se o locatário é o títular do orçamento
- **`modalidade`**: `string`, maxLength: `3`, pattern: `([0-9])`

  Código da modalidade do Produto
- **`nomeModalidade`**: `string`, maxLength: `15`, pattern: `([a-zA-Z0-9])`

  Nome modalidade
- **`nomeRamo`**: `string`, maxLength: `10`, pattern: `([a-zA-Z])`

  Nome ramo
- **`nomeSeguradora`**: `string`, maxLength: `10`, pattern: `([a-zA-Z])`

  Nome Seguradora
- **`nomeSeguro`**: `string`, maxLength: `15`, pattern: `([a-zA-Z0-9])`

  Nome seguro Ou da oferta
- **`ofertas`**: `array`, maxItems: `999`

  **Items:**

  schema: `OfertasCanonico`
  - **`beneficios`**: `array`, maxItems: `999`

    **Items:**

    *Schema `Beneficios` is shown above.*
  - **`calculo`**: `object`, schema: `CalculoCanonico`

    Detalhes dos valores brutos calculados no orçamento de seguro.

    *Schema `CalculoCanonico` is shown above.*
  - **`coberturas`**: `array`, maxItems: `999`

    **Items:**

    *Schema `CoberturaCanonico` is shown above.*
  - **`formasPagamento`**: `array`, maxItems: `999`

    **Items:**

    *Schema `FormaPagamentoCanonico` is shown above.*
  - **`nomeSeguro`**: `string`, maxLength: `15`, pattern: `([A-Za-z0-9])`

    Nome do produto de seguro ofertado
  - **`seguro`**: `string`, maxLength: `4`, pattern: `([0-9])`

    Código identificador da oferta de seguro. Este código deverá ser informado na etapa de proposta para emissão da apólice conforme escolha do segurado. 0001 = Essencial 12; 0005 = Essencial 30; 0007 = Essencial 20; 0008 = Fiança.
- **`orcamento`**: `string`, minLength: `15`, maxLength: `15`, pattern: `([0-9])`

  Número do orçamento. Utilizá-lo para as demais iterações do orçamento
- **`orcamentoExterno`**: `string`, maxLength: `10`, pattern: `([a-zA-Z0-9])`

  Número do orçamento específico do Canal de vendas.
- **`origem`**: `string`, maxLength: `2`, pattern: `([0-9])`

  Número da origem da proposta. Emissão 22 e Renovação 40
- **`parceiro`**: `object`, schema: `ParceirosCanonico`

  Dados da imobiliária parceira
  - **`cnpjImobiliaria`**: `string`, maxLength: `14`, pattern: `([0-9])`

    cpnj da imobiliaria parceira no sistema da porto seguro
  - **`email`**: `string`, maxLength: `20`, pattern: `([0-9A-Za-z\.\@\_])`
  - **`imobiliaria`**: `string`, maxLength: `6`, pattern: `([0-9])`

    Código da imobiliária parceira no sistema da Porto Seguro
  - **`nomeImobiliaria`**: `string`, maxLength: `30`, pattern: `([0-9A-Z-a-z])`

    Nome da imobiliára parceira
  - **`telefone`**: `string`, maxLength: `11`, pattern: `([0-9])`
- **`proposta`**: `string`, maxLength: `15`, pattern: `([0-9])`

  Número da proposta
- **`ramo`**: `string`, maxLength: `4`, pattern: `([0-9])`

  Código do ramo do Produto
- **`regraFixa`**: `boolean`, format: `binary`
- **`renovacao`**: `object`, schema: `renovacaoCanonico`

  Informações pertinentes à futura renovação do seguro
  - **`apolice`**: `string`, maxLength: `10`, pattern: `([a-zA-Z])`
  - **`endosso`**: `string`, maxLength: `10`, pattern: `([a-zA-Z])`
  - **`finalSeguroApoliceAnterior`**: `string`, maxLength: `10`, pattern: `([0-9\/])`
- **`seguradora`**: `string`, maxLength: `4`, pattern: `([0-9])`

  Código da seguradora
- **`seguro`**: `string`, maxLength: `4`, pattern: `([0-9])`

  Código identificador da oferta de seguro. Este código deverá ser informado na etapa de proposta para emissão da apólice conforme escolha do segurado. 0001 = Essencial 12; 0005 = Essencial 30; 0007 = Essencial 20; 0008 = Fiança.
- **`status`**: `string`, maxLength: `1`, pattern: `([0-9A-Z])`

  Status da proposta (código - significado):
  | Código | Significado                      |
  | ------ | -------------------------------- |
  | 0      | ORÇAMENTO CRIADO                 |
  | 1      | ORÇAMENTO APROVADO               |
  | 2      | ORÇAMENTO                        |
  | 3      | PROPOSTA EM DIGITAÇÃO            |
  | 4      | EMITIDA                          |
  | 5      | PRÉ-APROVADO                     |
  | 7      | AGUARDANDO PAGAMENTO             |
  | 9      | RECUSADA                         |
  | C      | ANÁLISE DE CRÉDITO               |
  | D      | DEVOLVIDO                        |
  | E      | EXPIRADA                         |
  | F      | AGUARDANDO ENVIO MESA DE CRÉDITO |
  | G      | PENDENCIADO EMISSÃO              |
  | M      | ANÁLISE EMISSÃO                  |
- **`sucursal`**: `string`, maxLength: `1`, pattern: `([0-9])`

  Código da Sucursal
- **`susep`**: `string`, minLength: `6`, maxLength: `6`, pattern: `([A-Z0-9])`

  Código susep da corretora responsável pelo orçamento.
- **`tipoRecusa`**: `string`, maxLength: `1`, pattern: `([0-9])`

  2 - quando for a recusa especial (dossiê),  1 - demais recusas (genérico)
- **`tipoSeguro`**: `string`, maxLength: `3`, pattern: `([0-9])`

  Código do tipo do seguro

**Example:**

```json
{
  "tipoSeguro": "001",
  "descontosAgravos": [],
  "coberturas": [],
  "modalidade": "004",
  "tipoRecusa": "1",
  "dataAceitacao": "01/01/2021",
  "seguro": "0008",
  "contrato": null,
  "localRisco": null,
  "beneficios": [],
  "orcamento": "000000010009512",
  "dataCriacao": "07/04/2021",
  "nomeSeguradora": "PORTO SEGURO",
  "cobranca": null,
  "cocorretagens": null,
  "origem": "22",
  "ramo": "0746",
  "susep": "COL10J",
  "cpfCotador": "94814209495",
  "status": "4",
  "nomeSeguro": "FIANCA ESSENCIAL 12",
  "corretor": null,
  "formasPagamento": null,
  "renovacao": null,
  "calculo": null,
  "proposta": "000000010008275",
  "regraFixa": false,
  "nomeModalidade": "MODALIDADE 004",
  "sucursal": "1",
  "seguradora": "0001",
  "orcamentoExterno": "AJDI736",
  "nomeRamo": "RAMO 746",
  "parceiro": null,
  "locatarios": [],
  "ofertas": [],
  "apolice": null,
  "endosso": "0000"
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

- `DescontosAgravos` — shown above.
- `Beneficios` — shown above.
- `CartaoCredito` — shown above.
- `Cobranca` — shown above.
- `Cocorretagens` — shown above.
- `CalculoCanonico` — shown above.
- `LocalRiscoCanonico` — shown above.
- `PepCanonico` — shown above.
- `ContratoCanonico` — shown above.
- `ParceirosCanonico` — shown above.
- `LocatariosCanonico` — shown above.
- `OfertasCanonico` — shown above.
- `CoberturaCanonico` — shown above.
- `ApoliceCanonico` — shown above.
- `CanonicoOrcamento` — shown above.
- `renovacaoCanonico` — shown above.
- `FormaPagamentoCanonico` — shown above.
- `CondicaoPagamentoCanonico` — shown above.
- `CorretorCanonico` — shown above.

### Unauthorized

**Type:** `string`, maxLength: `50`, pattern: `([A-Z])`

**Example:**

```json
"Could not find a required Access Token in the request, identified by HEADER access_token"
```

- `ProtheusError` — shown above.
- `CriticaGeracaoPDF` — shown above.

### BadGateway

**Type:** `string`, maxLength: `50`, pattern: `([A-Z])`

**Example:**

```json
"Error forwarding call to back-end server"
```

# Atualiza os dados de um orçamento do produto Fiança Essencial

- **Method:** `PUT`
- **Path:** `/orcamentos/{orcamento}/{susep}`
- **Tags:** Orçamento e Emissão

Quando sucesso, retorna status 200 (HTTP Status Code) com uma mensagem informando o sucesso na atualização dos dados.

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

## Request body

Parâmetros para atualização do orçamento.

### ⚠️ Atenção sobre o atributo `diaVencimento`:

Corresponde ao *dia de vencimento da fatura*, para as formas de pagamento: Fatura com entrada, Fatura sem entrada ou Aluguel Garantido.  Este campo é atualizado automaticamente com base no cadastro da imobiliária.

Para as formas de cobrança *Fatura com entrada* e *Fatura sem entrada* este atributo `não` pode ser alterado.

Para a forma de pagamento *Aluguel Garantido*, este atributo refere-se ao dia de vencimento da fatura que será cobrada do cliente (locatário). Neste caso, é `obrigatório` e pode ser alterado no PUT, seguindo as seguintes opções de preenchimento: *5, 10, 15, 20, 25* e *30*.

**Required:** `true`

**Content type:** `application/json`

- **`cobranca` (required)**: `object`, schema: `Cobranca`

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
- **`locador` (required)**: `object`, schema: `Locador`
  - **`cpfCnpj` (required)**: `string`, maxLength: `14`, pattern: `([0-9])`

    CPF ou CNPJ do locador. Deve conter 11 digitos numéricos para CPF's ou 14 digitos numéricos para CNPJ's , sem máscaras ou caracteres especiais e caso tenha menos caracteres preencher com zeros à esquerda. *Campo obrigatório*.
  - **`email` (required)**: `string`, maxLength: `30`, pattern: `([0-9A-Za-z\.\@\_])`

    É o endereço de e-mail do locador. É composto por no máximo 64 caracteres, deve conter um @, um . após o @, ao menos 5 caracteres antes do @, não deve terminar com um caracter especial, não deve conter nenhum caracter especial além do arroba, hífen e underline/underscore, não deve conter espaços. *Campo obrigatório*.
  - **`enderecoPrincipal` (required)**: `object`, schema: `Endereco`

    Dados do endereço da pessoa
    - **`bairro` (required)**: `string`, maxLength: `30`, pattern: `([A-Za-z])`

      Bairro do endereço da pessoa. É composto por até 40 caracteres. Campo obrigatório.
    - **`cep` (required)**: `string`, maxLength: `7`, pattern: `([0-9])`

      Código do CEP do endereço, composto por 8 caracteres numéricos, sem máscaras, sem caracteres especiais e sem espaços. Para CEPs com menos de 8 digitos preencher com zeros à esquerda. *Campo obrigatório*.
    - **`estado` (required)**: `string`, possible values: `"AC", "AL", "AM", "AP", "BA", "CE", "DF", "ES", "GO", "MA", "MG", "MS", "MT", "PA", "PB", "PE", "PI", "PR", "RJ", "RN", "RO", "RR", "RS", "SC", "SE", "SP", "TO"`, maxLength: `2`, pattern: `([A-Za-z])`

      Sigla do estado do endereço da pessoa. É composto por 2 letras maiúsculas. *Campo obrigatório* e conforme lista abaixo:
      - AC - Acre
      - AL - Alagoas
      - AM - Amazonas
      - AP - Amapá
      - BA - Bahia
      - CE - Ceará
      - DF - Distrito Federal
      - ES - Espírito Santo
      - GO - Goiás
      - MA - Maranhão
      - MG - Minas Gerais
      - MS - Mato Grosso do Sul
      - MT - Mato Grosso
      - PA - Pará
      - PB - Paraíba
      - PE - Pernambuco
      - PI - Piauí
      - PR - Paraná
      - RJ - Rio de Janeiro
      - RN - Rio Grande do Norte
      - RO - Rondônia
      - RR - Roraima
      - RS - Rio Grande do Sul
      - SC - Santa Catarina
      - SE - Sergipe
      - SP - São Paulo
      - TO - Tocantins
    - **`logradouro` (required)**: `string`, maxLength: `30`, pattern: `([A-Za-z])`

      Nome do logradouro do endereço da pessoa. É composto por até 40 caracteres. *Campo obrigatório*.
    - **`municipio` (required)**: `string`, maxLength: `30`, pattern: `([A-Za-z])`

      Cidade do endereço da pessoa. É composto por até 30 caracteres. Campo obrigatório.
    - **`numero` (required)**: `string`, maxLength: `5`, pattern: `([0-9A-Za-z])`

      Número do endereço da pessoa. É composto por até 6 caracteres. Caso o endereço não possua número, informar SN. *Campo obrigatório*.
    - **`tipoLogradouro` (required)**: `string`, maxLength: `3`, pattern: `([A-Za-z])`

      Tipo logradouro do endereço da pessoa. Composto por até 3 letras. *Campo obrigatório* e conforme lista abaixo:
      - A - AREA
      - AER - AEROPORTO
      - AL - ALAMEDA
      - AV - AVENIDA
      - CH - CHACARA
      - CJ - CONJUNTO
      - COL - COLONIA
      - CON - CONDOMINIO
      - CPO - CAMPO
      - DT - DISTRITO
      - ESP - ESPLANADA
      - EST - ESTRADA
      - ETC - ESTACAO
      - FAV - FAVELA
      - FAZ - FAZENDA
      - FRA - FEIRA
      - JD - JARDIM
      - LD - LADEIRA
      - LG - LAGO
      - LGA - LAGOA
      - LOT - LOTEAMENTO
      - LRG - LARGO
      - MRO - MORRO
      - NUC - NUCLEO
      - O - OUTROS
      - PAT - PATIO
      - PC - PRACA
      - PRQ - PARQUE
      - PSA - PASSARELA
      - Q - QUADRA
      - R - RUA
      - REC - RECANTO
      - RES - RESIDENCIAL
      - ROD - RODOVIA
      - SIT - SITIO
      - ST - SETOR
      - TR - TRECHO
      - TRV - TREVO
      - TV - TRAVESSA
      - V - VIA
      - VD - VIADUTO
      - VER - VEREDA
      - VL - VILA
      - VLA - VIELA
      - VLE - VALE
    - **`complemento`**: `string`, maxLength: `30`, pattern: `([A-Za-z0-9])`

      Complemento do endereço da pessoa. É composto por até 20 caracteres. *Campo opcional*.
  - **`nome` (required)**: `string`, maxLength: `30`, pattern: `([A-Za-z])`

    Nome e sobrenome ou razão social do locador do imóvel. É composto por no mínimo 5 caracteres, no máximo 64 caracteres, pelo menos 1 espaço entre o nome e sobrenome e sem caracteres especiais. Campo obrigatório.
  - **`dataNascimento`**: `string`, maxLength: `10`, pattern: `([0-9\/])`

    Data de nascimento do locador no formato DD/MM/AAAA. *Campo obrigatório* se locador for pessoa física.
  - **`enderecoCobranca`**: `object`, schema: `Endereco`

    Dados do endereço da pessoa

    *Schema `Endereco` is shown above.*
  - **`mae`**: `string`, maxLength: `30`, pattern: `([A-Za-z])`

    Nome da mãe do locador. É composto por no mínimo 5 caracteres, no máximo 60 caracteres, pelo menos 1 espaço entre o nome e sobrenome e sem caracteres especiais. *Campo opcional* e utilizado apenas se o locador for pessoa física.
  - **`nomeSocial`**: `string`, maxLength: `60`, pattern: `([A-Za-z])`

    Nome social do pretendente / locatário / inquilino. *Campo opcional*.
  - **`pep`**: `object`, schema: `Pep`

    Dados relacionados à pessoa exposta politicamente (PEP). São PEP os ocupantes de cargos e funções públicas como por exemplo Deputados e Senadores. *Campo opcional*.
    - **`cpf`**: `string`, maxLength: `11`, pattern: `([0-9])`

      CPF do PEP. Deve conter 11 digitos numéricos, sem máscaras ou caracteres especiais e caso tenha menos que 11 digitos preencher com zeros à esquerda. Obrigatório apenas se o campo relacionamento for informado com um código diferente de 001.
    - **`faixaRenda`**: `string`, maxLength: `3`, pattern: `([0-9])`

      Faixa renda do PEP. É composto por 3 caracteres numéricos preenchidos com zeros à esquerda. É obrigatório se no campo relacionamento for informado o código 001 e conforme lista abaixo:
      - 001 - Sem Renda
      - 002 - Até R$2.500,00
      - 003 - De R$2.500,01 até R$5.000,00
      - 004 - De R$5.000,01 até R$10.000,00
      - 005 - Acima de R$10.000,01
    - **`nacionalidade`**: `string`, possible values: `1, 2`, maxLength: `3`, pattern: `([0-9])`

      Nacionalidade do PEP. É composto por 3 caracteres numéricos preenchidos com zeros à esquerda. Disponível apenas se no campo relacionamento for informado o código 001. Se este campo não for informado, ficará gravado que o PEP é brasileiro. *Campo opcional*.
      - 001 - Brasileira
      - 002 - Estrangeira
    - **`nome`**: `string`, maxLength: `30`, pattern: `([A-Za-z])`

      Nome e sobrenome do PEP. É composto por no mínimo 5 caracteres, no máximo 60 caracteres, pelo menos 1 espaço entre o nome e sobrenome e sem caracteres especiais. Obrigatório apenas se o campo relacionamento for informado com um código diferente de 001.
    - **`nomeSocial`**: `string`, maxLength: `60`, pattern: `([A-Za-z])`

      Nome social do pretendente / locatário / inquilino. *Campo opcional*.
    - **`paisResidencia`**: `string`, default: `"01058"`, maxLength: `5`, pattern: `([0-9])`

      País de residência do PEP. É composto por 5 caracteres numéricos preenchidos com zeros à esquerda. Disponível apenas se no campo relacionamento for informado o código 001. Se este campo não for informado, ficará gravado que o PEP é residente no Brasil (código 01058). Para obter este código, Baixe o json com os valores para preenchimento no link <https://firebasestorage.googleapis.com/v0/b/riscosfinanceiros-hml-download/o/documentacao%2Fpaises-response.json?alt=media&token=6ad9126a-606e-41db-b99e-58557f91c409>. *Campo opcional*.
    - **`profissao`**: `string`, maxLength: `4`, pattern: `([0-9])`

      Código da profissão conforme Cadastro Brasileiro de Ocupações (CBO) que é utilizado pela receita federal. Buscar a informação direto na receita federal. É composto por 4 caracteres numéricos preenchidos com zeros à esquerda, sem máscaras, sem espaços e sem caracteres especiais. *Campo opcional*.
  - **`telefoneCelular`**: `string`, maxLength: `11`, pattern: `([0-9])`

    Número do telefone celular do locador, incluindo o código DDD. É composto por 11 caracteres numéricos, sendo os dois primeiros o código DDD e os demais o número do celular, preenchido com zeros à esquerda, sem máscaras, sem caracteres especiais ou espaços. *Campo obrigatório* para pessoa física.
  - **`telefoneComercial`**: `string`, maxLength: `11`, pattern: `([0-9])`

    Número do telefone comercial do locador, incluindo o código DDD. É composto por 10 caracteres numéricos, sendo os dois primeiros o código DDD e os demais o número do telefone, preenchido com zeros à esquerda, sem máscaras, sem caracteres especiais ou espaços. *Campo obrigatório* para pessoa jurídica.
  - **`telefoneResidencial`**: `string`, maxLength: `11`, pattern: `([0-9])`

    Número do telefone residencial do locador, incluindo o código DDD. É composto por 10 caracteres numéricos, sendo os dois primeiros o código DDD e os demais o número do telefone, preenchido com zeros à esquerda, sem máscaras, sem caracteres especiais ou espaços. *Campo opcional* e utilizado apenas se o locador for pessoa física.
- **`localRisco` (required)**: `object`, schema: `PropostaLocalRisco`

  Dados complementares do imóvel objeto do contrato de locação. Campo obrigatório.
  - **`bairro` (required)**: `string`, maxLength: `40`, pattern: `([A-Z-a-z])`

    Bairro do local de risco da proposta de seguro. É composto por até 40 caracteres. *Campo obrigatório*.
  - **`estado` (required)**: `string`, possible values: `"AC", "AL", "AM", "AP", "BA", "CE", "DF", "ES", "GO", "MA", "MG", "MS", "MT", "PA", "PB", "PE", "PI", "PR", "RJ", "RN", "RO", "RR", "RS", "SC", "SE", "SP", "TO"`, maxLength: `2`, pattern: `([A-Z])`

    Sigla do estado do local de risco da proposta de seguro. É composto por 2 letras maiúsculas. *Campo obrigatório* e conforme lista abaixo:
    - AC - Acre
    - AL - Alagoas
    - AM - Amazonas
    - AP - Amapá
    - BA - Bahia
    - CE - Ceará
    - DF - Distrito Federal
    - ES - Espírito Santo
    - GO - Goiás
    - MA - Maranhão
    - MG - Minas Gerais
    - MS - Mato Grosso do Sul
    - MT - Mato Grosso
    - PA - Pará
    - PB - Paraíba
    - PE - Pernambuco
    - PI - Piauí
    - PR - Paraná
    - RJ - Rio de Janeiro
    - RN - Rio Grande do Norte
    - RO - Rondônia
    - RR - Roraima
    - RS - Rio Grande do Sul
    - SC - Santa Catarina
    - SE - Sergipe
    - SP - São Paulo
    - TO - Tocantins
  - **`logradouro` (required)**: `string`, maxLength: `40`, pattern: `([A-Z-a-z])`

    Nome do logradouro do local de risco da proposta de seguro. É composto por até 40 caracteres. *Campo obrigatório*.
  - **`municipio` (required)**: `string`, maxLength: `30`, pattern: `([A-Z-a-z])`

    Cidade do local de risco da proposta de seguro. É composto por até 30 caracteres. *Campo obrigatório*.
  - **`numero` (required)**: `string`, maxLength: `6`, pattern: `([0-9A-Z])`

    Número do endereço do local de risco da proposta de seguro. É composto por até 6 caracteres. Caso o endereço não possua número, informar SN. *Campo obrigatório*.
  - **`tipoLogradouro` (required)**: `string`, maxLength: `3`, pattern: `([A-Z])`

    Tipo logradouro do local de risco da proposta de seguros. Composto por até 3 letras. *Campo obrigatório* e conforme lista abaixo:
    - A - AREA
    - AER - AEROPORTO
    - AL - ALAMEDA
    - AV - AVENIDA
    - CH - CHACARA
    - CJ - CONJUNTO
    - COL - COLONIA
    - CON - CONDOMINIO
    - CPO - CAMPO
    - DT - DISTRITO
    - ESP - ESPLANADA
    - EST - ESTRADA
    - ETC - ESTACAO
    - FAV - FAVELA
    - FAZ - FAZENDA
    - FRA - FEIRA
    - JD - JARDIM
    - LD - LADEIRA
    - LG - LAGO
    - LGA - LAGOA
    - LOT - LOTEAMENTO
    - LRG - LARGO
    - MRO - MORRO
    - NUC - NUCLEO
    - O - OUTROS
    - PAT - PATIO
    - PC - PRACA
    - PRQ - PARQUE
    - PSA - PASSARELA
    - Q - QUADRA
    - R - RUA
    - REC - RECANTO
    - RES - RESIDENCIAL
    - ROD - RODOVIA
    - SIT - SITIO
    - ST - SETOR
    - TR - TRECHO
    - TRV - TREVO
    - TV - TRAVESSA
    - V - VIA
    - VD - VIADUTO
    - VER - VEREDA
    - VL - VILA
    - VLA - VIELA
    - VLE - VALE
  - **`complemento`**: `string`, maxLength: `20`, pattern: `([0-9A-Z-a-z])`

    Complemento do local de risco da proposta de seguro. É composto por até 20 caracteres. *Campo opcional*.
- **`locatarios` (required)**: `array`

  **Items:**

  schema: `PropostaLocatario`

  Lista dos locatários / inquilinos. Aqui você deve informar os dados cadastrais dos CPF's inclusos anteriormente na API de orçamento e não é permitido a inclusão ou exclusão de CPF's. Caso necessite incluir um novo locatário é necessário executar novamente a etapa de orçamento e passar por nova análise cadastral. *Campo obrigatório*.
  - **`cpfCnpj` (required)**: `string`, maxLength: `11`, pattern: `([0-9])`

    CPF do pretendente / locatário / inquilino informado na API de orçamento. Deve conter 11 digitos numéricos, sem máscaras ou caracteres especiais e caso tenha menos que 11 digitos preencher com zeros à esquerda. *Campo obrigatório*.
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
  - **`email` (required)**: `string`, minLength: `5`, maxLength: `30`, pattern: `([A-Za-z0-9\.\!\@\-])`

    É o endereço de e-mail do locatário. É composto por no máximo 64 caracteres, deve conter um @, um . após o @, ao menos 5 caracteres antes do @, não deve terminar com um caracter especial, não deve conter nenhum caracter especial além do arroba, hífen e underline/underscore, não deve conter espaços. *Campo opcional*, que deverá a partir de agora ser enviado ainda no orçamento.
  - **`nome` (required)**: `string`, minLength: `5`, maxLength: `60`, pattern: `([A-Za-z])`

    Nome e sobrenome do pretendente / locatário / inquilino. É composto por no mínimo 5 caracteres, no máximo 60 caracteres, pelo menos 1 espaço entre o nome e sobrenome e sem caracteres especiais. Campo obrigatório.
  - **`telefoneCelular` (required)**: `string`, maxLength: `11`, pattern: `([0-9])`

    Número do telefone celular do locatário, incluindo o código DDD. É composto por 11 caracteres numéricos, sendo os dois primeiros o código DDD e os demais o número do celular, preenchido com zeros à esquerda, sem máscaras, sem caracteres especiais ou espaços. *Campo opcional*, que deverá a partir de agora ser enviado ainda no orçamento.
  - **`titular` (required)**: `string`, possible values: `"S", "N"`, maxLength: `1`, pattern: `([A-Z])`

    Campo que identifica o titular do orçamento. É composto por 1 caracter maiúsculo, sendo 'S' para afirmar que o CPF é o titular e 'N' para afirmar que não é o titular. *Campo obrigatório*.
  - **`enderecoAtual`**: `object`, schema: `LocatarioEnderecoAtual`

    Endereço atual para preenchimento em contrato de locação comercial. Objeto obrigatório para locação comercial (PFCOM).
    - **`bairro` (required)**: `string`, maxLength: `40`, pattern: `([A-Z-a-z])`

      Bairro do local de risco da proposta de seguro. É composto por até 40 caracteres. *Campo obrigatório*.
    - **`cep` (required)**: `string`, maxLength: `8`, pattern: `([0-9])`

      Código do CEP do imóvel, composto por 8 caracteres numéricos, sem máscaras, sem caracteres especiais e sem espaços. Para CEPs com menos de 8 digitos, utilizar zeros à esquerda. A Validaçào será feita utilizando as bases disponibilizadas pelos Correios, incluindo CEPS's genéricos', CEPs de cidades e CEP's especiais. *Campo obrigatório*.
    - **`condicaoImovel` (required)**: `string`, minLength: `1`, maxLength: `1`, pattern: `([0-5])`

      Condição do imóvel atual dentro das opções disponíveis:
      - 1 - Alugado em nome do pretendente
      - 2 - Alugado em nome de terceiros
      - 3 - Financiado pelo pretendente
      - 4 - Financiado em nome de terceiros
      - 5 - Próprio
    - **`estado` (required)**: `string`, possible values: `"AC", "AL", "AM", "AP", "BA", "CE", "DF", "ES", "GO", "MA", "MG", "MS", "MT", "PA", "PB", "PE", "PI", "PR", "RJ", "RN", "RO", "RR", "RS", "SC", "SE", "SP", "TO"`, maxLength: `2`, pattern: `([A-Z])`

      Sigla do estado do local de risco da proposta de seguro. É composto por 2 letras maiúsculas. *Campo obrigatório* e conforme lista abaixo:
      - AC - Acre
      - AL - Alagoas
      - AM - Amazonas
      - AP - Amapá
      - BA - Bahia
      - CE - Ceará
      - DF - Distrito Federal
      - ES - Espírito Santo
      - GO - Goiás
      - MA - Maranhão
      - MG - Minas Gerais
      - MS - Mato Grosso do Sul
      - MT - Mato Grosso
      - PA - Pará
      - PB - Paraíba
      - PE - Pernambuco
      - PI - Piauí
      - PR - Paraná
      - RJ - Rio de Janeiro
      - RN - Rio Grande do Norte
      - RO - Rondônia
      - RR - Roraima
      - RS - Rio Grande do Sul
      - SC - Santa Catarina
      - SE - Sergipe
      - SP - São Paulo
      - TO - Tocantins
    - **`logradouro` (required)**: `string`, maxLength: `40`, pattern: `([A-Z-a-z])`

      Nome do logradouro do local de risco da proposta de seguro. É composto por até 40 caracteres. *Campo obrigatório*.
    - **`municipio` (required)**: `string`, maxLength: `30`, pattern: `([A-Z-a-z])`

      Cidade do local de risco da proposta de seguro. É composto por até 30 caracteres. *Campo obrigatório*.
    - **`numero` (required)**: `string`, maxLength: `6`, pattern: `([0-9A-Z])`

      Número do endereço do local de risco da proposta de seguro. É composto por até 6 caracteres. Caso o endereço não possua número, informar SN. *Campo obrigatório*.
    - **`tipoLogradouro` (required)**: `string`, maxLength: `3`, pattern: `([A-Z])`

      Tipo logradouro do local de risco da proposta de seguros. Composto por até 3 letras. *Campo obrigatório* e conforme lista abaixo:
      - A - AREA
      - AER - AEROPORTO
      - AL - ALAMEDA
      - AV - AVENIDA
      - CH - CHACARA
      - CJ - CONJUNTO
      - COL - COLONIA
      - CON - CONDOMINIO
      - CPO - CAMPO
      - DT - DISTRITO
      - ESP - ESPLANADA
      - EST - ESTRADA
      - ETC - ESTACAO
      - FAV - FAVELA
      - FAZ - FAZENDA
      - FRA - FEIRA
      - JD - JARDIM
      - LD - LADEIRA
      - LG - LAGO
      - LGA - LAGOA
      - LOT - LOTEAMENTO
      - LRG - LARGO
      - MRO - MORRO
      - NUC - NUCLEO
      - O - OUTROS
      - PAT - PATIO
      - PC - PRACA
      - PRQ - PARQUE
      - PSA - PASSARELA
      - Q - QUADRA
      - R - RUA
      - REC - RECANTO
      - RES - RESIDENCIAL
      - ROD - RODOVIA
      - SIT - SITIO
      - ST - SETOR
      - TR - TRECHO
      - TRV - TREVO
      - TV - TRAVESSA
      - V - VIA
      - VD - VIADUTO
      - VER - VEREDA
      - VL - VILA
      - VLA - VIELA
      - VLE - VALE
    - **`aluguelAtual`**: `integer`, format: `int32`, minimum: `0`, maximum: `99999999`, pattern: `([0-9])`

      Valor do aluguel atual em caso de imóvel atual em condição de alugado.
    - **`complemento`**: `string`, maxLength: `20`, pattern: `([0-9A-Z-a-z])`

      Complemento do local de risco da proposta de seguro. É composto por até 20 caracteres. *Campo opcional*.
  - **`mae`**: `string`, maxLength: `60`, pattern: `([A-Za-z])`

    Nome da mãe do locatário. É composto por no mínimo 5 caracteres, no máximo 60 caracteres, pelo menos 1 espaço entre o nome e sobrenome e sem caracteres especiais. *Campo opcional*.
  - **`nomeSocial`**: `string`, maxLength: `60`, pattern: `([A-Za-z])`

    Nome social do pretendente / locatário / inquilino. *Campo opcional*.
  - **`pep`**: `object`, schema: `Pep`

    Dados relacionados à pessoa exposta politicamente (PEP). São PEP os ocupantes de cargos e funções públicas como por exemplo Deputados e Senadores. *Campo opcional*.

    *Schema `Pep` is shown above.*
  - **`sexo`**: `string`, possible values: `"M", "F", "I"`, default: `"I"`, maxLength: `1`, pattern: `([A-Z])`

    Sexo / gênero do locatário. É composto por um caractere maiúsculo. *Campo opcional*.
    Opções disponíveis
    - M - Masculino
    - F - Feminino
    - I - Indefinido
  - **`telefoneComercial`**: `string`, maxLength: `11`, pattern: `([0-9])`

    Número do telefone comercial do locatário, incluindo o código DDD. É composto por 10 caracteres numéricos, sendo os dois primeiros o código DDD e os demais o número do telefone, preenchido com zeros à esquerda, sem máscaras, sem caracteres especiais ou espaços. *Campo opcional*.
  - **`telefoneResidencial`**: `string`, maxLength: `11`, pattern: `([0-9])`

    Número do telefone residencial do locatário, incluindo o código DDD. É composto por 10 caracteres numéricos, sendo os dois primeiros o código DDD e os demais o número do telefone, preenchido com zeros à esquerda, sem máscaras, sem caracteres especiais ou espaços. *Campo opcional*.
- **`seguro` (required)**: `string`, maxLength: `4`, pattern: `([0-9])`

  Código identificador da oferta de seguro. Este código deverá ser informado na etapa de proposta para emissão da apólice conforme escolha do segurado. 0001 = Essencial 12; 0005 = Essencial 30; 0007 = Essencial 20; 0008 = Fiança.
- **`diaVencimento`**: `integer`, maxLength: `2`, pattern: `([0-9])`

  Corresponde ao dia de vencimento da fatura, para as formas de pagamento: Fatura com entrada, Fatura sem entrada ou Aluguel Garantido.  Este campo é atualizado automaticamente com base no cadastro da imobiliária.

  ⚠️Para as formas de cobrança *Fatura com entrada* e *Fatura sem entrada* este atributo não pode ser alterado.

  ⚠️Para a forma de pagamento *Aluguel Garantido*, este atributo refere-se ao dia de vencimento da fatura que será cobrada do cliente (locatário). Neste caso, é obrigatório e pode ser alterado no PUT, seguindo as seguintes opções de preenchimento: *5, 10, 15, 20, 25* e *30*.
- **`diaVencimentoAluguel`**: `string`, minLength: `2`, maxLength: `2`, pattern: `([0-9])`

  Dia do vencimento do aluguel estipulado no contrato de locação.
- **`ofertas`**: `array`

  O objeto ofertas, utilizado no endpoint POST /orcamento, faz com que apenas o seguro selecionado seja exibido na resposta. Por exemplo, se o seguro escolhido for o código 0008, os demais seguros serão ocultados no response. Recomenda-se o uso desse objeto no endpoint PUT /orcamentos/{orcamento}/{susep}

  **Items:**
  - **`comissao`**: `object`
    - **`codComissao`**: `integer`, format: `int32`, minimum: `0`, maximum: `100`, pattern: `([0-9])`

      Código de comissionamento em porcentagem. A comissão é sempre calculada padrão em 10%, tendo a opção de alterar para o valor desejado.
  - **`descontos`**: `array`

    **Items:**
    - **`id`**: `integer`, format: `int32`, minimum: `0`, maximum: `100`, pattern: `([0-9])`

      Código para indicar a solicitação do desconto de acordo com as opções abaixo:
      id 2: Desconto de Negociação
      id 3: Desconto Técnico Promocional
      id 5: Desconto Técnico por Acordo
    - **`maximo`**: `integer`, format: `int32`, minimum: `0`, maximum: `100`, pattern: `([0-9])`

      Utilizar o valor '1' como default.
    - **`minimo`**: `integer`, format: `int32`, minimum: `0`, maximum: `100`, pattern: `([0-9])`

      Utilizar o valor '0' como default.
    - **`tipoAplicacao`**: `integer`, format: `int32`, minimum: `0`, maximum: `100`, pattern: `([0-9])`

      Código para indicar o tipo de aplicação que será usado. 1 = Percentual 2 = Valor
    - **`valorInformado`**: `integer`, format: `int32`, minimum: `0`, maximum: `100`, pattern: `([0-9])`

      Utilizar o valor '0' como default.
  - **`seguro`**: `string`, maxLength: `4`, pattern: `([0-9])`

    Código identificador da oferta de seguro. Este código deverá ser informado na etapa de proposta para emissão da apólice conforme escolha do segurado. 0001 = Essencial 12; 0005 = Essencial 30; 0007 = Essencial 20; 0008 = Fiança
- **`orcamentoExterno`**: `string`, maxLength: `32`, pattern: `([A-Za-z0-9\/\-\.])`

  Número ou código do orçamento do sistema do parceiro caso exista, composto por até 32 caracteres com números ou letras maiúsculas / minúsculas. *Campo opcional*.

**Example:**

```json
{
  "orcamentoExterno": "SD3lo43.5565865A/JDI7-36",
  "seguro": "0008",
  "diaVencimento": 10,
  "diaVencimentoAluguel": "01",
  "cobranca": {
    "formaPagamento": "CC",
    "condicaoPagamento": "000",
    "cartaoCredito": null
  },
  "ofertas": [
    {
      "seguro": "0008",
      "comissao": {
        "codComissao": 10
      },
      "descontos": [
        {
          "id": 2,
          "tipoAplicacao": 1,
          "minimo": 0,
          "maximo": 1,
          "valorInformado": 0
        }
      ]
    }
  ],
  "locador": {
    "cpfCnpj": "77696561090",
    "nome": "José da Silva",
    "nomeSocial": "Haley",
    "dataNascimento": "10/10/1970",
    "mae": "Maria da Silva",
    "email": "locador.imovel@gmail.com",
    "telefoneCelular": "11998467305",
    "telefoneComercial": "1141237758",
    "telefoneResidencial": "1123993695",
    "enderecoPrincipal": null,
    "enderecoCobranca": null,
    "pep": null
  },
  "localRisco": {
    "tipoLogradouro": "AV",
    "logradouro": "TIRADENTES",
    "numero": "33",
    "complemento": "BL 5 APTO 3",
    "bairro": "Vila Assunção",
    "municipio": "SAO PAULO",
    "estado": "SC"
  },
  "locatarios": [
    {
      "cpfCnpj": "43988733091",
      "titular": "S",
      "nome": "João Antônio da Silva",
      "nomeSocial": "Haley",
      "dataNascimento": "07/02/1947",
      "sexo": "M",
      "mae": "DEA TONELLI QUARANTA",
      "email": "jose.antonio-01@gmail.com",
      "telefoneCelular": "11998467395",
      "telefoneComercial": "1141231234",
      "telefoneResidencial": "1141239635",
      "pep": null,
      "enderecoAtual": null,
      "dadosProfissionais": null
    }
  ],
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

### 200 Operação executada com sucesso. Os dados do orçamento foram atualizados.

**Content type:** `application/json`

- **`calculo`**: `object`

  Detalhamento dos valores calculados.
  - **`adicionalFracionamento`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

    Valor dos juros calculado no orçamento (adicional de fracionamento).
  - **`custoApolice`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

    Valor do custo de emissão da apólice de seguros calculado no orçamento.
  - **`premioBruto`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, maxLength: `7`, pattern: `([0-9])`

    É o valor do prêmio liquido do orçamento, com o IOF e sem a aplicação de descontos, nesta forma e condição de pagamento.
  - **`premioTarifario`**: `integer`, format: `int32`, pattern: `([0-9])`
  - **`premioTotal`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, maxLength: `7`, pattern: `([0-9])`

    É o valor total do orçamento a ser pago pelo segurado, já considerando o valor do IOF e aplicando os valores de descontos, nesta forma e condição de pagamento.
  - **`taxaJuros`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

    Percentual da taxa de juros utilizado no cálculo do orçamento.
  - **`taxaReativacao`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

    Taxa reativação da apólice calculada no orçamento.
  - **`valorIS`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, maxLength: `7`, pattern: `([0-9])`

    Valor total da importância segurada calculada no orçamento.
  - **`valorLMG`**: `integer`, format: `int32`, minimum: `100`, maximum: `10000`, pattern: `([0-9])`

    Limite máximo de indenização para a apólice calculada no orçamento.
- **`diaCorte`**: `integer`, format: `int32`, pattern: `([0-9])`

  Dia limite para processamento da fatura.
- **`mensagem`**: `string`

  Mensagem de confirmação de sucesso.
- **`status`**: `string`, format: `binary`, maxLength: `1`, pattern: `([0-9A-Z])`

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

**Example:**

```json
{
  "mensagem": "DADOS SALVOS COM SUCESSO",
  "status": "4",
  "diaCorte": 31,
  "calculo": {
    "custoApolice": 0,
    "valorLMG": 200,
    "valorIS": 200,
    "taxaReativacao": 30,
    "taxaJuros": 0,
    "adicionalFracionamento": 0,
    "premioBruto": 1728,
    "premioTotal": 1728,
    "premioTarifario": 0
  }
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

### 409 Recusa na aceitação

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

### 502 Status 502 indica alguma indisponibilidade sistêmica, seja por mudanças agendadas ou incidentes.&#x20;

**Content type:** `application/json`

`string`, maxLength: `50`, pattern: `([A-Z])`

**Example:**

```json
"Error forwarding call to back-end server"
```

## Schemas

- `PropostaLocatario` — shown above.
- `DadosProfissionais` — shown above.
- `PropostaLocalRisco` — shown above.
- `LocatarioEnderecoAtual` — shown above.
- `LocacaoComercial` — shown above.
- `CartaoCredito` — shown above.
- `Cobranca` — shown above.
- `Endereco` — shown above.
- `Locador` — shown above.
- `Pep` — shown above.

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

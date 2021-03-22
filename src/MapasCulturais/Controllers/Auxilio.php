<?php

namespace MapasCulturais\Controllers;

use DateTime;
use Normalizer;
use MapasCulturais\App;
use MapasCulturais\i;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\SecultCEPayment;
use MapasCulturais\Controllers\EntityController;

class Auxilio extends EntityController {

  public function __construct() {
    parent::__construct();

    $app = App::i();

    $this->entityClassName = "\MapasCulturais\Entities\Auxilio";
    $this->config = $app->plugins['RegistrationPaymentsAuxilio']->config;
  }

    /**
   * Retorna a oportunidade
   * 
   * @return \MapasCulturais\Entities\Opportunity 
   * @throws Exception 
   */
  private function getOpportunity() {
    $app = App::i();
    
    $opportunity_id = $this->data['opportunity'];

    /**
     * Pega informações da oportunidade
     */
    $opportunity = $app->repo('Opportunity')->find($opportunity_id);
    $this->registerRegistrationMetadata($opportunity);

    if (!$opportunity->canUser('@control')) {
        echo "Não autorizado";
        die();
    }

    return $opportunity;
  }

  private function normalizeString($valor): string {
      $valor = Normalizer::normalize($valor, Normalizer::FORM_D);
      return preg_replace('/[^A-Za-z0-9 ]/i', '', $valor);
  }
  
  private function generateCnab240($payments) {
      ini_set('max_execution_time', 0);
      ini_set('memory_limit', '768M');

      /**
       * Verifica se o usuário está autenticado
       */
      $this->requireAuthentication();
      $app = App::i();

      //Captura se deve ser gerado um arquivo do tipo teste
      $typeFile =  null;
      if(isset($this->data['typeFile'])){
          $typeFile = $this->data['typeFile'];
      }

      $opportunity = $this->getOpportunity();
      $opportunity_id = $opportunity->id;
      // $registrations = $this->getRegistrations($opportunity);
      $registrations = $payments;
      $parametersForms = $this->getParametersForms();
      
      /**
       * Pega os dados das configurações
       */
      $txt_config = $this->config['config-cnab240'];
      $default = $txt_config['parameters_default'];           
      $header1 = $txt_config['HEADER1'];
      $header2 = $txt_config['HEADER2'];
      $detahe1 = $txt_config['DETALHE1'];
      $detahe2 = $txt_config['DETALHE2'];
      $trailer1 = $txt_config['TRAILER1'];
      $trailer2 = $txt_config['TRAILER2'];
      $fromToAccounts = $default['fromToAccounts'];
      $dePara = $this->readingCsvFromTo($fromToAccounts);
      $cpfCsv = $this->cpfCsv($fromToAccounts);       
     
      $mappedHeader1 = [
          'BANCO' => '',
          'LOTE' => '',
          'REGISTRO' => '',
          'USO_BANCO_12' => '',
          'INSCRICAO_TIPO' => '',
          'CPF_CNPJ_FONTE_PAG' => '',
          'CONVENIO_BB1' => '',
          'CONVENIO_BB2' => '',
          'CONVENIO_BB3' => '',
          'CONVENIO_BB4' => function ($registrations) use ($typeFile) {
              if($typeFile == "TS"){
                  return "TS";
              }else{
                  return "";
              }
          }, 
          'AGENCIA' => function ($registrations) use ($header1) {
              $result = "";
              $field_id = $header1['AGENCIA'];
              $value = $this->normalizeString($field_id['default']);
              return substr($value, 0, 4);

          },
          'AGENCIA_DIGITO' => function ($registrations) use ($header1) {
              $result = "";
              $field_id = $header1['AGENCIA_DIGITO'];
              $value = $this->normalizeString($field_id['default']);
              $result = is_string($value) ? strtoupper($value) : $value;
              return $result;

          },
          'CONTA' => function ($registrations) use ($header1) {
              $result = "";
              $field_id = $header1['CONTA'];
              $value = $this->normalizeString($field_id['default']);
              return substr($value, 0, 12);
              

          },
          'CONTA_DIGITO' => function ($registrations) use ($header1) {
              $result = "";
              $field_id = $header1['CONTA_DIGITO'];
              $value = $this->normalizeString($field_id['default']);
              $result = is_string($value) ? strtoupper($value) : $value;
              return $result;

          },
          'USO_BANCO_20' => '',
          'NOME_EMPRESA' => function ($registrations) use ($header1, $app) {
              $result =  $header1['NOME_EMPRESA']['default'];
              return substr($result, 0, 30);
          },
          'NOME_BANCO' => '',
          'USO_BANCO_23' => '',
          'CODIGO_REMESSA' => '',
          'DATA_GER_ARQUIVO' => function ($registrations) use ($detahe1) {
              $date = new DateTime();
              return $date->format('dmY');
          },
          'HORA_GER_ARQUIVO' => function ($registrations) use ($detahe1) {
              $date = new DateTime();
              return $date->format('His');
          },
          'NUM_SERQUNCIAL_ARQUIVO' => '',
          'LAYOUT_ARQUIVO' => '',
          'DENCIDADE_GER_ARQUIVO' => '',
          'USO_BANCO_30' => '',
          'USO_BANCO_31' => '',
      ];

      $mappedHeader2 = [
          'BANCO' => '',
          'LOTE' => '',
          'REGISTRO' => '',
          'OPERACAO' => '',
          'SERVICO' => '',
          'FORMA_LANCAMENTO' => '',
          'LAYOUT_LOTE' => '',
          'USO_BANCO_43' => '',
          'INSCRICAO_TIPO' => '',
          'INSCRICAO_NUMERO' => '',
          'CONVENIO_BB1' => '',
          'CONVENIO_BB2' => '',
          'CONVENIO_BB3' => '',
          'CONVENIO_BB4' => function ($registrations) use ($typeFile) {
              if($typeFile == "TS"){
                  return "TS";
              }else{
                  return "";
              }
          },
          'AGENCIA' => function ($registrations) use ($header2) {
              $result = "";
              $field_id = $header2['AGENCIA'];
              $value = $this->normalizeString($field_id['default']);
              return substr($value, 0, 4);

          },
          'AGENCIA_DIGITO' => function ($registrations) use ($header2) {
              $result = "";
              $field_id = $header2['AGENCIA_DIGITO'];
              $value = $this->normalizeString($field_id['default']);
              $result = is_string($value) ? strtoupper($value) : $value;
              return $result;

          },
          'CONTA' => function ($registrations) use ($header2) {
              $result = "";
              $field_id = $header2['CONTA'];
              $value = $this->normalizeString($field_id['default']);
              return substr($value, 0, 12);
            

          },
          'CONTA_DIGITO' => function ($registrations) use ($header2) {
              $result = "";
              $field_id = $header2['CONTA_DIGITO'];
              $value = $this->normalizeString($field_id['default']);
              $result = is_string($value) ? strtoupper($value) : $value;
              return $result;

          },
          'USO_BANCO_51' => '',
          'NOME_EMPRESA' => function ($registrations) use ($header2, $app) {
              $result =  $header2['NOME_EMPRESA']['default'];
              return substr($result, 0, 30);
          },
          'USO_BANCO_40' => '',
          'LOGRADOURO' => '',
          'NUMERO' => '',
          'COMPLEMENTO' => '',
          'CIDADE' => '',
          'CEP' => '',
          'ESTADO' => '',
          'USO_BANCO_60' => '',
          'USO_BANCO_61' => '',
      ];

      $mappedDeletalhe1 = [
          'BANCO' => '',
          'LOTE' => '',
          'REGISTRO' => '',
          'NUMERO_REGISTRO' => '',
          'SEGMENTO' => '',
          'TIPO_MOVIMENTO' => '',
          'CODIGO_MOVIMENTO' => '',
          'CAMARA_CENTRALIZADORA' => function ($registrations) use ($detahe1) {

              //Verifica se existe o medadado se sim pega o registro
              if(!($bank = $this->bankData($registrations, 'bank-number'))){
                  $field_id = $detahe1['BEN_CODIGO_BANCO']['field_id'];
                  $numberBank = $this->numberBank($registrations->$field_id);
                  
              }else{
                  $numberBank = $bank;
              }

              //Se for BB devolve 000 se nao devolve 018
              if($numberBank === "001"){
                  $result = "000";

              }else{
                  $result = "018";
                  
              }
              
              return $result;

          },
          'BEN_CODIGO_BANCO' => function ($registrations) use ($detahe2, $detahe1, $dePara, $cpfCsv) {

              //Verifica se existe o medadado se sim pega o registro
              if(!($bank = $this->bankData($registrations, 'bank-number'))){

                  $field_cpf = $detahe2['BEN_CPF']['field_id'];
                  $cpfBase = preg_replace('/[^0-9]/i', '',$registrations->$field_cpf);
                  
                  $pos = array_search($cpfBase,$cpfCsv);

                  if($pos){                    
                      $result = $dePara[$pos]['BEN_NUM_BANCO'];
                      
                  }else{
                      $field_id = $detahe1['BEN_CODIGO_BANCO']['field_id'];
                      $result = $this->numberBank($registrations->$field_id);
                  }
              }else{
                  $result = $bank;
              }
             
              return $result;

          },
          'BEN_AGENCIA' => function ($registrations) use ($detahe2, $detahe1, $default, $app, $dePara, $cpfCsv) {

              //Verifica se existe o medadado se sim pega o registro
              if(!($branch = $this->bankData($registrations, 'branch'))){
                  $result = "";
                  $field_cpf = $detahe2['BEN_CPF']['field_id'];
                  $cpfBase = preg_replace('/[^0-9]/i', '',$registrations->$field_cpf);
                  
                  $pos = array_search($cpfBase,$cpfCsv);
              
                  if($pos){                    
                      $agencia = $dePara[$pos]['BEN_AGENCIA'];
                      
                  }else{
                      $temp = $default['formoReceipt'];
                      $formoReceipt = $temp ? $registrations->$temp : false;
      
                      if($formoReceipt == "CARTEIRA DIGITAL BB"){
                          $field_id = $default['fieldsWalletDigital']['agency'];

                      }else{
                          $field_id = $detahe1['BEN_AGENCIA']['field_id'];

                      }

                      $agencia = $registrations->$field_id;
                  }
              }else{
                  $agencia = $branch;
              }
              

              $age = explode("-", $agencia);
              
              if(count($age)>1){
                  $result = $age[0];

              }else{
                  if (strlen($age[0]) > 4) {
                  
                      $result = substr($age[0], 0, 4);
                  } else {
                      $result = $age[0];
                  }
              }
             
              $result = $this->normalizeString($result);
              return is_string($result) ? strtoupper($result) : $result;
          },
          'BEN_AGENCIA_DIGITO' => function ($registrations) use ($detahe2, $detahe1, $default, $app, $dePara, $cpfCsv) {
              
              //Verifica se existe o medadado se sim pega o registro
               if(!($branch = $this->bankData($registrations, 'branch'))){
                      $result = "";
                      $field_cpf = $detahe2['BEN_CPF']['field_id'];
                      $cpfBase = preg_replace('/[^0-9]/i', '',$registrations->$field_cpf);
                      
                      $pos = array_search($cpfBase,$cpfCsv);               
                      if($pos){                    
                          $agencia = $dePara[$pos]['BEN_AGENCIA'];
                          
                      }else{
                          $temp = $default['formoReceipt'];
                          $formoReceipt = $temp ? $registrations->$temp : false; 
          
                          if($formoReceipt == "CARTEIRA DIGITAL BB"){
                              $field_id = $default['fieldsWalletDigital']['agency'];                    
                          }else{
                              $field_id = $detahe1['BEN_AGENCIA_DIGITO']['field_id'];
                          }

                          $agencia = $registrations->$field_id;
                      }
                  }else{
                      $agencia = $branch;
                  }
              
              
              $age = explode("-", $agencia);

              if(count($age)>1){
                  $result = $age[1];
              }else{
                  if (strlen($age[0]) > 4) {
                      $result = substr($age[0], -1);
                  } else {
                      $result = "";
                  }
              }
              
              $result = $this->normalizeString($result);
              return is_string($result) ? strtoupper($result) : $result;
          },
          'BEN_CONTA' => function ($registrations) use ($detahe2, $detahe1, $default, $app, $dePara, $cpfCsv) {  
              
              $field_conta = $detahe1['TIPO_CONTA']['field_id'];
              $dig = $detahe1['BEN_CONTA_DIGITO']['field_id']; //pega o field_id do digito da conta

              //Verifica se existe o medadado se sim pega o registro
              if(!($account = $this->bankData($registrations, 'account'))){
                  $result  = ""; 
                  $field_cpf = $detahe2['BEN_CPF']['field_id'];
                  $cpfBase = preg_replace('/[^0-9]/i', '',$registrations->$field_cpf);
                 
                  $typeAccount = $registrations->$field_conta;                   

                  $temp = $detahe1['BEN_CODIGO_BANCO']['field_id'];
                  if($temp){
                      $numberBank = $this->numberBank($registrations->$temp);
                  }else{
                      $numberBank = $default['defaultBank'];
                  }

                  $pos = array_search($cpfBase,$cpfCsv);               
                  if($pos){                    
                      $temp_account = $dePara[$pos]['BEN_CONTA'];
                      
                  }else{
                      $temp = $default['formoReceipt'];
                      $formoReceipt = $temp ? $registrations->$temp : false;
                  
                      if($formoReceipt == "CARTEIRA DIGITAL BB"){
                          $field_id = $default['fieldsWalletDigital']['account'];                    
                      }else{
                          $field_id = $detahe1['BEN_CONTA']['field_id'];
                      }

                      $temp_account = $registrations->$field_id;
                  }
              }else{
                  $typeAccount = $this->bankData($registrations, 'account-type');
                  $numberBank = $this->bankData($registrations, 'bank-number');
                  $temp_account = $account;
              }                
              
              $temp_account = explode("-", $temp_account);
              if(count($temp_account)>1){
                  $account = $temp_account[0];
              }else{
                  $account = substr($temp_account[0], 0, -1);
              }
             
              if(!$account){
                  $app->log->info($registrations->number . " Conta bancária não informada");
                  return " ";
              }

              
              if($typeAccount == $default['typesAccount']['poupanca']){

                  if (($numberBank == '001') && (substr($account, 0, 2) != "51")) {

                      $account_temp = "51" . $account;

                      if(strlen($account_temp) < 9){
                          $result = "51".str_pad($account, 7, 0, STR_PAD_LEFT);
                      
                      }else{
                          $result = "51" . $account;

                      }
                  }else{
                      $result = $account;

                  }
              }else{
                  $result = $account;

              }
              
              $result = preg_replace('/[^0-9]/i', '',$result);

              if($dig === $field_conta && $temp_account == 1){                   
                  return substr($this->normalizeString($result), 0, -1); // Remove o ultimo caracter. Intende -se que o ultimo caracter é o DV da conta

              }else{                    
                  return $this->normalizeString($result);

              }
              
          },
          'BEN_CONTA_DIGITO' => function ($registrations) use ($detahe2, $detahe1, $default, $app, $dePara, $cpfCsv) {
              //Verifica se existe o medadado se sim pega o registro
              if(!($account = $this->bankData($registrations, 'account'))){
                  $result = "";
                  $field_id = $detahe1['BEN_CONTA']['field_id'];
                  
                  $field_cpf = $detahe2['BEN_CPF']['field_id'];
                  $cpfBase = preg_replace('/[^0-9]/i', '',$registrations->$field_cpf);
                  
                  /**
                   * Caso use um banco padrão para recebimento, pega o número do banco das configs
                   * Caso contrario busca o número do banco na base de dados
                   */
                  $fieldBanco = $detahe1['BEN_CODIGO_BANCO']['field_id'];
                  if($fieldBanco){
                      $numberBank = $this->numberBank($registrations->$fieldBanco);
                  }else{
                      $numberBank = $default['defaultBank']; 
                  }
                  
                  /**
                   * Verifica se o CPF do requerente consta na lista de de-para dos bancos
                   * se existir, pega os dados bancários do arquivo
                   */
                  $pos = array_search($cpfBase,$cpfCsv);               
                  if($pos){                    
                      $temp_account = $dePara[$pos]['BEN_CONTA'];
                      
                  }else{
                      /**
                       * Verifica se existe a opção de forma de recebimento
                       * Caso exista, e seja CARTEIRA DIGITAL BB pega o field id nas configs em (fieldsWalletDigital)
                       */
                      $formaRecebimento = $default['formoReceipt'];
                      $formoReceipt = $formaRecebimento ? $registrations->$formaRecebimento : false;
                  
                      if($formoReceipt == "CARTEIRA DIGITAL BB"){
                          $temp = $default['fieldsWalletDigital']['account'];                    
                      }else{
                          $temp = $detahe1['BEN_CONTA_DIGITO']['field_id'];
                      }
                      $temp_account = $registrations->$temp;
                  }
              }else{
                  $typeAccount = $this->bankData($registrations, 'account-type');
                  $numberBank = $this->bankData($registrations, 'bank-number');
                  $temp_account =  $account;
              }
              
              $temp_account = explode("-", $temp_account);
              
              if(count($temp_account)>1){
                  $dig = substr($temp_account[1], -1);

              }else{
                  $dig = substr($temp_account[0], -1);
              }
              
              /**
               * Pega o tipo de conta que o beneficiário tem Poupança ou corrente
               */
              $fiieldTipoConta = $detahe1['TIPO_CONTA']['field_id'];
              $typeAccount = $registrations->$fiieldTipoConta;

              /**
               * Verifica se o usuário é do banco do Brasil, se sim verifica se a conta é poupança
               * Se a conta for poupança e iniciar com o 510, ele mantem conta e DV como estão
               * Caso contrario, ele pega o DV do De-Para das configs (savingsDigit)
               */
              if ($numberBank == '001' && $typeAccount == $default['typesAccount']['poupanca']) {                   
                  if (substr($temp_account[0], 0, 3) == "510") {
                      $result = $dig;
                  } else {
                      $dig = trim(strtoupper($dig));                       
                      $result = $default['savingsDigit'][$dig];
                  }
              } else {

                  $result = $dig;
              }                
              
              return is_string($result) ? strtoupper(trim($result)) : $this->normalizeString(trim($result));
             
          },
          'BEN_DIGITO_CONTA_AGENCIA_80' => '',
          'BEN_NOME' => function ($registrations) use ($detahe1) {
              $field_id = $detahe1['BEN_NOME']['field_id'];
              $result = substr($this->normalizeString($registrations->$field_id), 0, $detahe1['BEN_NOME']['length']);                            
              return $result;
          },
          'BEN_DOC_ATRIB_EMPRESA_82' => '',
          'DATA_PAGAMENTO' => function ($registrations) use ($detahe1) {
              $date = new DateTime();                
              $date->add(new DateInterval('P5D'));
              $weekday = $date->format('D');

              $weekdayList = [
                  'Mon' => true,
                  'Tue' => true,
                  'Wed' => true,
                  'Thu' => true,
                  'Fri' => true,
                  'Sat' => false,
                  'Sun' => false,
              ];

              while (!$weekdayList[$weekday]) {
                  $date->add(new DateInterval('P1D'));
                  $weekday = $date->format('D');
              }
              
              return $date->format('dmY');
          },
          'TIPO_MOEDA' => '',
          'USO_BANCO_85' => '',
          'VALOR_INTEIRO' => function ($registrations) use ($detahe1, $app) {
              $payment = $app->em->getRepository('\RegistrationPayments\Payment')->findOneBy([
                  'registration' => $registrations->id
              ]);

              return preg_replace('/[^0-9]/i', '',number_format($payment->amount,2,",","."));
          },
          'USO_BANCO_88' => '',
          'USO_BANCO_89' => '',
          'OUTRAS_INFO_233A' => function ($registrations) use ($detahe1, $default) {
               //Verifica se existe o medadado se sim pega o registro
               if(!($bank = $this->bankData($registrations, 'bank-number'))){

                  $temp = $detahe1['BEN_CODIGO_BANCO']['field_id'];

                  $field_conta = $detahe1['TIPO_CONTA']['field_id'];
                  $typeAccount = $registrations->$field_conta;               

                  if($temp){
                      $numberBank = $this->numberBank($registrations->$temp);
                  }else{
                      $numberBank = $default['defaultBank'];
                  }
              }else{
                  $typeAccount = $this->bankData($registrations, 'account-type');                   
                  $numberBank = $bank;
              }
                  
              if ($numberBank == "001" && $typeAccount == $default['typesAccount']['poupanca']) {
                  return '11';

              }else{
                  return "";

              }
          },
          'USO_BANCO_90' => '',
          'CODIGO_FINALIDADE_TED' => function ($registrations) use ($detahe1, $default) {
              //Verifica se existe o medadado se sim pega o registro
              if(!($bank = $this->bankData($registrations, 'bank-number'))){
                  $temp = $detahe1['BEN_CODIGO_BANCO']['field_id'];
                  if($temp){
                      $numberBank = $this->numberBank($registrations->$temp);
                  }else{
                      $numberBank = $default['defaultBank'];
                  }
              }else{
                  $numberBank =  $bank;
              }
              if ($numberBank != "001") {
                  return '10';
              } else {
                  return "";
              }
          },
          'USO_BANCO_92' => '',
          'USO_BANCO_93' => '',
          'TIPO_CONTA' => '',
      ];

      $mappedDeletalhe2 = [
          'BANCO' => '',
          'LOTE' => '',
          'REGISTRO' => '',
          'NUMERO_REGISTRO' => '',
          'SEGMENTO' => '',
          'USO_BANCO_104' => '',
          'BEN_TIPO_DOC' => function ($registrations) use ($detahe2) {
              $field_id = $detahe2['BEN_CPF']['field_id'];
              $data = preg_replace('/[^0-9]/i', '',$registrations->$field_id);
              if (strlen($this->normalizeString($data)) <= 11) {
                  return 1;
              }else{
                  return 2;
              }
             
          },
          'BEN_CPF' => function ($registrations) use ($detahe2) {
              $field_id = $detahe2['BEN_CPF']['field_id'];
              $data = preg_replace('/[^0-9]/i', '',$registrations->$field_id);
              if (strlen($this->normalizeString($data)) != 11) {
                  $_SESSION['problems'][$registrations->number] = "CPF Inválido";
              }
              return $data;
          },
          'BEN_ENDERECO_LOGRADOURO' => function ($registrations) use ($detahe2, $app) {
              return strtoupper($this->normalizeString($registrations->number));
          },
          'BEN_ENDERECO_NUMERO' => function ($registrations) use ($detahe2, $app) {
              $field_id = $detahe2['BEN_ENDERECO_NUMERO']['field_id'];
              $length = $detahe2['BEN_ENDERECO_NUMERO']['length'];
              $data = $registrations->$field_id;
              $result = $data['En_Num'];
              
              $result = substr($result, 0, $length);

              return $result;
          },
          'BEN_ENDERECO_COMPLEMENTO' => function ($registrations) use ($detahe2, $app) {
              $field_id = $detahe2['BEN_ENDERECO_COMPLEMENTO']['field_id'];
              $length = $detahe2['BEN_ENDERECO_COMPLEMENTO']['length'];
              $data = $registrations->$field_id;
              $result = $data['En_Complemento'];
              
              $result = substr($result, 0, $length);

              return $result;
          },
          'BEN_ENDERECO_BAIRRO' => function ($registrations) use ($detahe2, $app) {
              $field_id = $detahe2['BEN_ENDERECO_BAIRRO']['field_id'];
              $length = $detahe2['BEN_ENDERECO_BAIRRO']['length'];
              $data = $registrations->$field_id;
              $result = $data['En_Bairro'];
              
              $result = substr($result, 0, $length);

              return $result;
          },
          'BEN_ENDERECO_CIDADE' => function ($registrations) use ($detahe2, $app) {
              $field_id = $detahe2['BEN_ENDERECO_CIDADE']['field_id'];
              $length = $detahe2['BEN_ENDERECO_CIDADE']['length'];
              $data = $registrations->$field_id;
              $result = $data['En_Municipio'];
              
              $result = substr($result, 0, $length);

              return $result;
          },
          'BEN_ENDERECO_CEP' => function ($registrations) use ($detahe2, $app) {
              $field_id = $detahe2['BEN_ENDERECO_CEP']['field_id'];
              $length = $detahe2['BEN_ENDERECO_CEP']['length'];
              $data = $registrations->$field_id;
              $result = $data['En_CEP'];
              
              $result = substr($result, 0, $length);

              return $result;
          },
          'BEN_ENDERECO_ESTADO' => function ($registrations) use ($detahe2, $app) {
              $field_id = $detahe2['BEN_ENDERECO_ESTADO']['field_id'];
              $length = $detahe2['BEN_ENDERECO_CIDADE']['length'];
              $data = $registrations->$field_id;
              $result = $data['En_Estado'];
              
              $result = substr($result, 0, $length);

              return $result;
          },
          'USO_BANCO_114' => '',
          'USO_BANCO_115' => function ($registrations) use ($detahe2, $app) {
              return $this->normalizeString($registrations->number);
          },
          'USO_BANCO_116' => '',
          'USO_BANCO_117' => '',
      ];

      $mappedTrailer1 = [
          'BANCO' => '',
          'LOTE' => '',
          'REGISTRO' => '',
          'USO_BANCO_126' => '',
          'QUANTIDADE_REGISTROS_127' => '',
          'VALOR_TOTAL_DOC_INTEIRO' => '',
          'VALOR_TOTAL_DOC_DECIMAL' => '',
          'USO_BANCO_130' => '',
          'USO_BANCO_131' => '',
          'USO_BANCO_132' => '',
      ];

      $mappedTrailer2 = [
          'BANCO' => '',
          'LOTE' => '',
          'REGISTRO' => '',
          'USO_BANCO_141' => '',
          'QUANTIDADE_LOTES-ARQUIVO' => '',
          'QUANTIDADE_REGISTROS_ARQUIVOS' => '',
          'USO_BANCO_144' => '',
          'USO_BANCO_145' => '',
      ];

      /**
       * Separa os registros em 3 categorias
       * $recordsBBPoupanca =  Contas polpança BB
       * $recordsBBCorrente = Contas corrente BB
       * $recordsOthers = Contas outros bancos
       */
      $recordsBBPoupanca = [];
      $recordsBBCorrente = [];
      $recordsOthers = [];
      $field_TipoConta = $default['field_TipoConta'];
      $field_banco = $default['field_banco'];
      $field_agency = $default['field_agency'];
      $defaultBank = $default['defaultBank'];
      $informDefaultBank = $default['informDefaultBank'];       
      $selfDeclaredBB = $default['selfDeclaredBB'];
      $typesReceipt = $default['typesReceipt'];
      $formoReceipt = $default['formoReceipt'];
      $womanMonoParent = $default['womanMonoParent'];
      $monoParentIgnore = $default['monoParentIgnore'];
      $countBanked = 0;
      $countUnbanked = 0;
      $countUnbanked = 0;
      $noFormoReceipt = 0;

      if($default['ducumentsType']['unbanked']){ // Caso exista separação entre bancarizados e desbancarizados
          foreach($registrations as $value){
              
              //Caso nao exista pagamento para a inscrição, ele a ignora e notifica na tela                
              if(!$this->validatedPayment($value)){
                  $app->log->info("\n".$value->number . " - Pagamento nao encontrado.");
                  continue;
              } 
              
              // Veirifica se existe a pergunta se o requerente é correntista BB ou não no formulário. Se sim, pega a resposta  
              $accountHolderBB = "NÃO";              
              if($selfDeclaredBB){
                  $accountHolderBB = trim($value->$selfDeclaredBB);
                 
              }
              
              //Caso nao exista informações bancárias
              if(!$value->$formoReceipt && $selfDeclaredBB === "NÃO"){                                   
                  $app->log->info("\n".$value->number . " - Forma de recebimento não encontrada.");
                  $noFormoReceipt ++;                   
                  continue;
              }
              
              //Verifica se a inscrição é bancarizada ou desbancarizada               
              if(in_array(trim($value->$formoReceipt), $typesReceipt['banked']) || $accountHolderBB === "SIM"){
                  $Banked = true;     
                  $countBanked ++;

              }else if(in_array(trim($value->$formoReceipt) , $typesReceipt['unbanked']) || $accountHolderBB === "NÃO"){
                  $Banked = false;
                  $countUnbanked ++; 
                             
              }
             
              if($Banked){
                  if($defaultBank){                          
                      if($informDefaultBank === "001" || $accountHolderBB === "SIM"){
                          
                          if (trim($value->$field_TipoConta) === "Conta corrente" || $value->$formoReceipt === "CARTEIRA DIGITAL BB") { 
                              $recordsBBCorrente[] = $value;
                              
                          }  else if (trim($value->$field_TipoConta) === "Conta poupança"){
                              
                              $recordsBBPoupanca[] = $value;                               
      
                          }else{
                              $recordsBBCorrente[] = $value;
                          }
                      }else{
                          $recordsOthers[] = $value;
                      }
                      
                  }else{    
                                         
                      if(($this->numberBank($value->$field_banco) == "001") || $accountHolderBB == "SIM"){
                          if (trim($value->$field_TipoConta) === "Conta corrente" || $value->$formoReceipt === "CARTEIRA DIGITAL BB") { 
                              $recordsBBCorrente[] = $value;
      
                          } else if (trim($value->$field_TipoConta) === "Conta poupança"){
                              $recordsBBPoupanca[] = $value;
      
                          }else{
                              $recordsBBCorrente[] = $value;
                          }
                      }else{                            
                          $recordsOthers[] = $value;
                      
                      }
                  }
              }else{
                  continue;
              
              }
          }
      }else{
        
          foreach ($registrations as $value) {
              //Caso nao exista pagamento para a inscrição, ele a ignora e notifica na tela
              if(!$this->validatedPayment($value)){
                  $app->log->info("\n".$value->number . " - Pagamento nao encontrado.");
                  continue;
              }

              if ($this->numberBank($value->$field_banco) == "001") {               
                  if ($value->$field_TipoConta == "Conta corrente") {
                      $recordsBBCorrente[] = $value;
                  } else {
                      $recordsBBPoupanca[] = $value;
                  }
  
              } else {
                  $recordsOthers[] = $value;
              }
          }
      }
      //Caso exista separação de bancarizados ou desbancarizados, mostra no terminal o resumo
      if($default['ducumentsType']['unbanked']){           
          $app->log->info("\nResumo da separação entre bancarizados e desbancarizados.");
          $app->log->info($countBanked . " BANCARIZADOS");
          $app->log->info($countUnbanked . " DESBANCARIZADOS");
      }

      //Mostra no terminal resumo da separação entre CORRENTE BB, POUPANÇA BB OUTROS BANCOS e SEM INFORMAÇÃO BANCÁRIA
      $app->log->info("\nResumo da separação entre CORRENTE BB, POUPANÇA BB, OUTROS BANCOS e SEM INFORMAÇÃO BANCÁRIA");
      $app->log->info(count($recordsBBCorrente) . " CORRENTE BB");
      $app->log->info(count($recordsBBPoupanca) . " POUPANÇA BB");
      $app->log->info(count($recordsOthers) . " OUTROS BANCOS");
      $app->log->info($noFormoReceipt . " SEM INFORMAÇÃO BANCÁRIA");
      sleep(1);
      
      //Verifica se existe registros em algum dos arrays. Caso não exista exibe a mensagem
      $validaExist = array_merge($recordsBBCorrente, $recordsOthers, $recordsBBPoupanca);
      if(empty($validaExist)){
          echo "Não foram encontrados registros analise os logs";
          exit();
      }

      /**
       * Monta o txt analisando as configs. caso tenha que buscar algo no banco de dados,
       * faz a pesquisa atravez do array mapped. Caso contrario busca o valor default da configuração
       *
       */
      $txt_data = "";
      $numLote = 0;
      $totaLotes = 0;
      $totalRegistros = 0;
      // $numSeqRegistro = 0;

      $complement = [];
      $txt_data = $this->mountTxt($header1, $mappedHeader1, $txt_data, null, null, $app);
      $totalRegistros += 1;

      $txt_data .= "\r\n";

      /**
       * Inicio banco do Brasil Corrente
       */
      $lotBBCorrente = 0;
      if ($recordsBBCorrente) {
          // Header 2
          $numSeqRegistro = 0;
          $complement = [];
          $numLote++;
          $complement = [
              'FORMA_LANCAMENTO' => 01,
              'LOTE' => $numLote,
          ];

          $txt_data = $this->mountTxt($header2, $mappedHeader2, $txt_data, null, $complement, $app);
          $txt_data .= "\r\n";

          $lotBBCorrente += 1;

          $_SESSION['valor'] = 0;

          $totaLotes++;
          // $numSeqRegistro = 0;

          //Detalhes 1 e 2

          foreach ($recordsBBCorrente as $key_records => $records) {
              $numSeqRegistro++;
              $complement = [
                  'LOTE' => $numLote,
                  'NUMERO_REGISTRO' => $numSeqRegistro,
              ];
              $txt_data = $this->mountTxt($detahe1, $mappedDeletalhe1, $txt_data, $records, $complement, $app);
              $txt_data .= "\r\n";

              $numSeqRegistro++;
              $complement['NUMERO_REGISTRO'] = $numSeqRegistro;

              $txt_data = $this->mountTxt($detahe2, $mappedDeletalhe2, $txt_data, $records, $complement, $app);
              $txt_data .= "\r\n";

              $lotBBCorrente += 2;
              $this->processesPayment($records, $app);
          }

          //treiller 1
          $lotBBCorrente += 1; // Adiciona 1 para obedecer a regra de somar o treiller 1
          $valor = $_SESSION['valor'];             
          $complement = [
              'QUANTIDADE_REGISTROS_127' => $lotBBCorrente,
              'VALOR_TOTAL_DOC_INTEIRO' => $valor,

          ];

          $txt_data = $this->mountTxt($trailer1, $mappedTrailer1, $txt_data, null, $complement, $app);
          $txt_data .= "\r\n";
          $totalRegistros += $lotBBCorrente;
      }

      /**
       * Inicio banco do Brasil Poupança
       */
      $lotBBPoupanca = 0;
      if ($recordsBBPoupanca) {
          // Header 2
          $numSeqRegistro = 0;
          $complement = [];
          $numLote++;
          $complement = [
              'FORMA_LANCAMENTO' => 05,
              'LOTE' => $numLote,
          ];
          $txt_data = $this->mountTxt($header2, $mappedHeader2, $txt_data, null, $complement, $app);
          $txt_data .= "\r\n";

          $lotBBPoupanca += 1;

          $_SESSION['valor'] = 0;

          $totaLotes++;
          // $numSeqRegistro = 0;

          //Detalhes 1 e 2

          foreach ($recordsBBPoupanca as $key_records => $records) {               
              $numSeqRegistro++;
              $complement = [
                  'LOTE' => $numLote,
                  'NUMERO_REGISTRO' => $numSeqRegistro,
              ];

              $txt_data = $this->mountTxt($detahe1, $mappedDeletalhe1, $txt_data, $records, $complement, $app);
              $txt_data .= "\r\n";

              $numSeqRegistro++;
              $complement['NUMERO_REGISTRO'] = $numSeqRegistro;

              $txt_data = $this->mountTxt($detahe2, $mappedDeletalhe2, $txt_data, $records, $complement, $app);
              $txt_data .= "\r\n";

              $lotBBPoupanca += 2;
              $this->processesPayment($records, $app);
          }

          //treiller 1
          $lotBBPoupanca += 1; // Adiciona 1 para obedecer a regra de somar o treiller 1
          $valor = $_SESSION['valor'];
          $complement = [
              'QUANTIDADE_REGISTROS_127' => $lotBBPoupanca,
              'VALOR_TOTAL_DOC_INTEIRO' => $valor,
              'LOTE' => $numLote,
          ];

          $txt_data = $this->mountTxt($trailer1, $mappedTrailer1, $txt_data, null, $complement, $app);
          $txt_data .= "\r\n";

          $totalRegistros += $lotBBPoupanca;
      }

      /**
       * Inicio Outros bancos
       */
      $lotOthers = 0;
      if ($recordsOthers) {
          //Header 2
          $numSeqRegistro = 0;
          $complement = [];
          $numLote++;
          $complement = [
              'FORMA_LANCAMENTO' => 41,
              'LOTE' => $numLote,
          ];

          $txt_data = $this->mountTxt($header2, $mappedHeader2, $txt_data, null, $complement, $app);

          $txt_data .= "\r\n";

          $lotOthers += 1;

          $_SESSION['valor'] = 0;

          $totaLotes++;
          // $numSeqRegistro = 0;

          //Detalhes 1 e 2

          foreach ($recordsOthers as $key_records => $records) {                
              $numSeqRegistro++;
              $complement = [
                  'LOTE' => $numLote,
                  'NUMERO_REGISTRO' => $numSeqRegistro,
              ];
              $txt_data = $this->mountTxt($detahe1, $mappedDeletalhe1, $txt_data, $records, $complement, $app);
              $txt_data .= "\r\n";

              $numSeqRegistro++;
              $complement['NUMERO_REGISTRO'] = $numSeqRegistro;

              $txt_data = $this->mountTxt($detahe2, $mappedDeletalhe2, $txt_data, $records, $complement, $app);
              $txt_data .= "\r\n";
              $lotOthers += 2;
              $this->processesPayment($records, $app);
              

          }

          //treiller 1
          $lotOthers += 1; // Adiciona 1 para obedecer a regra de somar o treiller 1
          $valor = $_SESSION['valor'];         
          $complement = [
              'QUANTIDADE_REGISTROS_127' => $lotOthers,
              'VALOR_TOTAL_DOC_INTEIRO' => $valor,
              'LOTE' => $numLote,
          ];
          $txt_data = $this->mountTxt($trailer1, $mappedTrailer1, $txt_data, null, $complement, $app);
          $txt_data .= "\r\n";
          $totalRegistros += $lotOthers;
      }

      //treiller do arquivo
      $totalRegistros += 1; // Adiciona 1 para obedecer a regra de somar o treiller
      $complement = [
          'QUANTIDADE_LOTES-ARQUIVO' => $totaLotes,
          'QUANTIDADE_REGISTROS_ARQUIVOS' => $totalRegistros,
      ];

      $txt_data = $this->mountTxt($trailer2, $mappedTrailer2, $txt_data, null, $complement, $app);

      if (isset($_SESSION['problems'])) {
          foreach ($_SESSION['problems'] as $key => $value) {
              $app->log->info("Problemas na inscrição " . $key . " => " . $value);
          }
          unset($_SESSION['problems']);
      }
      
      /**
       * cria o arquivo no servidor e insere o conteuto da váriavel $txt_data
       */
      $file_name = 'inciso1-cnab240-'. $this->getStatus($this->data[$parametersForms['typeExport']]) .$opportunity_id.'-' . md5(json_encode($txt_data)) . '.txt';

      $dir = PRIVATE_FILES_PATH . 'aldirblanc/inciso1/remessas/cnab240/';

      $patch = $dir . $file_name;

      if (!is_dir($dir)) {
          mkdir($dir, 0700, true);
      }

      $stream = fopen($patch, 'w');

      fwrite($stream, $txt_data);

      fclose($stream);

      header('Content-Type: application/csv');
      header('Content-Disposition: attachment; filename=' . $file_name);
      header('Pragma: no-cache');
      readfile($patch);

  }

  private function insertNewApproved(int $opportunity_id) {
    $app = App::i();

    $payments_registration_id = [];
    $status_approved = Registration::STATUS_APPROVED;
    $payments = $app->repo("SecultCEPayment")->findAll();


    foreach($payments as $pay) {
      $payments_registration_id[] = $pay->registration->id;
    }

    // TESTE
    // $payments_registration_id[] = "12309482";
    // $payments_registration_id[] = "09878987";

    $query = "
      SELECT
        *
      FROM
        registration
      WHERE
        status = $status_approved
        AND opportunity_id = $opportunity_id
    ";

    $not_in = implode(', ', $payments_registration_id);

    if (count($payments_registration_id) > 0) {
      $query .= "AND id NOT IN ($not_in)";
    }

    $query .= ";";

    $stmt = $app->em->getConnection()->prepare($query);
    $stmt->execute();
    $registrations = $stmt->fetchAll();

    foreach($registrations as $reg) {
      $payInstallment1 = new SecultCEPayment();
      $payInstallment2 = new SecultCEPayment();

      $registration = $app->repo("Registration")->findOneBy([ "id" => $reg["id"]]);

      $payInstallment1->installment = 1;
      $payInstallment1->value = 500;
      $payInstallment1->status = 0;
      $payInstallment1->setRegistration($registration);

      $payInstallment2->installment = 2;
      $payInstallment2->value = 500;
      $payInstallment2->status = 0;
      $payInstallment2->setRegistration($registration);

      $app->em->persist($payInstallment1);
      $app->em->persist($payInstallment2);
    }

    $app->em->flush();
  }

  private function searchPayments($data) {
    $app = App::i();

    $dql = "SELECT se FROM MapasCulturais\\Entities\\SecultCEPayment se
        JOIN MapasCulturais\\Entities\\Registration r WITH r.id = se.registration
      WHERE
        se.installment = {$data['installment']}
    ";

    if ($data["registrations"] !== "") {
      $registrations_array = explode(";", $data["registrations"]);
      $registrations_string = implode(", ", $registrations_array);

      $dql .= "AND se.registration_id IN ($registrations_string)";
    }

    if ($data["remakePayment"]) {
      $dql .= "AND se.status IN (0, 1, 2)";
    } else {
      $dql .= "AND se.status = 0";
    }

    // $dql .= ";";

    $query = $app->em->createQuery($dql);
    $payments = $query->getResult();

    if (empty($payments)) {
      echo "Não foram encontrados pagamentos.";
      die();
    }

    // $stmt = $app->em->getConnection()->prepare($query);
    // $stmt->execute();
    // $payments = $stmt->fetchAll();

    return $payments;
  }

  private function updatePayments() {

  }

  private function sendEmails(string $emails) {

  }

  public function ALL_payment() {
    if ($this->data["paymentDate"] === "") {
      echo "Escolha a data de pagamento. Me ajude!!";
      return;
    }

    $this->insertNewApproved($this->data["opportunity"]);

    $payments = $this->searchPayments($this->data);

    $success = $this->generateCnab240($payments);

    if ($success) { 
      $this->updatePayments($payments);
    }

  }
}
<?php

namespace MapasCulturais\Controllers;

use DateTime;
use DateInterval;
use Normalizer;
use League\Csv\Reader;
use League\Csv\Writer;
use League\Csv\Statement;
use MapasCulturais\App;
use MapasCulturais\i;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\SecultCEPayment;
use MapasCulturais\Entities\SecultCEPaymentFile;
use MapasCulturais\Controllers\EntityController;

class Auxilio extends \MapasCulturais\Controllers\Registration
{

    public function __construct()
    {
        parent::__construct();

        $app = App::i();

        $this->entityClassName = "\MapasCulturais\Entities\Auxilio";
        $this->config = $app->plugins['RegistrationPaymentsAuxilio']->config;

        // $opportunity = $app->repo('Opportunity')->find($this->config["opportunity_id"]);
        // $app->controller('Registration')->registerRegistrationMetadata($opportunity);
    }

    private function getOpportunity()
    {
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

    private function bankData($registration, $return = 'account')
    {

        if ($return == 'account') {

            return $registration->registration->owner->metadata['payment_bank_account_number'] ?? null;
        } elseif ($return == 'branch') {

            return $registration->registration->owner->metadata['payment_bank_branch'] ?? null;
        } elseif ($return == 'account-type') {

            return $registration->registration->owner->metadata['payment_bank_account_type'] ?? null;
        } elseif ($return == 'bank-number') {

            return $registration->registration->owner->metadata['payment_bank_number'] ?? null;
        }

        return false;
    }

    private function normalizeString($valor)
    {
        $valor = Normalizer::normalize($valor, Normalizer::FORM_D);
        return preg_replace('/[^A-Za-z0-9 ]/i', '', $valor);
    }

    private function numberBank($bankName)
    {
        $bankName = strtolower(preg_replace('/\\s\\s+/', ' ', $this->normalizeString($bankName)));
        $bankList = $this->readingCsvFromTo('CSV/fromToNumberBank.csv');
        $list = [];
        foreach ($bankList as $key => $value) {
            $list[$key]['BANK'] = strtolower(preg_replace('/\\s\\s+/', ' ', $this->normalizeString($value['BANK'])));
            $list[$key]['NUMBER'] = strtolower(preg_replace('/\\s\\s+/', ' ', $this->normalizeString($value['NUMBER'])));
        }
        $result = 0;
        foreach ($list as $key => $value) {
            if ($value['BANK'] === $bankName) {
                $result = $value['NUMBER'];
                break;
            }
        }
        return $result;
    }

    private function readingCsvFromTo($filename)
    {

        $filename = __DIR__ . "/../../../" . $filename;

        //Verifica se o arquivo existe
        if (!file_exists($filename)) {
            return false;
        }

        $data = [];
        //Abre o arquivo em modo de leitura
        $stream = fopen($filename, "r");

        //Faz a leitura do arquivo
        $csv = Reader::createFromStream($stream);

        //Define o limitador do arqivo (, ou ;)
        $csv->setDelimiter(";");

        //Seta em que linha deve se iniciar a leitura
        $header_temp = $csv->setHeaderOffset(0);

        //Faz o processamento dos dados
        $stmt = (new Statement());

        $results = $stmt->process($csv);

        foreach ($results as $key => $value) {
            $data[$key] = $value;
        }

        return $data;
    }

    private function cpfCsv($filename)
    {

        $results = $this->readingCsvFromTo($filename);

        $data = [];

        foreach ($results as $key => $value) {
            $data[$key] = $value['CPF'];
        }

        return $data;
    }

    private function createString($value)
    {
        $data = "";
        $qtd = strlen($value['default']);
        $length = $value['length'];
        $type = $value['type'];
        $diff = 0;
        $complet = "";

        if ($qtd < $length) {
            $diff = $length - $qtd;
        }

        $value['default'] = Normalizer::normalize($value['default'], Normalizer::FORM_D);
        $regex = isset($value['filter']) ? $value['filter'] : '/[^a-z0-9 ]/i';
        $value['default'] = preg_replace($regex, '', $value['default']);

        if ($type === 'int') {
            $data .= str_pad($value['default'], $length, '0', STR_PAD_LEFT);
        } else {
            $data .= str_pad($value['default'], $length, " ");
        }
        return substr($data, 0, $length);
    }

    private function generateCnab240($registrations, $payments)
    {
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '-1');

        /**
         * Verifica se o usuário está autenticado
         */
        $this->requireAuthentication();
        $app = App::i();

        //Captura se deve ser gerado um arquivo do tipo teste
        $typeFile =  null;
        if (isset($this->data['typeFile'])) {
            $typeFile = $this->data['typeFile'];
        }

        $opportunity = $this->getOpportunity();
        $opportunity_id = $opportunity->id;
        //   $registrations = $this->getRegistrations($opportunity);
        //   $registrations = $payments;
        //   $parametersForms = $this->getParametersForms();

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
                if ($typeFile == "TS") {
                    return "TS";
                } else {
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
                if ($typeFile == "TS") {
                    return "TS";
                } else {
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
                if (!($bank = $this->bankData($registrations, 'bank-number'))) {
                    $field_id = $detahe1['BEN_CODIGO_BANCO']['field_id'];
                    $numberBank = $this->numberBank($registrations->$field_id);
                } else {
                    $numberBank = $bank;
                }

                //Se for BB devolve 000 se nao devolve 018
                if ($numberBank === "001") {
                    $result = "000";
                } else {
                    $result = "018";
                }

                return $result;
            },
            'BEN_CODIGO_BANCO' => function ($registrations) use ($detahe2, $detahe1, $dePara, $cpfCsv) {



                //Verifica se existe o medadado se sim pega o registro
                if (!($bank = $this->bankData($registrations, 'bank-number'))) {

                    $field_cpf = $detahe2['BEN_CPF']['field_id'];

                    $cpfBase = preg_replace('/[^0-9]/i', '', $registrations->$field_cpf);

                    $pos = array_search($cpfBase, $cpfCsv);

                    if ($pos) {
                        $result = $dePara[$pos]['BEN_NUM_BANCO'];
                    } else {
                        $field_id = $detahe1['BEN_CODIGO_BANCO']['field_id'];
                        $result = $this->numberBank($registrations->$field_id);
                    }
                } else {
                    $result = $bank;
                }

                return $result;
            },
            'BEN_AGENCIA' => function ($registrations) use ($detahe2, $detahe1, $default, $app, $dePara, $cpfCsv) {

                //Verifica se existe o medadado se sim pega o registro
                if (!($branch = $this->bankData($registrations, 'branch'))) {
                    $result = "";
                    $field_cpf = $detahe2['BEN_CPF']['field_id'];
                    $cpfBase = preg_replace('/[^0-9]/i', '', $registrations->$field_cpf);

                    $pos = array_search($cpfBase, $cpfCsv);

                    if ($pos) {
                        $agencia = $dePara[$pos]['BEN_AGENCIA'];
                    } else {
                        $temp = $default['formoReceipt'];
                        $formoReceipt = $temp ? $registrations->$temp : false;

                        if ($formoReceipt == "CARTEIRA DIGITAL BB") {
                            $field_id = $default['fieldsWalletDigital']['agency'];
                        } else {
                            $field_id = $detahe1['BEN_AGENCIA']['field_id'];
                        }

                        $agencia = $registrations->$field_id;
                    }
                } else {
                    $agencia = $branch;
                }


                $age = explode("-", $agencia);

                if (count($age) > 1) {
                    $result = $age[0];
                } else {
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
                if (!($branch = $this->bankData($registrations, 'branch'))) {
                    $result = "";
                    $field_cpf = $detahe2['BEN_CPF']['field_id'];
                    $cpfBase = preg_replace('/[^0-9]/i', '', $registrations->$field_cpf);

                    $pos = array_search($cpfBase, $cpfCsv);
                    if ($pos) {
                        $agencia = $dePara[$pos]['BEN_AGENCIA'];
                    } else {
                        $temp = $default['formoReceipt'];
                        $formoReceipt = $temp ? $registrations->$temp : false;

                        if ($formoReceipt == "CARTEIRA DIGITAL BB") {
                            $field_id = $default['fieldsWalletDigital']['agency'];
                        } else {
                            $field_id = $detahe1['BEN_AGENCIA_DIGITO']['field_id'];
                        }

                        $agencia = $registrations->$field_id;
                    }
                } else {
                    $agencia = $branch;
                }


                $age = explode("-", $agencia);

                if (count($age) > 1) {
                    $result = $age[1];
                } else {
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
                if (!($account = $this->bankData($registrations, 'account'))) {
                    $result  = "";
                    $field_cpf = $detahe2['BEN_CPF']['field_id'];
                    $cpfBase = preg_replace('/[^0-9]/i', '', $registrations->$field_cpf);

                    $typeAccount = $registrations->$field_conta;

                    $temp = $detahe1['BEN_CODIGO_BANCO']['field_id'];
                    if ($temp) {
                        $numberBank = $this->numberBank($registrations->$temp);
                    } else {
                        $numberBank = $default['defaultBank'];
                    }

                    $pos = array_search($cpfBase, $cpfCsv);
                    if ($pos) {
                        $temp_account = $dePara[$pos]['BEN_CONTA'];
                    } else {
                        $temp = $default['formoReceipt'];
                        $formoReceipt = $temp ? $registrations->$temp : false;

                        if ($formoReceipt == "CARTEIRA DIGITAL BB") {
                            $field_id = $default['fieldsWalletDigital']['account'];
                        } else {
                            $field_id = $detahe1['BEN_CONTA']['field_id'];
                        }

                        $temp_account = $registrations->$field_id;
                    }
                } else {
                    $typeAccount = $this->bankData($registrations, 'account-type');
                    $numberBank = $this->bankData($registrations, 'bank-number');
                    $temp_account = $account;
                }

                $temp_account = explode("-", $temp_account);
                if (count($temp_account) > 1) {
                    $account = $temp_account[0];
                } else {
                    $account = substr($temp_account[0], 0, -1);
                }

                if (!$account) {
                    $app->log->info($registrations->number . " Conta bancária não informada");
                    return " ";
                }


                if ($typeAccount == $default['typesAccount']['poupanca']) {

                    if (($numberBank == '001') && (substr($account, 0, 2) != "51")) {

                        $account_temp = "51" . $account;

                        if (strlen($account_temp) < 9) {
                            $result = "51" . str_pad($account, 7, 0, STR_PAD_LEFT);
                        } else {
                            $result = "51" . $account;
                        }
                    } else {
                        $result = $account;
                    }
                } else {
                    $result = $account;
                }

                $result = preg_replace('/[^0-9]/i', '', $result);

                if ($dig === $field_conta && $temp_account == 1) {
                    return substr($this->normalizeString($result), 0, -1); // Remove o ultimo caracter. Intende -se que o ultimo caracter é o DV da conta

                } else {
                    return $this->normalizeString($result);
                }
            },
            'BEN_CONTA_DIGITO' => function ($registrations) use ($detahe2, $detahe1, $default, $app, $dePara, $cpfCsv) {
                //Verifica se existe o medadado se sim pega o registro
                if (!($account = $this->bankData($registrations, 'account'))) {
                    $result = "";
                    $field_id = $detahe1['BEN_CONTA']['field_id'];

                    $field_cpf = $detahe2['BEN_CPF']['field_id'];
                    $cpfBase = preg_replace('/[^0-9]/i', '', $registrations->$field_cpf);

                    /**
                     * Caso use um banco padrão para recebimento, pega o número do banco das configs
                     * Caso contrario busca o número do banco na base de dados
                     */
                    $fieldBanco = $detahe1['BEN_CODIGO_BANCO']['field_id'];
                    if ($fieldBanco) {
                        $numberBank = $this->numberBank($registrations->$fieldBanco);
                    } else {
                        $numberBank = $default['defaultBank'];
                    }

                    /**
                     * Verifica se o CPF do requerente consta na lista de de-para dos bancos
                     * se existir, pega os dados bancários do arquivo
                     */
                    $pos = array_search($cpfBase, $cpfCsv);
                    if ($pos) {
                        $temp_account = $dePara[$pos]['BEN_CONTA'];
                    } else {
                        /**
                         * Verifica se existe a opção de forma de recebimento
                         * Caso exista, e seja CARTEIRA DIGITAL BB pega o field id nas configs em (fieldsWalletDigital)
                         */
                        $formaRecebimento = $default['formoReceipt'];
                        $formoReceipt = $formaRecebimento ? $registrations->$formaRecebimento : false;

                        if ($formoReceipt == "CARTEIRA DIGITAL BB") {
                            $temp = $default['fieldsWalletDigital']['account'];
                        } else {
                            $temp = $detahe1['BEN_CONTA_DIGITO']['field_id'];
                        }
                        $temp_account = $registrations->$temp;
                    }
                } else {
                    $typeAccount = $this->bankData($registrations, 'account-type');
                    $numberBank = $this->bankData($registrations, 'bank-number');
                    $temp_account =  $account;
                }

                $temp_account = explode("-", $temp_account);

                if (count($temp_account) > 1) {
                    $dig = substr($temp_account[1], -1);
                } else {
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
                //   $date = new DateTime();                
                //   $date->add(new DateInterval('P5D'));
                //   $weekday = $date->format('D');

                //   $weekdayList = [
                //       'Mon' => true,
                //       'Tue' => true,
                //       'Wed' => true,
                //       'Thu' => true,
                //       'Fri' => true,
                //       'Sat' => false,
                //       'Sun' => false,
                //   ];

                //   while (!$weekdayList[$weekday]) {
                //       $date->add(new DateInterval('P1D'));
                //       $weekday = $date->format('D');
                //   }

                return date("dmY", strtotime($this->data["paymentDate"]));
            },
            'TIPO_MOEDA' => '',
            'USO_BANCO_85' => '',
            'VALOR_INTEIRO' => function ($registrations) use ($detahe1, $app) {
                //   $payment = $app->em->getRepository('\RegistrationPayments\Payment')->findOneBy([
                //       'registration' => $registrations->id
                //   ]);

                return preg_replace('/[^0-9]/i', '', number_format(500, 2, ",", "."));
            },
            'USO_BANCO_88' => '',
            'USO_BANCO_89' => '',
            'OUTRAS_INFO_233A' => function ($registrations) use ($detahe1, $default) {
                //Verifica se existe o medadado se sim pega o registro
                if (!($bank = $this->bankData($registrations, 'bank-number'))) {

                    $temp = $detahe1['BEN_CODIGO_BANCO']['field_id'];

                    $field_conta = $detahe1['TIPO_CONTA']['field_id'];
                    $typeAccount = $registrations->$field_conta;

                    if ($temp) {
                        $numberBank = $this->numberBank($registrations->$temp);
                    } else {
                        $numberBank = $default['defaultBank'];
                    }
                } else {
                    $typeAccount = $this->bankData($registrations, 'account-type');
                    $numberBank = $bank;
                }

                if ($numberBank == "001" && $typeAccount == $default['typesAccount']['poupanca']) {
                    return '11';
                } else {
                    return "";
                }
            },
            'USO_BANCO_90' => '',
            'CODIGO_FINALIDADE_TED' => function ($registrations) use ($detahe1, $default) {
                //Verifica se existe o medadado se sim pega o registro
                if (!($bank = $this->bankData($registrations, 'bank-number'))) {
                    $temp = $detahe1['BEN_CODIGO_BANCO']['field_id'];
                    if ($temp) {
                        $numberBank = $this->numberBank($registrations->$temp);
                    } else {
                        $numberBank = $default['defaultBank'];
                    }
                } else {
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
                $data = preg_replace('/[^0-9]/i', '', $registrations->$field_id);
                if (strlen($this->normalizeString($data)) <= 11) {
                    return 1;
                } else {
                    return 2;
                }
            },
            'BEN_CPF' => function ($registrations) use ($detahe2) {
                $field_id = $detahe2['BEN_CPF']['field_id'];
                $data = preg_replace('/[^0-9]/i', '', $registrations->$field_id);
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

        if ($default['ducumentsType']['unbanked']) { // Caso exista separação entre bancarizados e desbancarizados
            foreach ($registrations as $value) {

                //Caso nao exista pagamento para a inscrição, ele a ignora e notifica na tela                
                if (!$this->validatedPayment($value)) {
                    $app->log->info("\n" . $value->number . " - Pagamento nao encontrado.");
                    continue;
                }

                // Veirifica se existe a pergunta se o requerente é correntista BB ou não no formulário. Se sim, pega a resposta  
                $accountHolderBB = "NÃO";
                if ($selfDeclaredBB) {
                    $accountHolderBB = trim($value->$selfDeclaredBB);
                }

                //Caso nao exista informações bancárias
                if (!$value->$formoReceipt && $selfDeclaredBB === "NÃO") {
                    $app->log->info("\n" . $value->number . " - Forma de recebimento não encontrada.");
                    $noFormoReceipt++;
                    continue;
                }

                //Verifica se a inscrição é bancarizada ou desbancarizada               
                if (in_array(trim($value->$formoReceipt), $typesReceipt['banked']) || $accountHolderBB === "SIM") {
                    $Banked = true;
                    $countBanked++;
                } else if (in_array(trim($value->$formoReceipt), $typesReceipt['unbanked']) || $accountHolderBB === "NÃO") {
                    $Banked = false;
                    $countUnbanked++;
                }

                if ($Banked) {
                    if ($defaultBank) {
                        if ($informDefaultBank === "001" || $accountHolderBB === "SIM") {

                            if (trim($value->$field_TipoConta) === "Conta corrente" || $value->$formoReceipt === "CARTEIRA DIGITAL BB") {
                                $recordsBBCorrente[] = $value;
                            } else if (trim($value->$field_TipoConta) === "Conta poupança") {

                                $recordsBBPoupanca[] = $value;
                            } else {
                                $recordsBBCorrente[] = $value;
                            }
                        } else {
                            $recordsOthers[] = $value;
                        }
                    } else {

                        if (($this->numberBank($value->$field_banco) == "001") || $accountHolderBB == "SIM") {
                            if (trim($value->$field_TipoConta) === "Conta corrente" || $value->$formoReceipt === "CARTEIRA DIGITAL BB") {
                                $recordsBBCorrente[] = $value;
                            } else if (trim($value->$field_TipoConta) === "Conta poupança") {
                                $recordsBBPoupanca[] = $value;
                            } else {
                                $recordsBBCorrente[] = $value;
                            }
                        } else {
                            $recordsOthers[] = $value;
                        }
                    }
                } else {
                    continue;
                }
            }
        } else {

            foreach ($registrations as $value) {
                //Caso nao exista pagamento para a inscrição, ele a ignora e notifica na tela
                // if(!$this->validatedPayment($value)){
                //     $app->log->info("\n".$value->number . " - Pagamento nao encontrado.");
                //     continue;
                // }

                if ($this->numberBank($value->$field_banco) == "001") {

                    if ($value->$field_TipoConta[0] === "Conta corrente") {
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
        if ($default['ducumentsType']['unbanked']) {
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
        if (empty($validaExist)) {
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
                //   $this->processesPayment($records, $app);
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
                //   $this->processesPayment($records, $app);
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
                // $this->processesPayment($records, $app);


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
        $file_name = 'cnab240-' . $opportunity_id . '-' . md5(json_encode($txt_data)) . '.txt';

        $dir = PRIVATE_FILES_PATH . 'auxilioeventos/remessas/cnab240/';

        $patch = $dir . $file_name;

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $stream = fopen($patch, 'w');

        fwrite($stream, $txt_data);

        fclose($stream);

        $tmp_file = array(
            "name" => $file_name,
            "type" => "text/plain",
            "tmp_name" => $dir,
            "path" => "auxilioeventos/remessas/cnab240/" . $file_name,
            "error" => ""
        );

        $this->updatePayments($payments, $tmp_file);
        $this->sendEmails($this->data["emails"], $patch);

        header('Content-Type: application/csv');
        header('Content-Disposition: attachment; filename=' . $file_name);
        header('Pragma: no-cache');
        readfile($patch);
    }

    private function insertNewApproved(int $opportunity_id)
    {
        $app = App::i();

        $payments_registration_id = [];
        $status_approved = Registration::STATUS_APPROVED;
        $payments = $app->repo("SecultCEPayment")->findAll();

        foreach ($payments as $pay) {
            $payments_registration_id[] = $pay->registration->id;
        }

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

        foreach ($registrations as $reg) {
            $payInstallment1 = new SecultCEPayment();
            $payInstallment2 = new SecultCEPayment();

            $registration = $app->repo("Registration")->findOneBy(["id" => $reg["id"]]);

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

    private function mountTxt($array, $mapped, $txt_data, $register, $complement, $app)
    {
        if ($complement) {
            foreach ($complement as $key => $value) {
                $array[$key]['default'] = $value;
            }
        }

        foreach ($array as $key => $value) {
            if ($value['field_id']) {
                if (is_callable($mapped[$key])) {
                    $data = $mapped[$key];
                    $value['default'] = $data($register);
                    $value['field_id'] = null;
                    $txt_data .= $this->createString($value);
                    $value['default'] = null;
                    $value['field_id'] = $value['field_id'];

                    if ($key == "VALOR_INTEIRO") {
                        $inteiro = 0;

                        if ($key == "VALOR_INTEIRO") {
                            $inteiro = $data($register);
                        }

                        $valor = $inteiro;

                        $_SESSION['valor'] = $_SESSION['valor'] + $valor;
                    }
                }
            } else {
                $txt_data .= $this->createString($value);
            }
        }
        return $txt_data;
    }

    private function searchPayments($data)
    {
        $app = App::i();

        $dql = "
          SELECT se FROM MapasCulturais\\Entities\\SecultCEPayment se
          JOIN MapasCulturais\\Entities\\Registration r WITH r.id = se.registration
          WHERE
          se.installment = {$data['installment']}
      ";

        if ($data["registrations"] !== "") {
            $registrations_array = explode(";", $data["registrations"]);
            $registrations_string = implode(", ", $registrations_array);

            $dql .= "AND se.registration IN ($registrations_string)";
        }

      if ($data["remakePayment"]) {
          $dql .= "AND se.status IN (0, 1, 2, 4)";
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

    private function searchRegForPay($payments, $asIterator = false)
    {
        $payments_id = [];

        foreach ($payments as $pay) {
            $payments_id[] = $pay->registration->id;
        }

        $app = App::i();

        $dql = "SELECT r FROM MapasCulturais\\Entities\\Registration r
        WHERE r.id IN (:payments_id)
    ";

        $params = [
            'payments_id' => $payments_id
        ];

        $query = $app->em->createQuery($dql);
        $query->setParameters($params);

        $registrations = $asIterator ? $query->iterate() : $query->getResult();

        if (!$asIterator && empty($registrations)) {
            echo "Não foram encontrados registros.";
            die();
        }

        return $registrations;
    }

    private function updatePayments($payments, $tmp_file)
    {
        $app = App::i();

        $payment_date = date("Y-m-d H:i:s", strtotime($this->data['paymentDate']));
        $sent_date = date("Y-m-d H:i:s", time());
        $create_timestamp = date("Y-m-d H:i:s", time());

        $mime_type = $tmp_file["type"];
        $nameFile = $tmp_file["name"];
        $md5 = md5_file($tmp_file["tmp_name"]);
        $path = $tmp_file["path"];

        foreach ($payments as $p) {
            $payment_id = $p->id;

            $query_insert_file = "
            INSERT INTO file 
            (
                md5, 
                mime_type, 
                name, 
                object_type, 
                object_id, 
                create_timestamp, 
                grp, 
                description, 
                parent_id,
                path,
                private
            ) 
            VALUES 
            (
                '$md5',
                '$mime_type',
                '$nameFile',
                'MapasCulturais\Entities\SecultCEPayment',
                $payment_id,
                '$create_timestamp',
                'payment_$payment_id',
                '',
                null,
                '$path',
                false
            );
        ";

            $stmt_file = $app->em->getConnection()->prepare($query_insert_file);
            $stmt_file->execute();

            $query_select_file = "SELECT max(id) FROM file WHERE object_type = 'MapasCulturais\Entities\SecultCEPayment' AND object_id = $payment_id";

            $stmt_file = $app->em->getConnection()->prepare($query_select_file);
            $stmt_file->execute();
            $file = $stmt_file->fetchAll();
            $file_id = $file[0]['max'];

            $query_update = "
            UPDATE
                public.secultce_payment
            SET
                status = 2
                , payment_date = '$payment_date'
                , generate_file_date = '$sent_date'
                , sent_date = '$sent_date'          
                , payment_file_id = $file_id
            WHERE
                id = $payment_id;
        ";

            $query_insert = "
            INSERT INTO public.secultce_payment_history
                (payment_id, file_id, action, result, file_date, payment_date)
            values
                ($payment_id, $file_id, 'gerar', NULL, '$sent_date', '$payment_date');
        ";

            $stmt_update = $app->em->getConnection()->prepare($query_update);
            $stmt_update->execute();
            $stmt_insert = $app->em->getConnection()->prepare($query_insert);
            $stmt_insert->execute();
        }
    }

    private function sendEmails($emails, $patch = null)
    {
        $app = App::i();
        $message = \Swift_Message::newInstance();
        $failures = [];

        if (empty($emails)) {
            return;
        }

        $setTo = explode(";", $emails);

        $message->setTo($setTo);
        $message->setFrom($app->config['mailer.from']);

        $type = $message->getHeaders()->get('Content-Type');
        $type->setValue('text/html');
        $type->setParameter('charset', 'utf-8');

        $message->setSubject("Arquivo de pagamento CNAB240");
        $message->setBody("
        Bom dia, 
        Segue o arquivo de pagamento CNAB240 para pagamento no dia {$this->data['paymentDate']} em anexo
    ");

        $message->attach(\Swift_Attachment::fromPath($patch));

        $mailer = $app->getMailer();

        if (!is_object($mailer))
            return false;

        try {
            $mailer->send($message, $failures);
            return true;
        } catch (\Swift_TransportException $exception) {
            App::i()->log->info('Swift Mailer error: ' . $exception->getMessage());
            return false;
        }
    }

  public function GET_importFileCnab240() {   
        
    $this->requireAuthentication();

    $app = App::i();

    $opportunity_id = $this->data['opportunity'] ?? 0;
    $file_id = $this->data['file'] ?? 0;

    $opportunity = $app->repo('Opportunity')->find($opportunity_id);

    if (!$opportunity) {
        echo "Opportunidade de id $opportunity_id não encontrada";
    }

    $opportunity->checkPermission('@control');

    $files = $opportunity->getFiles("cnab240-$opportunity_id");
      
    foreach ($files as $file) {
        if ($file->id == $file_id) {                            
            $this->importCnab240($opportunity, $file);
        }
    }

    return;
  }

    /**
     * Processa o retorno do CNAB240 e faz a validação de processado ou não
     */
    private function validatedCanb($code, $seg, $cpf, $inscri, $lote){
        $returnCode = $returnCode = $this->config['config-import-cnab240']['returnCode'];
        $positive = $returnCode['positive'];
        $negative = $returnCode['negative'];
        foreach($positive as $key => $value){
            if($key === $code){
                return [
                    //'seg' => $seg,
                    'lote' => $lote,
                    'inscricao' => $inscri,
                    'cpf' => $cpf,
                    'status' => true,
                    'reason' => $value
                ];
            }
        }

        foreach($negative as $key => $value){
            if($key === $code){
                return [
                    'lote' => $lote,
                    'inscricao' => $inscri,
                    'cpf' => $cpf,
                    'status' => false,
                    'reason' => $value
                ];
            }
        }
    }

    /**
     * Pega o registro dentro de uma determinada posição do CNAB240
     */
    private function getLineData($line, $start, $end){
        $data = "";
        $char = strlen($line);       
        if(!empty($line)){
            for($i=0; $i<$char; $i++){
                if($i>=$start && $i<=$end){
                    $data .= $line[$i];
                    
                }
            }
        }
        return trim($data);
    }

  /**
    * faz o mapeamento do CNAB20... separa os lotes, treiller e header
   */
  private function mappedCnab($file){
    $stream = fopen($file,"r");
    $result = [];
    $countLine = 1;
    while(!feof($stream)){
        $linha = fgets($stream);
        if(!empty($linha)){
            $value = $this->getLineData($linha, 0, 7);
            switch ($value) {
                case '00100000':
                    $result['HEADER_ARQ'][$countLine] = $countLine;
                    $result['HEADER_DATA_ARQ'][$countLine] = $linha;
                    break;
                case '00100011':
                case '00100013':
                case '00100015':
                    $result['LOTE_1'][$countLine] = $countLine;
                    $result['LOTE_1_DATA'][$countLine] = $linha;
                    break;
                case '00100021':
                case '00100023':
                case '00100025':
                    $result['LOTE_2'][$countLine] = $countLine;
                    $result['LOTE_2_DATA'][$countLine] = $linha;
                    break;
                case '00100031':
                case '00100033':
                case '00100035':
                    $result['LOTE_3'][$countLine] = $countLine;
                    $result['LOTE_3_DATA'][$countLine] = $linha;
                    break;
                case '00199999':
                    $result['TREILLER_ARQ'][$countLine] = $countLine;
                    $result['TREILLER_DATA_ARQ'][$countLine] = $linha;
                    break;                    
            }
        }
        $countLine ++;
    }
    return $result;
  }

  private function limpaCPF_CNPJ($valor){
    $valor = trim($valor);
    $valor = str_replace(".", "", $valor);
    $valor = str_replace(",", "", $valor);
    $valor = str_replace("-", "", $valor);
    $valor = str_replace("/", "", $valor);
    return $valor;
   }

  private function importCnab240($opportunity, $file){   

    $app = App::i();
    $conn = $app->em->getConnection();
    // $plugin = $app->plugins['AldirBlanc'];   
    $processingDate = new DateTime();
    $processingDate = $processingDate->format('Y-m-d');    

    $return_date = null;

    $result = [];
    $countLine = 1;
    $countSeg = 1;
    $field_labelMap = [];
    $config = $returnCode = $this->config['config-import-cnab240']['configs'];
    if($field = array_search($opportunity->id, $config['opportunitys'])){
        if(is_string($field)){
            foreach ($opportunity->registrationFieldConfigurations as $fields) {
                if($fields->title == $field){
                    $field_id = "field_" . $fields->id;

                }
            }
        }else{
            $field_id = "field_" .$field;

        }

    }else{
        echo "Essa oportunidade nao é uma oportunidade configurada";
        exit;
    }
    
    $data = $this->mappedCnab($file->getPath());
    
    //Pega a linha do header do lote
    $LOTE1_H = isset($data['LOTE_1']) ? min($data['LOTE_1']) : null;
    $LOTE2_H = isset($data['LOTE_2']) ? min($data['LOTE_2']) : null;
    $LOTE3_H = isset($data['LOTE_3']) ? min($data['LOTE_3']) : null;

    //Pega a linha do trailler do lote
    $LOTE1_T = isset($data['LOTE_1']) ? max($data['LOTE_1']) : null;
    $LOTE2_T = isset($data['LOTE_2']) ? max($data['LOTE_2']) : null;
    $LOTE3_T = isset($data['LOTE_3']) ? max($data['LOTE_3']) : null;

    $query_select = "
        SELECT
            agents_data::json->'owner'->>'documento' as documento,
            id
        FROM
            registration
        WHERE 
            status = 10
            AND opportunity_id = {$opportunity->id};
    ";

    $stmt = $app->em->getConnection()->prepare($query_select);
    $stmt->execute();
    $registrations = $stmt->fetchAll();
    $registrations_formmated = [];

    foreach($registrations as &$reg) {
        $reg["documento"] = substr($this->limpaCPF_CNPJ($reg["documento"]), -11);
    }

    foreach($registrations as $reg) {
        $registrations_formmated[$reg["documento"]] = $reg;
    }

    //Faz a busca nos dados do retorno e monta o array $result com todos os dados 
    foreach($data as $key_data => $value){
        $seg = null;
        $cpf = null;
        $inscri = null;
        $lote = null;
        if($key_data === "HEADER_DATA_ARQ"){
            foreach($value as $key_r => $r){
                //Valida o arquivo
                $n = $this->getLineData($r, 230, 231);
                $result['AQURIVO']['ARQUIVO_STATUS']  = $this->validatedCanb($n, $seg, $cpf, $inscri, $lote);
               
            }
        }else if($key_data === "LOTE_1_DATA"){
            $cont = 1;
            $lote = 'Corrente BB';                   
            foreach($value as $key_r => $r){
                if($key_r == $LOTE1_H){ 
                    //Valida se o lote 1 esta válido
                    $n = $this->getLineData($r, 230, 231);
                    $result['LOTE_1']['LOTE_STATUS']  = $this->validatedCanb($n, $seg, $cpf, $inscri, $lote);

                }elseif($key_r == $LOTE1_T){
                } else { 
                    $seg = $this->getLineData($r, 13, 13);

                    if($seg === "A") {
                        //Valida as inscrições
                        $return_date = $this->getLineData($r, 90, 100);
                        $code = $this->getLineData($r, 230, 231);
                        $result['LOTE_1'][$cont] = $this->validatedCanb($code, $seg, $cpf, $inscri, $lote);

                    } elseif ($seg === "B") {
                        //Pega o tipo de documento CPF ou CNPJ
                        $tipo = $this->getLineData($r, 17, 17);
                        
                        //Pega o CPF da inscrição
                        $cpf_cnpj = $this->getLineData($r, 19, 31);
                        $cpf_cnpj_unformatted = substr($this->getLineData($r, 19, 31), -11);
                        $result['LOTE_1'][$cont] = $this->validatedCanb($code, $seg, $cpf_cnpj, $inscri, $lote);
                        
                        //Firmata o CPF ou CNPJ
                        $cpf_cnpj = preg_replace("/(\d{3})(\d{3})(\d{3})(\d{2})/", "\$1.\$2.\$3-\$4", substr($cpf_cnpj, -11));

                        //Busca o número da inscrição
                        if($this->getLineData($r, 210, 224) != ""){
                            $inscri = $this->getLineData($r, 210, 224);

                        }elseif($this->getLineData($r, 33, 62)!=""){
                            $inscri = $this->getLineData($r, 33, 62);

                        }else{
                            $inscri = false;

                            if (array_key_exists($cpf_cnpj_unformatted, $registrations_formmated)) {
                                $inscri = $registrations_formmated[$cpf_cnpj_unformatted]["id"];
                            } else {
                                $query_insert_secultce_payment_history = "
                                INSERT INTO secultce_payment_history 
                                    (action, result) 
                                VALUES 
                                    ('retorno-nao-encontrado', '$cpf_cnpj_unformatted');";
        
        
                                $stmt = $app->em->getConnection()->prepare($query_insert_secultce_payment_history);
                                $stmt->execute();
                            }

                            // $inscri = $conn->fetchColumn("select id from registration where agents_data::json->'owner'->>'documento' like any(array['%$cpf_cnpj%', '%$cpf_cnpj_unformatted%']) AND opportunity_id = {$opportunity->id}");

                        }
                        $result['LOTE_1'][$cont] = $this->validatedCanb($code, $seg, $cpf_cnpj,  $inscri, $lote);
                    }

                    if($seg === "B"){
                        $cont ++;
                    }
                    
                }
            }
            
        }else if($key_data === "LOTE_2_DATA"){
            
            $cont = 1;
            $lote = 'Poupança BB';                
            foreach($value as $key_r => $r){
                if($key_r == $LOTE2_H){ 
                    //Valida se o lote 2 esta válido
                    $n = $this->getLineData($r, 230, 231);
                    $result['LOTE_2']['LOTE_STATUS'] = $this->validatedCanb($n, $seg, $cpf, $inscri, $lote);

                }elseif($key_r == $LOTE2_T){}else{ 
                    $seg = $this->getLineData($r, 13, 13);

                    if($seg === "A"){
                        //Valida as inscrições
                        $code = $this->getLineData($r, 230, 231);
                        $result['LOTE_2'][$cont] = $this->validatedCanb($code, $seg, $cpf, $inscri, $lote);
                    } elseif ($seg === "B") {
                        // Pega o tipo de documento CPF ou CNPJ
                        $tipo = $this->getLineData($r, 17, 17);
                        
                        //Pega o CPF da inscrição
                        $cpf_cnpj = $this->getLineData($r, 19, 31);
                        $cpf_cnpj_unformatted = substr($this->getLineData($r, 19, 31), -11);
                        $result['LOTE_2'][$cont] = $this->validatedCanb($code, $seg, $cpf_cnpj, $inscri, $lote);
                        
                        //Firmata o CPF ou CNPJ
                        $cpf_cnpj = preg_replace("/(\d{3})(\d{3})(\d{3})(\d{2})/", "\$1.\$2.\$3-\$4", substr($cpf_cnpj, -11));

                        //Busca o número da inscrição
                        if($this->getLineData($r, 210, 224) != ""){
                            $inscri = $this->getLineData($r, 210, 224);

                        }elseif($this->getLineData($r, 33, 62)!=""){
                            $inscri = $this->getLineData($r, 33, 62);

                        }else{
                            $inscri = false;

                            if (array_key_exists($cpf_cnpj_unformatted, $registrations_formmated)) {
                                $inscri = $registrations_formmated[$cpf_cnpj_unformatted]["id"];
                            } else {
                                $query_insert_secultce_payment_history = "
                                INSERT INTO secultce_payment_history 
                                    (action, result) 
                                VALUES 
                                    ('retorno-nao-encontrado', '$cpf_cnpj_unformatted');";
        
        
                                $stmt = $app->em->getConnection()->prepare($query_insert_secultce_payment_history);
                                $stmt->execute();
                            }

                            // $inscri = $conn->fetchColumn("select id from registration where agents_data::json->'owner'->>'documento' like any(array['%$cpf_cnpj%', '%$cpf_cnpj_unformatted%']) AND opportunity_id = {$opportunity->id}");
                        }
                        $result['LOTE_2'][$cont] = $this->validatedCanb($code, $seg, $cpf_cnpj,  $inscri, $lote);
                    }

                    if($seg === "B"){
                        $cont ++;
                    }
                    
                }
            }
        }else if($key_data === "LOTE_3_DATA"){
            $cont = 1;
            $lote = 'Outros Bancos';               
            foreach($value as $key_r => $r){
                if($key_r == $LOTE3_H){ 
                    //Valida se o lote 3 esta válido
                    $n = $this->getLineData($r, 230, 231);
                    $result['LOTE_3']['LOTE_STATUS'] = $this->validatedCanb($n, $seg, $cpf, $inscri, $lote);

                }elseif($key_r == $LOTE3_T){}else{ 
                    $seg = $this->getLineData($r, 13, 13);

                    if($seg === "A") {
                        //Valida as inscrições
                        $code = $this->getLineData($r, 230, 231);
                        $result['LOTE_3'][$cont] = $this->validatedCanb($code, $seg, $cpf, $inscri, $lote);
                    } elseif ($seg === "B") {
                         //Pega o tipo de documento CPF ou CNPJ
                         $tipo = $this->getLineData($r, 17, 17);
                        
                         //Pega o CPF da inscrição
                         $cpf_cnpj = $this->getLineData($r, 19, 31);
                         $cpf_cnpj_unformatted = substr($this->getLineData($r, 19, 31), -11);
                         $result['LOTE_3'][$cont] = $this->validatedCanb($code, $seg, $cpf_cnpj, $inscri, $lote);
                         
                         //Firmata o CPF ou CNPJ
                         $cpf_cnpj = preg_replace("/(\d{3})(\d{3})(\d{3})(\d{2})/", "\$1.\$2.\$3-\$4", substr($cpf_cnpj, -11));

                         //Busca o número da inscrição
                         if($this->getLineData($r, 210, 224) != ""){
                             $inscri = $this->getLineData($r, 210, 224);

                         }elseif($this->getLineData($r, 33, 62)!=""){
                             $inscri = $this->getLineData($r, 33, 62);

                         }else{
                            $inscri = false;

                            if (array_key_exists($cpf_cnpj_unformatted, $registrations_formmated)) {
                                $inscri = $registrations_formmated[$cpf_cnpj_unformatted]["id"];
                            } else {
                                $query_insert_secultce_payment_history = "
                                INSERT INTO secultce_payment_history 
                                    (action, result) 
                                VALUES 
                                    ('retorno-nao-encontrado', '$cpf_cnpj_unformatted');";
        
        
                                $stmt = $app->em->getConnection()->prepare($query_insert_secultce_payment_history);
                                $stmt->execute();
                            }

                            // $inscri = $conn->fetchColumn("select id from registration where agents_data::json->'owner'->>'documento' like any(array['%$cpf_cnpj%', '%$cpf_cnpj_unformatted%']) AND opportunity_id = {$opportunity->id}");

                         }
                         $result['LOTE_3'][$cont] = $this->validatedCanb($code, $seg, $cpf_cnpj,  $inscri, $lote);
                    }

                    if($seg === "B"){
                        $cont ++;
                    }
                    
                }
            }
        }else if($key_data === "TREILLER_DATA_ARQ"){}
    }
  
    //Arrays que serão realmente avaliados no processmento do retorno
    $check = ['LOTE_1', 'LOTE_2', 'LOTE_3'];

    $return_date = DateTime::createFromFormat('dmY', $return_date)->format('Y-m-d H:i:s');
    
    foreach($result as $key_result => $value) {
        if(in_array($key_result, $check)) {
            foreach($value as $key_value => $r) {
                if($key_value != "LOTE_STATUS") {
                    if ($r["inscricao"] != false) {
                        $payment_id = 0;
                        $file_id = $file->id;
                        $status = $r['status'] ? 3: 4;
                        $error = $status == 3 ? "": $r["reason"];
                        $installment = 0;
                        // 1. Verificar se a primeira parcela já foi paga
                            // a. Se foi paga, atualize a parcela dois.
                            // b. Se não foi paga, atualize a parcela um.
                            // c. Se as duas já foram pagar, não fazer nada e botar no log.
    
                        $secultce_payment = $app->repo("SecultCEPayment")->findBy([
                            "registration" => $r["inscricao"]
                        ], ['installment' => 'asc']);
    
                        if ($secultce_payment[0]->status == 3 &&  $secultce_payment[1]->status == 3) {
                            $app->log->info("\n" . $r["inscricao"] . " já possui as duas parcelas pagas");
                            continue;
                        } else if ($secultce_payment[0]->status != 3) {
                            $payment_id = $secultce_payment[0]->id;
                            $installment = 1;
                        } else if ($secultce_payment[1]->status != 3) {
                            $payment_id = $secultce_payment[1]->id;
                            $installment = 2;
                        }
    
    
                        $query_update_secultce_payment = "UPDATE secultce_payment SET status = $status, error = '$error', return_date = '$return_date', return_file_id = $file_id WHERE registration_id = {$r['inscricao']} AND installment = $installment;";
                        
                        $action = "retorno";
                        $resultado = $r["reason"];
                        $file_date = $return_date;
    
                        $query_insert_secultce_payment_history = "
                            INSERT INTO secultce_payment_history 
                                (payment_id, file_id, action, result, file_date) 
                            VALUES 
                                ($payment_id, $file_id, '$action', '$resultado', '$file_date');";
    
    
                        $stmt = $app->em->getConnection()->prepare($query_update_secultce_payment);
                        $stmt->execute();
    
                        $stmt = $app->em->getConnection()->prepare($query_insert_secultce_payment_history);
                        $stmt->execute();
                    }
                }
            }
        }
    }

    $app->disableAccessControl();
    $opportunity = $app->repo("Opportunity")->find($opportunity->id);
    $opportunity->refresh();
    $files = $opportunity->cnab240_eventos_processed_files;
    $files->{basename($file->getPath())} = date("d/m/Y \à\s H:i");
    $opportunity->cnab240_eventos_processed_files = $files;
    $opportunity->save(true);
    $app->enableAccessControl();

    $this->finish("ok");
  }


    public function ALL_bankData()
    {
        $app = App::i();
        $opportunity = $this->config['opportunity_id'];
        $num_inscricao = $this->data['num_inscricao'];
        $banco = $this->data['bank'];
        $agencia = $this->data['agencia'];
        $tipoConta = json_encode($this->data['contaTipe']);
        $conta = $this->data['conta'];
        //var_dump($tipoConta);
        //die();
        $updateBanco = "
            update 
                public.registration_meta
            set
                value = '$banco'
            where 
                object_id = $num_inscricao
                and key = 'field_26529'
                and REPLACE(key, 'field_', '')::int in (select id from public.registration_field_configuration where id = 26529 and opportunity_id = 2852)
        
        ";
        $updateAgencia = "
            update 
                public.registration_meta
            set
                value = '$agencia'
            where 
                object_id = $num_inscricao
                and key = 'field_26530'
                and REPLACE(key, 'field_', '')::int in (select id from public.registration_field_configuration where id = 26530 and opportunity_id = 2852)
        
        ";
        $updateConta = "
            update 
                public.registration_meta
            set
                value = '$conta'
            where 
                object_id = $num_inscricao
                and key  = 'field_26531'
                and REPLACE(key, 'field_', '')::int in (select id from public.registration_field_configuration where id = 26531 and opportunity_id = 2852)
        ";
        $asp = '"';
        $updateTipoConta = "
            update 
                public.registration_meta
            set
                value = '[$tipoConta]' 
            where 
                object_id = $num_inscricao
                and key = 'field_26528'
                and REPLACE(key, 'field_', '')::int in (select id from public.registration_field_configuration where id = 26528 and opportunity_id = 2852)
        ";
        $stmt = $app->em->getConnection()->prepare($updateBanco);
        $stmt->execute();
        $stmt = $app->em->getConnection()->prepare($updateAgencia);
        $stmt->execute();
        $stmt = $app->em->getConnection()->prepare($updateConta);
        $stmt->execute();
        $stmt = $app->em->getConnection()->prepare($updateTipoConta);
        $stmt->execute();
        $app->redirect($app->createUrl('oportunidade', $opportunity, ['mensagem' => 'sucesso']));
    }



    public function ALL_payment()
    {
        if ($this->data["paymentDate"] === "") {
            echo "Escolha a data de pagamento. Me ajude!!";
            return;
        }

        $app = App::i();

        $opportunity = $app->repo('Opportunity')->find($this->config["opportunity_id"]);

        $this->insertNewApproved($this->data["opportunity"]);

        $payments = $this->searchPayments($this->data);
        $registrations = $this->searchRegForPay($payments);

        $this->registerRegistrationMetadata($opportunity);

        $this->generateCnab240($registrations, $payments);
    }
}

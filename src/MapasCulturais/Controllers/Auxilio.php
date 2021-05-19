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

    public function GET_corrigeDados()
    {
        $app = App::i();

        $nao_pagos = array(
            0 => 917527257,
            1 => 285916637,
            2 => 2113510801,
            3 => 1826993411,
            4 => 415609722,
            5 => 1059261964,
            6 => 124015082,
            7 => 1235559272,
            8 => 1976275753,
            9 => 8747317,
            10 => 750552758,
            11 => 1010575906,
            12 => 1894999500,
            13 => 542268820,
            14 => 390713627,
            15 => 1806073051,
            16 => 841939712,
            17 => 761521007,
            18 => 1227678402,
            19 => 1922946798,
            20 => 360774787,
            21 => 207206989,
            22 => 1844879417,
            23 => 1582831280,
            24 => 1722015703,
            25 => 160046517,
            26 => 531793306,
            27 => 2083619086,
            28 => 1469955401,
            29 => 2021853181,
            30 => 1939644598,
            31 => 1091726501,
            32 => 2012827970,
            33 => 1534524883,
            34 => 633858658,
            35 => 1163784976,
            36 => 897137526,
            37 => 1359666254,
            38 => 1078078488,
            39 => 989377723,
            40 => 1835892361,
            41 => 1054249837,
            42 => 637681992,
            43 => 430566326,
            44 => 1352500923,
            45 => 1277716122,
            46 => 2028789463,
            47 => 2132025591,
            48 => 2124815350,
            49 => 1394767802,
            50 => 26932965,
            51 => 749456018,
            52 => 661801283,
            53 => 901805536,
            54 => 2096263420,
            55 => 1179232976,
            56 => 300115201,
            57 => 1185403518,
            58 => 435459938,
            59 => 414157091,
            60 => 1927915144,
            61 => 1366913497,
            62 => 144523283,
            63 => 1823834327,
            64 => 885426789,
            65 => 489352790,
            66 => 2078542041,
            67 => 715288092,
            68 => 1709695915,
            69 => 828246437,
            70 => 1738068477,
            71 => 783741150,
            72 => 1165859399,
            73 => 158643421,
            74 => 2103840663,
            75 => 986640209,
            76 => 1838398488,
            77 => 2073881396,
            78 => 1836098249,
            79 => 457848516,
            80 => 647286197,
            81 => 1364472225,
            82 => 327922901,
            83 => 1822164483,
            84 => 169731898,
            85 => 640945381,
            86 => 453022271,
            87 => 2145332724,
            88 => 1256539926,
            89 => 1142110996,
            90 => 1796300256,
            91 => 31120145,
            92 => 1329554734,
            93 => 1098716948,
            94 => 1075492981,
            95 => 1466866070,
            96 => 1108312446,
            97 => 1564544483,
            98 => 163644683,
            99 => 838714641,
            100 => 925795718,
            101 => 410875648,
            102 => 1264096524,
            103 => 1573539204,
            104 => 1249219616,
            105 => 1227460318,
            106 => 1262961106,
            107 => 794767184,
            108 => 624435171,
            109 => 1801019325,
            110 => 1700067685,
            111 => 1368679830,
            112 => 879662663,
            113 => 1670711524,
            114 => 200065123,
            115 => 1443637866,
            116 => 1906673467,
            117 => 1833956515,
            118 => 992438029,
            119 => 1787514755,
            120 => 1242672612,
            121 => 891434575,
            122 => 868614882,
            123 => 1742706497,
            124 => 1036202845,
            125 => 2071349415,
            126 => 712403857,
            127 => 237233688,
            128 => 900123004,
            129 => 1794214565,
            130 => 1504233013,
            131 => 1611187757,
            132 => 2140186873,
            133 => 746164511,
            134 => 2010758425,
            135 => 110926014,
            136 => 767079901,
            137 => 695609948,
            138 => 1375965442,
            139 => 73446220,
            140 => 148828073,
            141 => 411791079,
            142 => 684812990,
            143 => 1061143387,
            144 => 1862735999,
            145 => 705945505,
            146 => 877850429,
            147 => 1701900011,
            148 => 876314150,
            149 => 1997697268,
            150 => 2000152441,
            151 => 852059510,
            152 => 913169646,
            153 => 46364162,
            154 => 746925077,
            155 => 1182975459,
            156 => 1338198601,
            157 => 1282659082,
            158 => 908028181,
            159 => 182996807,
            160 => 1383520870,
            161 => 227168852,
            162 => 1989110947,
            163 => 719882309,
            164 => 331624190,
            165 => 1386357309,
            166 => 1447766363,
            167 => 1383493842,
            168 => 150364197,
            169 => 2081722307,
            170 => 493395603,
            171 => 1368787390,
            172 => 263688835,
            173 => 2078003562,
            174 => 649154725,
            175 => 924612618,
            176 => 945757055,
            177 => 1635415362,
            178 => 880022911,
            179 => 1322028258,
            180 => 387503519,
            181 => 1294755707,
            182 => 1844576519,
            183 => 670468572,
            184 => 633658815,
            185 => 984847594,
            186 => 1919572518,
            187 => 1814229742,
            188 => 769054709,
            189 => 159551070,
            190 => 1246261527,
            191 => 2028012953,
            192 => 88837028,
            193 => 1653426966,
            194 => 383382156,
            195 => 1559243600,
            196 => 1514649467,
            197 => 1364903861,
            198 => 739480766,
            199 => 702460473,
            200 => 1524844004,
            201 => 986407512,
            202 => 1227469037,
            203 => 296127340,
            204 => 200843694,
            205 => 1996041705,
            206 => 905669494,
            207 => 1472367869,
            208 => 1188629453,
            209 => 807048625,
            210 => 278679631,
            211 => 1519457341,
            212 => 1447108961,
            213 => 390713603,
            214 => 1662765364,
            215 => 62987820,
            216 => 107402722,
            217 => 240015194,
            218 => 927372462,
            219 => 2055383954,
            220 => 581044383,
            221 => 947460574,
            222 => 9679208,
            223 => 1698201028,
            224 => 1395306292,
            225 => 969076552,
            226 => 2089087020,
            227 => 1709029180,
            228 => 1081679991,
            229 => 810728605,
            230 => 1101921794,
            231 => 1936697047,
            232 => 1787677580,
            233 => 316299170,
            234 => 714012837,
            235 => 621018530,
            236 => 675697480,
            237 => 476599292,
            238 => 472723137,
            239 => 260706242,
            240 => 298044068,
            241 => 1636884432,
            242 => 1602644748,
            243 => 1968576583,
            244 => 638694456,
            245 => 1079781618,
            246 => 489690512,
            247 => 881507539,
            248 => 391502679,
            249 => 204585165,
            250 => 1150518061,
            251 => 566971085,
            252 => 1874455775,
            253 => 1120201333,
            254 => 495463393,
            255 => 944036976,
            256 => 1642440599,
            257 => 771457834,
            258 => 1006343547,
            259 => 1965005809,
            260 => 1676586796,
            261 => 1449982314,
            262 => 1446168530,
            263 => 817346074,
            264 => 720410521,
            265 => 735287773,
            266 => 677186897,
            267 => 567107584,
            268 => 1383316445,
            269 => 359875123,
            270 => 1549122453,
            271 => 2090587246,
            272 => 909300999,
            273 => 451240294,
            274 => 1322241021,
            275 => 1614338335,
            276 => 1461532856,
            277 => 501894545,
            278 => 1332829357,
            279 => 148974651,
            280 => 1150753249,
            281 => 519923052,
            282 => 1792070827,
            283 => 220526385,
            284 => 463475244,
            285 => 1721524037,
            286 => 1223489543,
            287 => 288791556,
            288 => 1791096206,
            289 => 1223944259,
            290 => 914581115,
            291 => 1308449405,
            292 => 263115961,
            293 => 579028895,
            294 => 1396591517,
            295 => 1597807979,
            296 => 1011939961,
            297 => 927668705,
            298 => 661745930,
            299 => 779253887,
            300 => 192450846,
            301 => 154460337,
            302 => 196887699,
            303 => 953760773,
            304 => 1687678789,
            305 => 194216267,
            306 => 1743839698,
            307 => 681413025,
            308 => 1743454827,
            309 => 58194377,
            310 => 919451310,
            311 => 760474821,
            312 => 133284511,
            313 => 146103373,
            314 => 34320083,
            315 => 1883522086,
            316 => 54814362,
            317 => 1222716988,
            318 => 927309173,
            319 => 1071569156,
            320 => 1240071794,
            321 => 2061170484,
            322 => 1188164785,
            323 => 1706481626,
            324 => 753167810,
            325 => 836998811,
            326 => 1625133372,
            327 => 724202709,
            328 => 1768212659,
            329 => 1686563977,
            330 => 345846116,
            331 => 972154574,
            332 => 1349765499,
            333 => 452490520,
            334 => 369680485,
            335 => 1774512604,
            336 => 1732902781,
            337 => 607036218,
            338 => 542714084,
            339 => 845655699,
            340 => 1373282973,
            341 => 419496026,
            342 => 1978974366,
            343 => 701345631,
            344 => 1735033580,
            345 => 212870007,
            346 => 1128481643,
            347 => 970723370,
            348 => 1440443871,
            349 => 220120772,
            350 => 424534894,
            351 => 613249174,
            352 => 935197131,
            353 => 303293710,
            354 => 1583760033,
            355 => 209472926,
            356 => 1636217551,
            357 => 1993316004,
            358 => 1700042079,
            359 => 1405203457,
            360 => 888739994,
            361 => 1937565326,
            362 => 382581401,
            363 => 947472447,
            364 => 215563707,
            365 => 1488060876,
            366 => 437062893,
            367 => 657565668,
            368 => 1070448523,
            369 => 1525270213,
            370 => 1657429585,
            371 => 1386278080,
            372 => 2048894327,
            373 => 1842594508,
            374 => 335162278,
            375 => 107191943,
            376 => 1322657558,
            377 => 1573458130,
            378 => 329704279,
            379 => 1462736512,
            380 => 115096618,
            381 => 1718448136,
            382 => 1728172536,
            383 => 1994087495,
            384 => 1956207120,
            385 => 1331941956,
            386 => 1611044484,
            387 => 560980827,
            388 => 487771194,
            389 => 288085896,
            390 => 953872398,
            391 => 104981484,
            392 => 1741002865,
            393 => 1486772543,
            394 => 207301093,
            395 => 896974524,
            396 => 203417678,
            397 => 399208125,
            398 => 1198560883,
            399 => 1934611490,
            400 => 495470767,
            401 => 1498604665,
            402 => 1369792623,
            403 => 1401113574,
            404 => 980133026,
            405 => 547282387,
            406 => 1074250831,
            407 => 1011815528,
            408 => 515439487,
            409 => 1732887495,
            410 => 1760490661,
            411 => 1798529844,
            412 => 9351770,
            413 => 809062746,
            414 => 2130916875,
            415 => 603114673,
            416 => 2087555566,
            417 => 1283430662,
            418 => 172265596,
            419 => 625461164,
            420 => 813841880,
            421 => 2135610217,
            422 => 340746447,
            423 => 797635822,
            424 => 442286924,
            425 => 2014510925,
            426 => 2086828374,
            427 => 651479603,
            428 => 27997454,
            429 => 271504883,
            430 => 330905078,
            431 => 1953033443,
            432 => 1674414793,
            433 => 1248416497,
            434 => 380711556,
            435 => 1009303668,
            436 => 1379642362,
            437 => 1866692943,
            438 => 2025167505,
            439 => 1364708375,
            440 => 1582232392,
            441 => 1555912076,
            442 => 2127141114,
            443 => 1385915467,
            444 => 1964664744,
            445 => 1704471510,
            446 => 2026650585,
            447 => 1611749433,
            448 => 1467122354,
            449 => 382565730,
            450 => 1688932736,
            451 => 1918223654,
            452 => 1224575616,
            453 => 555506445,
            454 => 1852094683,
            455 => 994500714,
            456 => 12520729,
            457 => 2079324593,
            458 => 1496324354,
            459 => 27974655,
            460 => 1522305729,
            461 => 1875074610,
            462 => 1003810454,
            463 => 804076033,
            464 => 649184747,
            465 => 937941126,
            466 => 1334283892,
            467 => 2083434999,
            468 => 576496102,
            469 => 1458019923,
            470 => 1688452311,
            471 => 1964971715,
            472 => 1018837052,
            473 => 1141678153,
            474 => 1420996774,
            475 => 349661270,
            476 => 1799595720,
            477 => 99583021,
            478 => 1437774646,
            479 => 402873285,
            480 => 2105725213,
            481 => 1043571747,
            482 => 1374114580,
            483 => 2135987082,
            484 => 273183731,
            485 => 1505793668,
            486 => 847984551,
            487 => 1906603840,
            488 => 125155024,
            489 => 1278501589,
            490 => 1123160080,
            491 => 1244888920,
            492 => 1590930656,
            493 => 172153221,
            494 => 1078450023,
            495 => 1744445898,
            496 => 322456861,
            497 => 1127831850,
            498 => 1278069357,
            499 => 538743336,
            500 => 121216134,
            501 => 63225230,
            502 => 110317317,
            503 => 99128198,
            504 => 1909804659,
            505 => 1913924457,
            506 => 46378035,
            507 => 1392912030,
            508 => 458307859,
            509 => 554628872,
            510 => 186341146,
            511 => 1896046494,
            512 => 2046600893,
            513 => 1622464626,
            514 => 2044579291,
            515 => 2068192271,
            516 => 894222993,
            517 => 801995411,
            518 => 597380049,
            519 => 606341023,
            520 => 1819063539,
            521 => 2064512079,
            522 => 1126836863,
            523 => 182802633,
            524 => 1898359042,
            525 => 1361299413,
            526 => 1569793759,
            527 => 1270881566,
            528 => 1193786305,
            529 => 455044183,
            530 => 722909603,
            531 => 484546182,
            532 => 1832460809,
            533 => 1139982922,
            534 => 799386082,
            535 => 949046722,
            536 => 2068413615,
            537 => 131194573,
            538 => 1603177277,
            539 => 64279308,
            540 => 1793899806,
            541 => 552027662,
            542 => 607932616,
            543 => 2121391942,
            544 => 1595142854,
            545 => 1477994452,
            546 => 2137852721,
            547 => 1813230302,
            548 => 618380476,
            549 => 36010151,
            550 => 1965275012,
            551 => 1620731614,
            552 => 1734382589,
            553 => 34634351,
            554 => 1114822128,
            555 => 894476290,
            556 => 1257298158,
            557 => 849854606,
            558 => 1832010103,
            559 => 107193706,
            560 => 1682764450,
            561 => 1257300559,
            562 => 840266866,
            563 => 1981574565,
            564 => 1201877492,
            565 => 1879215737,
            566 => 1804015965,
            567 => 249868305,
            568 => 242278241,
            569 => 1026883979,
            570 => 671392397,
            571 => 854961993,
            572 => 1074420475,
            573 => 1028705664,
            574 => 1986465603,
            575 => 731104682,
            576 => 1989581144,
            577 => 1869629156,
            578 => 975217921,
            579 => 214173755,
            580 => 1645015490,
            581 => 889730077,
            582 => 1952619353,
            583 => 565815806,
            584 => 43944974,
            585 => 773345340,
            586 => 849356400,
            587 => 1315135805,
            588 => 799345201,
            589 => 1103021510,
            590 => 1763017085,
            591 => 1824068356,
            592 => 1524845583,
            593 => 1752973119,
            594 => 1627739656,
            595 => 2111981743,
            596 => 686664790,
            597 => 337494538,
            598 => 826561405,
            599 => 1123169564,
            600 => 210025001,
            601 => 1531686124,
            602 => 53280638,
            603 => 629937188,
            604 => 893343464,
            605 => 1927139883,
            606 => 1289265605,
            607 => 65780280,
            608 => 288836230,
            609 => 303951605,
            610 => 1148700091,
            611 => 2045020245,
            612 => 786236986,
            613 => 1056292270,
            614 => 172527405,
            615 => 1174185102,
            616 => 289174547,
            617 => 906860714,
            618 => 2120832477,
            619 => 467059981,
            620 => 830730890,
            621 => 1701017809,
            622 => 1338504729,
            623 => 1234574667,
            624 => 896575140,
            625 => 1648770970,
            626 => 52963434,
            627 => 2029467073,
            628 => 1201938273,
            629 => 197175071,
            630 => 1598421315,
            631 => 2027498140,
            632 => 1804693237,
            633 => 947113579,
            634 => 1283148398,
            635 => 446763295,
            636 => 118109314,
            637 => 1990490024,
            638 => 1256171164,
            639 => 1773384289,
            640 => 2077745753,
            641 => 1720579835,
            642 => 94554293,
            643 => 1048499005,
            644 => 2035310782,
            645 => 773010631,
            646 => 1568849048,
            647 => 1162302805,
            648 => 790013802,
            649 => 1392211945,
            650 => 1552165316,
            651 => 21642438,
            652 => 1026264960,
            653 => 1380605468,
            654 => 1051933819,
            655 => 1510076385,
            656 => 854740585,
            657 => 525682572,
            658 => 1429750582,
            659 => 1125059178,
            660 => 341478892,
            661 => 337977000,
            662 => 235138283,
            663 => 1209230270,
            664 => 973458427,
            665 => 185171345,
            666 => 1060676728,
            667 => 668166450,
            668 => 940008251,
            669 => 1630231740,
            670 => 1150735045,
            671 => 248053834,
            672 => 1135689073,
            673 => 1508374705,
            674 => 1764074185,
            675 => 1200860939,
            676 => 1272040730,
            677 => 1345276966,
            678 => 34394002,
            679 => 2017421494,
            680 => 870301181,
            681 => 55529152,
            682 => 1743193292,
            683 => 93010973,
            684 => 1351912460,
            685 => 389597535,
            686 => 1508742693,
            687 => 1481765129,
            688 => 846170496,
            689 => 1624092458,
            690 => 1224462577,
            691 => 1062542709,
            692 => 2046028324,
            693 => 1743630921,
            694 => 1198538359,
            695 => 2114623411,
            696 => 2121546970,
            697 => 1891393206,
            698 => 41551859,
            699 => 219582352,
            700 => 1877570183,
            701 => 1728898031,
            702 => 1803913175,
            703 => 720827337,
            704 => 884771983,
            705 => 556948474,
            706 => 1688152888,
            707 => 1438615399,
            708 => 221229263,
            709 => 1347224816,
            710 => 350056104,
            711 => 1269765795,
            712 => 1903463663,
            713 => 203119803,
            714 => 1437064644,
            715 => 149189833,
            716 => 1703708848,
            717 => 684662866,
            718 => 411043345,
            719 => 251447444,
            720 => 238174078,
            721 => 828552187,
            722 => 1175869357,
            723 => 713831247,
            724 => 1679964685,
            725 => 1384501344,
            726 => 723433738,
            727 => 1543889209,
            728 => 1178420891,
            729 => 980322837,
            730 => 771542184,
            731 => 1204442492,
            732 => 1776020261,
            733 => 464761444,
            734 => 45618187,
            735 => 1791825605,
            736 => 221960418,
            737 => 2047434426,
            738 => 778803383,
            739 => 166545840,
            740 => 114802416,
            741 => 1228776590,
            742 => 634159280,
            743 => 2063214716,
            744 => 267434344,
            745 => 1527817499,
            746 => 1913320579,
            747 => 2111464123,
            748 => 955524619,
            749 => 268831545,
            750 => 215497591,
            751 => 447672778,
            752 => 1021836136,
            753 => 1829289265,
            754 => 1093427108,
            755 => 35318945,
            756 => 1541918974,
            757 => 1147978049,
            758 => 161482231,
            759 => 751759500,
            760 => 965191346,
            761 => 941131154,
            762 => 421697982,
            763 => 337185428,
            764 => 400407295,
            765 => 225687876,
            766 => 1749865186,
            767 => 1449848192,
            768 => 266568083,
            769 => 1887159390,
            770 => 13754184,
            771 => 1675951749,
            772 => 347335217,
            773 => 754016411,
            774 => 1550445650,
            775 => 1818053633,
            776 => 1375516619,
            777 => 245492903,
            778 => 90999913,
            779 => 509644640,
            780 => 1734512449,
            781 => 434803095,
            782 => 1428532705,
            783 => 843362677,
            784 => 619045,
            785 => 120384837,
            786 => 822712122,
            787 => 1854505321,
            788 => 1422873220,
            789 => 1720944530,
            790 => 1038923858,
            791 => 1337630925,
            792 => 2144213750,
            793 => 324436407,
            794 => 36103205,
            795 => 2096426099,
            796 => 1276248165,
            797 => 692440940,
            798 => 1103815869,
            799 => 863487715,
            800 => 2127365310,
            801 => 399528412,
            802 => 466602839,
            803 => 2007886860,
            804 => 511420648,
            805 => 388731653,
            806 => 1428848145,
            807 => 1479558962,
            808 => 1878657624,
            809 => 1469166723,
            810 => 1778757050,
            811 => 585528597,
            812 => 1211836771,
            813 => 1118458283,
            814 => 1214762907,
            815 => 1170636357,
            816 => 1214041215,
            817 => 1453154808,
            818 => 1348450510,
            819 => 1433845929,
            820 => 1271434952,
            821 => 1689222573,
            822 => 1669081886,
            823 => 2043271353,
            824 => 1005318520,
            825 => 1072401612,
            826 => 289626284,
            827 => 2000019865,
            828 => 1355237866,
            829 => 776331158,
            830 => 759373545,
            831 => 1431603625,
            832 => 266059017,
            833 => 1246452872,
            834 => 1778219786,
            835 => 637446925,
            836 => 86620619,
            837 => 201647997,
            838 => 611831423,
            839 => 1721076006,
            840 => 2035663520,
            841 => 466805271,
            842 => 51581853,
            843 => 995320817,
            844 => 1660260543,
            845 => 2020876884,
            846 => 201593737,
            847 => 1334801533,
            848 => 1591014463,
            849 => 632139491,
            850 => 2138888767,
            851 => 48117747,
            852 => 1391264702,
            853 => 480934095,
            854 => 959603230,
            855 => 124112601,
            856 => 1835257881,
            857 => 477037319,
            858 => 277149923,
            859 => 841797497,
            860 => 1827531667,
            861 => 912622375,
            862 => 1478435941,
            863 => 303643784,
            864 => 1803103560,
            865 => 1326585156,
            866 => 645344678,
            867 => 1319584173,
            868 => 1783190513,
            869 => 2076552382,
            870 => 936960405,
            871 => 2002823180,
            872 => 1682008376,
            873 => 1176577721,
            874 => 2096281384,
            875 => 923391405,
            876 => 762277116,
            877 => 1196223692,
            878 => 851716451,
            879 => 641383748,
            880 => 1590455973,
            881 => 482662340,
            882 => 1961174021,
            883 => 1151970157,
            884 => 109644518,
            885 => 1176852518,
            886 => 102925137,
            887 => 1388813953,
            888 => 1923480668,
            889 => 78201645,
            890 => 1410092441,
            891 => 333958199,
            892 => 2023593803,
            893 => 1847885836,
            894 => 2071480674,
            895 => 1101657131,
            896 => 751887605,
            897 => 1447541652,
            898 => 1259447010,
            899 => 1617371221,
            900 => 1710173090,
            901 => 645811988,
            902 => 625888667,
            903 => 882480012,
            904 => 508587317,
            905 => 1594739887,
            906 => 538351876,
            907 => 56958943,
            908 => 2037894147,
            909 => 70111487,
            910 => 1908485927,
            911 => 613936902,
            912 => 1039845282,
            913 => 1982119943,
            914 => 379935453,
            915 => 1777436856,
            916 => 856725003,
            917 => 1490318402,
            918 => 348508173,
            919 => 1451750403,
            920 => 24365103,
            921 => 307223420,
            922 => 1454451107,
            923 => 2095620773,
            924 => 1095047472,
            925 => 539391813,
            926 => 969896180,
            927 => 1125168188,
            928 => 536595698,
            929 => 1344801184,
            930 => 493990052,
            931 => 1300690139,
            932 => 374375855,
            933 => 1692870443,
            934 => 1586197203,
            935 => 2112340971,
            936 => 316022315,
            937 => 1306730992,
            938 => 1267683269,
            939 => 1944424767,
            940 => 1752213296,
            941 => 21028035,
            942 => 8783786,
            943 => 1679905675,
            944 => 130296879,
            945 => 78608085,
            946 => 2083396122,
            947 => 122206793,
            948 => 1608609790,
            949 => 2095643028,
            950 => 726060055,
            951 => 860399049,
            952 => 1305293564,
            953 => 1360673917,
            954 => 250408483,
            955 => 589300272,
            956 => 2081069794,
            957 => 1805131377,
            958 => 1110856989,
            959 => 661401252,
            960 => 1380452401,
            961 => 404434758,
            962 => 295269650,
            963 => 2079796898,
            964 => 642285177,
            965 => 1065252737,
            966 => 695819614,
            967 => 2055184567,
            968 => 1481337476,
            969 => 320717965,
            970 => 755350623,
            971 => 2109152115,
            972 => 986226262,
            973 => 646393987,
            974 => 139266735,
            975 => 292055728,
            976 => 77046266,
            977 => 342076514,
            978 => 1183312699,
            979 => 1966872299,
            980 => 2025880006,
            981 => 1578291084,
            982 => 462814047,
            983 => 1654911695,
            984 => 1121978495,
            985 => 77151056,
            986 => 1741570320,
            987 => 152478572,
            988 => 922267484,
            989 => 393777853,
            990 => 1740897325,
            991 => 1678063634,
            992 => 178558923,
            993 => 1844178822,
            994 => 1694223215,
            995 => 639445226,
            996 => 1151197351,
            997 => 1911813758,
            998 => 1982705450,
            999 => 1650238246,
            1000 => 1436166198,
            1001 => 1748049215,
            1002 => 1779236432,
            1003 => 169609394,
            1004 => 515582932,
            1005 => 1849468527,
            1006 => 1155026173,
            1007 => 723298991,
            1008 => 1686552679,
            1009 => 4814327,
            1010 => 1746423623,
            1011 => 1824408193,
            1012 => 1909437645,
            1013 => 990547077,
            1014 => 349836540,
            1015 => 556706866,
            1016 => 1741741918,
            1017 => 1676088742,
            1018 => 1803537393,
            1019 => 1896989881,
            1020 => 1113118042,
            1021 => 563964944,
            1022 => 42158551,
            1023 => 390785422,
            1024 => 527575485,
            1025 => 1649377002,
            1026 => 153661706,
            1027 => 2023940677,
            1028 => 569289257,
            1029 => 1185296921,
            1030 => 502239107,
            1031 => 749919683,
            1032 => 641556679,
            1033 => 1489014762,
            1034 => 311227472,
            1035 => 894877539,
            1036 => 482850182,
            1037 => 1134067981,
            1038 => 1651586126,
            1039 => 1812188277,
            1040 => 1844758661,
            1041 => 1834752511,
            1042 => 790565779,
            1043 => 658694974,
            1044 => 178907141,
            1045 => 99886183,
            1046 => 184391984,
            1047 => 85077576,
            1048 => 1997271284,
            1049 => 400439239,
            1050 => 996000401,
            1051 => 779407615,
            1052 => 1148490875,
            1053 => 1744227764,
            1054 => 1506196413,
            1055 => 1881427935,
            1056 => 2136931539,
            1057 => 1306899515,
            1058 => 589921610,
            1059 => 1148428308,
            1060 => 1280140387,
            1061 => 685949957,
            1062 => 1863319441,
            1063 => 1437715020,
            1064 => 1305969621,
            1065 => 1952795191,
            1066 => 1659072094,
            1067 => 1484861502,
            1068 => 1649485588,
            1069 => 248087444,
            1070 => 194387915,
            1071 => 708364302,
            1072 => 1848307521,
            1073 => 1061207059,
            1074 => 71737319,
            1075 => 104215177,
            1076 => 806860362,
            1077 => 939515140,
            1078 => 489003765,
            1079 => 1654808761,
            1080 => 1523883876,
            1081 => 1348015173,
            1082 => 699909287,
            1083 => 1525181826,
            1084 => 785892969,
            1085 => 1327112597,
            1086 => 1162582424,
            1087 => 263059180,
            1088 => 1437714989,
            1089 => 1229823664,
            1090 => 2025410705,
            1091 => 969804594,
            1092 => 1990971034,
            1093 => 1572745006,
            1094 => 1876289219,
            1095 => 300044355,
            1096 => 292442993,
            1097 => 493095403,
            1098 => 1253139930,
            1099 => 759207475,
            1100 => 1552003427,
            1101 => 767618120,
            1102 => 802561415,
            1103 => 1436976371,
            1104 => 913193705,
            1105 => 1645993542,
            1106 => 224706825,
            1107 => 1801013665,
            1108 => 1791029277,
            1109 => 1825026137,
            1110 => 83399689,
            1111 => 2118157958,
            1112 => 1426824351,
            1113 => 1437044352,
            1114 => 1464371332,
            1115 => 292134467,
            1116 => 887633895,
            1117 => 931783602,
            1118 => 306481736,
            1119 => 907522306,
            1120 => 817742545,
            1121 => 1024250929,
            1122 => 2054036746,
            1123 => 29614550,
            1124 => 442242438,
            1125 => 1892498220,
            1126 => 1496463708,
            1127 => 1835517393,
            1128 => 1825861381,
            1129 => 61717229,
            1130 => 324038413,
            1131 => 751269045,
            1132 => 669706851,
            1133 => 964826030,
            1134 => 1316705632,
            1135 => 1177277423,
            1136 => 2093642420,
            1137 => 417327552,
            1138 => 87752123,
            1139 => 1828814285,
            1140 => 1887642110,
            1141 => 2017411582,
            1142 => 1962278437,
            1143 => 2089521633,
            1144 => 286857617,
            1145 => 199942605,
            1146 => 1371378205,
            1147 => 478955054,
            1148 => 949846541,
            1149 => 172933696,
            1150 => 1119556219,
            1151 => 1486907767,
            1152 => 842978477,
            1153 => 132077386,
            1154 => 580829277,
            1155 => 2112819625,
            1156 => 1420138427,
            1157 => 167049432,
            1158 => 1417451656,
            1159 => 723992592,
            1160 => 811935671,
            1161 => 1911867228,
            1162 => 1393299412,
            1163 => 1944374909,
            1164 => 1741085572,
            1165 => 496189677,
            1166 => 721066323,
            1167 => 1592229429,
            1168 => 883503935,
            1169 => 1572468506,
            1170 => 742450383,
            1171 => 1393096245,
            1172 => 1899895292,
            1173 => 1647537703,
            1174 => 686875901,
            1175 => 757617617,
            1176 => 1510113073,
            1177 => 1792963947,
            1178 => 1187848440,
            1179 => 526048041,
            1180 => 1638864750,
            1181 => 2122143294,
            1182 => 734653191,
            1183 => 259436121,
            1184 => 523812371,
            1185 => 242992390,
            1186 => 668698430,
            1187 => 589689348,
            1188 => 1203538550,
            1189 => 456383902,
            1190 => 1360190473,
            1191 => 1840643019,
            1192 => 1340174483,
            1193 => 1580081465,
            1194 => 1025155658,
            1195 => 137811807,
            1196 => 2097600496,
            1197 => 1301132146,
            1198 => 313274716,
            1199 => 188204183,
            1200 => 328847377,
            1201 => 350406545,
            1202 => 275041038,
            1203 => 518575855,
            1204 => 320380502,
            1205 => 516793737,
            1206 => 1901594293,
            1207 => 2024981406,
            1208 => 901185697,
            1209 => 1830734438,
            1210 => 245076298,
            1211 => 270782072,
            1212 => 1560627236,
            1213 => 465629824,
            1214 => 1318372427,
            1215 => 2004357137,
            1216 => 1455998710,
            1217 => 999763626,
            1218 => 819067453,
            1219 => 430979256,
            1220 => 2140517108,
            1221 => 1809212591,
            1222 => 984986453,
            1223 => 775459706,
            1224 => 594353228,
            1225 => 2007760022,
            1226 => 1284183845,
            1227 => 1144526925,
            1228 => 326222488,
            1229 => 788285093,
            1230 => 319860892,
            1231 => 1793508880,
            1232 => 481478684,
            1233 => 1913644318,
            1234 => 783765207,
            1235 => 1767618297,
            1236 => 128751048,
            1237 => 99609036,
            1238 => 17743651,
            1239 => 22998334,
            1240 => 134973462,
            1241 => 1467268827,
            1242 => 29834878,
            1243 => 1615945419,
            1244 => 270580207,
            1245 => 1086837748,
            1246 => 1887827265,
            1247 => 712801524,
            1248 => 945582361,
            1249 => 1464311965,
            1250 => 1849680792,
            1251 => 742193398,
            1252 => 1173800674,
            1253 => 2038186117,
            1254 => 1648726436,
            1255 => 2027633484,
            1256 => 135216368,
            1257 => 1045582425,
            1258 => 1805069624,
            1259 => 1603522228,
            1260 => 1122984909,
            1261 => 1589163529,
            1262 => 31436430,
            1263 => 1558100543,
            1264 => 1001742518,
            1265 => 1067247548,
            1266 => 502682400,
            1267 => 16586190,
            1268 => 845173801,
            1269 => 1118175719,
            1270 => 1452667547,
            1271 => 926689734,
            1272 => 1616144230,
            1273 => 1787204691,
            1274 => 668062256,
            1275 => 1790495099,
            1276 => 2054221725,
            1277 => 1120071827,
            1278 => 450907242,
            1279 => 898150236,
            1280 => 412965683,
            1281 => 387702797,
            1282 => 919832850,
            1283 => 172923772,
            1284 => 763419418,
            1285 => 1470931687,
            1286 => 1897180560,
            1287 => 1505495840,
            1288 => 249436940,
            1289 => 932797910,
            1290 => 1095233464,
            1291 => 1656848495,
            1292 => 1337843334,
            1293 => 1298175336,
            1294 => 313371342,
            1295 => 1265146873,
            1296 => 323888277,
            1297 => 744162861,
            1298 => 1199966282,
            1299 => 1250971609,
            1300 => 982167535,
            1301 => 1551475053,
            1302 => 1477224061,
            1303 => 978220565,
            1304 => 1564607253,
            1305 => 492584647,
            1306 => 1242475588,
            1307 => 1369523254,
            1308 => 217615278,
            1309 => 300594415,
            1310 => 477484355,
            1311 => 137974396,
            1312 => 688454704,
            1313 => 754229254,
            1314 => 2095399153,
            1315 => 10356200,
            1316 => 166358813,
            1317 => 2138529597,
            1318 => 1058284599,
            1319 => 1550882081,
            1320 => 795462695,
            1321 => 2062320684,
            1322 => 218227639,
            1323 => 1950267401,
            1324 => 792869944,
            1325 => 1209464136,
            1326 => 1195778130,
            1327 => 1697970459,
            1328 => 1066200136,
            1329 => 1616659347,
            1330 => 444064151,
            1331 => 1370603362,
            1332 => 1804065951,
            1333 => 1629090691,
            1334 => 116735999,
            1335 => 319930630,
            1336 => 1393166408,
            1337 => 132370772,
            1338 => 1363562665,
            1339 => 1552653847,
            1340 => 1532930455,
            1341 => 1493374268,
            1342 => 812352188,
            1343 => 981333158,
            1344 => 1960075031,
            1345 => 612623236,
            1346 => 998700993,
            1347 => 2056439769,
            1348 => 1866638370,
            1349 => 1476207878,
            1350 => 1073279999,
            1351 => 145882400,
            1352 => 654866209,
            1353 => 1883195318,
            1354 => 1000919766,
            1355 => 526947567,
            1356 => 2067062813,
            1357 => 1765361762,
            1358 => 2029347439,
            1359 => 1938794986,
            1360 => 1829175112,
            1361 => 1866205312,
            1362 => 1723709791,
            1363 => 1136181449,
            1364 => 1261815745,
            1365 => 517011433,
            1366 => 1411152960,
            1367 => 1101978531,
            1368 => 1331270733,
            1369 => 1240912716,
            1370 => 1370320502,
            1371 => 374755764,
            1372 => 615548839,
            1373 => 1414138900,
            1374 => 759312793,
            1375 => 1739241537,
            1376 => 739791792,
            1377 => 1572092849,
            1378 => 1531254905,
            1379 => 899664839,
            1380 => 606216318,
            1381 => 265949692,
            1382 => 1903202143,
            1383 => 512195890,
            1384 => 450079764,
            1385 => 2040293255,
            1386 => 2015136259,
            1387 => 126599427,
            1388 => 1133973405,
            1389 => 2056193836,
            1390 => 682895058,
            1391 => 222066622,
            1392 => 2045357887,
            1393 => 1698139629,
            1394 => 463722383,
            1395 => 25683301,
            1396 => 1168826539,
            1397 => 1654396803,
            1398 => 1545539183,
            1399 => 446865476,
            1400 => 445346075,
            1401 => 214935264,
            1402 => 1214942606,
            1403 => 1221472374,
            1404 => 158924114,
            1405 => 1053456349,
            1406 => 773463188,
            1407 => 582090713,
            1408 => 2018775826,
            1409 => 988035138,
            1410 => 1162358614,
            1411 => 676050855,
            1412 => 341287993,
            1413 => 179202658,
            1414 => 1836622348,
            1415 => 1051822448,
            1416 => 537860228,
            1417 => 1811836,
            1418 => 1415691122,
            1419 => 1132706684,
            1420 => 1939168789,
            1421 => 240604591,
            1422 => 519670137,
            1423 => 1988491625,
            1424 => 1444360546,
            1425 => 336212358,
            1426 => 513971112,
            1427 => 17256779,
            1428 => 1936598999,
            1429 => 1302104494,
            1430 => 1521289199,
            1431 => 712255333,
            1432 => 1652296986,
            1433 => 1477500346,
            1434 => 1780799101,
            1435 => 926298433,
            1436 => 1138291561,
            1437 => 2029311469,
            1438 => 2111485507,
            1439 => 1867568681,
            1440 => 888493320,
            1441 => 836601771,
            1442 => 1818181171,
            1443 => 1536732041,
            1444 => 410507119,
            1445 => 1767029764,
            1446 => 1762632825,
            1447 => 1928200743,
            1448 => 38365525,
            1449 => 609192914,
            1450 => 913654618,
            1451 => 1914895659,
            1452 => 84496991,
            1453 => 1292274091,
            1454 => 692442322,
            1455 => 1137623353,
            1456 => 492965751,
            1457 => 1252293887,
            1458 => 775772953,
            1459 => 1401616145,
            1460 => 1832168847,
            1461 => 984011991,
            1462 => 1484450703,
            1463 => 68900330,
            1464 => 610076807,
            1465 => 1459728693,
            1466 => 2124311850,
            1467 => 519625221,
            1468 => 1945277804,
            1469 => 2132708908,
            1470 => 1859646019,
            1471 => 2043554529,
            1472 => 1446100960,
            1473 => 2107272776,
            1474 => 1464621501,
            1475 => 544804659,
            1476 => 624455513,
            1477 => 1592927363,
            1478 => 504078306,
            1479 => 980100944,
            1480 => 20067338,
            1481 => 212816329,
            1482 => 815221307,
            1483 => 504509960,
            1484 => 1333989294,
            1485 => 1889338341,
            1486 => 290332030,
            1487 => 206485286,
            1488 => 1486138454,
            1489 => 1916310493,
            1490 => 1764373932,
            1491 => 1093762206,
            1492 => 2070920050,
            1493 => 1166195265,
            1494 => 1992560248,
            1495 => 24601158,
            1496 => 308255000,
            1497 => 1887431859,
            1498 => 1547856451,
            1499 => 2097673103,
            1500 => 1505338532,
            1501 => 1052598002,
            1502 => 923409467,
            1503 => 1003030981,
            1504 => 1636554714,
            1505 => 1021756488,
            1506 => 599559463,
            1507 => 854323216,
            1508 => 326252301,
            1509 => 2047038670,
            1510 => 874228243,
            1511 => 1895790979,
            1512 => 2132274019,
            1513 => 965073443,
            1514 => 590450230,
            1515 => 565491588,
            1516 => 170775157,
            1517 => 73774146,
            1518 => 2065117936,
            1519 => 1904067591,
            1520 => 779298762,
            1521 => 1686006050,
            1522 => 1754049295,
            1523 => 895854000,
            1524 => 494312430,
            1525 => 1145604838,
            1526 => 670897429,
            1527 => 1739254346,
            1528 => 1333144880,
            1529 => 1979480015,
            1530 => 1329189623,
            1531 => 931437690,
            1532 => 862177426,
            1533 => 1300799223,
            1534 => 1423169680,
            1535 => 1929466581,
            1536 => 519910069,
            1537 => 996076402,
            1538 => 1183044531,
            1539 => 628212819,
            1540 => 745456229,
            1541 => 1926310901,
            1542 => 916728846,
            1543 => 253838254,
            1544 => 62009609,
            1545 => 1585262634,
            1546 => 1170471888,
            1547 => 1929577262,
            1548 => 1873166131,
            1549 => 1662089055,
            1550 => 1894686566,
            1551 => 1597339499,
            1552 => 1529712266,
            1553 => 132680862,
            1554 => 1685945817,
            1555 => 1922985766,
            1556 => 606513111,
            1557 => 1838493155,
            1558 => 991974859,
            1559 => 216277322,
            1560 => 1008976712,
            1561 => 29261063,
            1562 => 1710944125,
            1563 => 1452322935,
            1564 => 2119762666,
            1565 => 634032554,
            1566 => 1598335801,
            1567 => 319784689,
            1568 => 431604487,
            1569 => 14118116,
            1570 => 1341530094,
            1571 => 662685497,
            1572 => 2073221617,
            1573 => 1571604618,
            1574 => 496002203,
            1575 => 1377045451,
            1576 => 377365869,
            1577 => 1551763988,
            1578 => 1049029662,
            1579 => 1723444892,
            1580 => 1373983243,
            1581 => 2040641804,
            1582 => 363083278,
            1583 => 407459668,
            1584 => 1552973279,
            1585 => 857953557,
            1586 => 946559127,
            1587 => 1121997614,
            1588 => 445612510,
            1589 => 1235974229,
            1590 => 913206082,
            1591 => 189570465,
            1592 => 1274839707,
            1593 => 1720076686,
            1594 => 386044363,
            1595 => 1605319127,
            1596 => 785398011,
            1597 => 385339037,
            1598 => 898823790,
            1599 => 867938384,
            1600 => 114312489,
            1601 => 129881496,
            1602 => 594414171,
            1603 => 986923231,
            1604 => 502224074,
            1605 => 422735788,
            1606 => 1502974451,
            1607 => 467674373,
            1608 => 1480901326,
            1609 => 1213637184,
            1610 => 1304676667,
            1611 => 2068942085,
            1612 => 2131976618,
            1613 => 256254594,
            1614 => 857848121,
            1615 => 1117327611,
            1616 => 310749885,
            1617 => 618350277,
            1618 => 612297455,
            1619 => 1916303612,
            1620 => 1811746975,
            1621 => 860183165,
            1622 => 1132084093,
            1623 => 458756638,
            1624 => 1145336386,
            1625 => 611387021,
            1626 => 1970016514,
            1627 => 2074932110,
            1628 => 28362205,
            1629 => 861849786,
            1630 => 845877201,
            1631 => 139236153,
            1632 => 1573333674,
            1633 => 266832506,
            1634 => 1834533403,
            1635 => 1697499350,
            1636 => 1436343183,
            1637 => 1923662833,
            1638 => 1414279398,
            1639 => 703974199,
            1640 => 567646533,
            1641 => 1970634382,
            1642 => 570681890,
            1643 => 382521854,
            1644 => 655011899,
            1645 => 514388162,
            1646 => 551821839,
            1647 => 767136753,
            1648 => 973826538,
            1649 => 1095787185,
            1650 => 1393991955,
            1651 => 653745772,
            1652 => 1739119565,
            1653 => 1990725558,
            1654 => 1817154668,
            1655 => 1926896884,
            1656 => 895145717,
            1657 => 71829059,
            1658 => 2054721578,
            1659 => 996217028,
            1660 => 1240801363,
            1661 => 48784362,
            1662 => 1342284536,
            1663 => 1233968276,
            1664 => 1261355874,
            1665 => 2081299478,
            1666 => 2015103726,
            1667 => 525838278,
            1668 => 1873546931,
            1669 => 772729646,
            1670 => 111061459,
            1671 => 1631400201,
            1672 => 215416637,
            1673 => 982730221,
            1674 => 1437081381,
            1675 => 1587647346,
            1676 => 866582721,
            1677 => 1789779730,
            1678 => 538411739,
            1679 => 635146628,
            1680 => 404771915,
            1681 => 875058852,
            1682 => 1679132816,
            1683 => 348857781,
            1684 => 1894817816,
            1685 => 191990814,
            1686 => 1127135644,
            1687 => 1129854017,
            1688 => 11692595,
            1689 => 1521136090,
            1690 => 162100034,
            1691 => 113937269,
            1692 => 285406154,
            1693 => 1555004246,
            1694 => 1708776118,
            1695 => 1248980509,
            1696 => 781883140,
            1697 => 924644350,
            1698 => 2025771526,
            1699 => 2078045910,
            1700 => 1410890644,
            1701 => 2106044070,
            1702 => 306690411,
            1703 => 1033112088,
            1704 => 1765465619,
            1705 => 1891988063,
            1706 => 1333374974,
            1707 => 195459505,
            1708 => 674214725,
            1709 => 1367285402,
            1710 => 1653841648,
            1711 => 1414107025,
            1712 => 69299718,
            1713 => 836095334,
            1714 => 146584637,
            1715 => 1508088750,
            1716 => 277688873,
            1717 => 1333402519,
            1718 => 1453023087,
            1719 => 1961402172,
            1720 => 714736963,
            1721 => 805011761,
            1722 => 1102496477,
            1723 => 1032360760,
            1724 => 1045066627,
            1725 => 309419979,
            1726 => 651916278,
            1727 => 274595554,
            1728 => 70306045,
            1729 => 802803041,
            1730 => 61372858,
            1731 => 909509377,
            1732 => 1868020751,
            1733 => 658598635,
            1734 => 472947193,
            1735 => 82266846,
            1736 => 2134056542,
            1737 => 1259606369,
            1738 => 124032279,
            1739 => 510936724,
            1740 => 736260467,
            1741 => 527100767,
            1742 => 1273099428,
            1743 => 623739848,
            1744 => 551815351,
            1745 => 177179108,
            1746 => 1348036120,
            1747 => 1539213903,
            1748 => 1062906077,
            1749 => 462410180,
            1750 => 1535757683,
            1751 => 767368387,
            1752 => 1776406482,
            1753 => 1953943759,
            1754 => 1066238216,
            1755 => 1705942855,
            1756 => 324240081,
            1757 => 935315543,
            1758 => 1366102469,
            1759 => 2114321792,
            1760 => 1024794416,
            1761 => 1643238001,
            1762 => 1077914505,
            1763 => 319494207,
            1764 => 527021972,
            1765 => 1157472857,
            1766 => 1245078677,
            1767 => 1134589650,
            1768 => 1594163786,
            1769 => 1597289730,
            1770 => 2129233844,
            1771 => 997283742,
            1772 => 1555600549,
            1773 => 1823925818,
            1774 => 21166561,
            1775 => 1378168554,
            1776 => 1128489346,
            1777 => 1932505844,
            1778 => 1235046815,
            1779 => 744277686,
            1780 => 919196732,
            1781 => 1235278484,
            1782 => 626314873,
            1783 => 1717162536,
            1784 => 1718374879,
            1785 => 2121320423,
            1786 => 769063772,
            1787 => 733015555,
            1788 => 28118052,
            1789 => 2074536518,
            1790 => 1437434826,
            1791 => 1197017168,
            1792 => 1670516522,
            1793 => 1946006145,
            1794 => 349678749,
            1795 => 715995115,
            1796 => 1993804131,
            1797 => 336532578,
            1798 => 417760182,
            1799 => 944338880,
            1800 => 1030044285,
            1801 => 1878254336,
            1802 => 1933303614,
            1803 => 451888890,
            1804 => 318248663,
            1805 => 758189603,
            1806 => 1458253327,
            1807 => 1870864624,
            1808 => 432027604,
            1809 => 1730817791,
            1810 => 1550032857,
            1811 => 1309608030,
            1812 => 1248393747,
            1813 => 1158799725,
            1814 => 1073099859,
            1815 => 1179452057,
            1816 => 720656060,
            1817 => 186353019,
            1818 => 1945352193,
            1819 => 74950567,
            1820 => 833627098,
            1821 => 2095412231,
            1822 => 490670423,
            1823 => 1288526253,
            1824 => 563702227,
            1825 => 1931493335,
            1826 => 1880012205,
            1827 => 1063283216,
            1828 => 1104016823,
            1829 => 892676126,
            1830 => 451117431,
            1831 => 734253623,
            1832 => 494165907,
            1833 => 313879839,
            1834 => 524533057,
            1835 => 1377903735,
            1836 => 1661396238,
            1837 => 373183953,
            1838 => 643101314,
            1839 => 105523071,
            1840 => 888786685,
            1841 => 140442500,
            1842 => 519616083,
            1843 => 462589498,
            1844 => 1925527904,
            1845 => 1901961220,
            1846 => 1860278036,
            1847 => 241202687,
            1848 => 603213429,
            1849 => 1960560371,
            1850 => 1340773088,
            1851 => 2090424814,
            1852 => 1163346389,
            1853 => 1467803501,
            1854 => 1136094924,
            1855 => 1082882943,
            1856 => 1958751565,
            1857 => 1352821536,
            1858 => 1886365476,
            1859 => 1204108491,
            1860 => 1383301150,
            1861 => 238958396,
            1862 => 2014192310,
            1863 => 2101920753,
            1864 => 1538506102,
            1865 => 1238402390,
            1866 => 1017477444,
            1867 => 2058227328,
            1868 => 613188688,
            1869 => 1856348830,
            1870 => 2024408844,
            1871 => 1224726988,
            1872 => 688288131,
            1873 => 1971196250,
            1874 => 1829786656,
            1875 => 1833379599,
            1876 => 36861605,
            1877 => 799051961,
            1878 => 1924514021,
            1879 => 760632371,
            1880 => 1726789554,
            1881 => 1258485839,
            1882 => 823900562,
            1883 => 1785777047,
            1884 => 1924846866,
            1885 => 847421479,
            1886 => 124626823,
            1887 => 1485783257,
            1888 => 1604683787,
            1889 => 675773292,
            1890 => 345490106,
            1891 => 328833792,
            1892 => 424646674,
            1893 => 942181418,
            1894 => 1447697425,
            1895 => 228835332,
            1896 => 281168876,
            1897 => 318074197,
            1898 => 1114432917,
            1899 => 1090158642,
            1900 => 133768600,
            1901 => 786861054,
            1902 => 1634233946,
            1903 => 1238252834,
            1904 => 1516977876,
            1905 => 1550318091,
            1906 => 868101210,
            1907 => 1827158115,
            1908 => 74734494,
            1909 => 1179845270,
            1910 => 816957448,
            1911 => 1273013817,
            1912 => 1459920159,
            1913 => 1596465299,
            1914 => 102442445,
            1915 => 235497486,
            1916 => 209543924,
            1917 => 1546990014,
            1918 => 241406912,
            1919 => 469969651,
            1920 => 61791592,
            1921 => 15323557,
            1922 => 1175513951,
            1923 => 280222924,
            1924 => 1819193671,
            1925 => 401350004,
            1926 => 16606891,
            1927 => 240145757,
            1928 => 1935875894,
            1929 => 774646260,
            1930 => 1755234728,
            1931 => 1681174432,
            1932 => 1937743283,
            1933 => 1124217733,
            1934 => 1956445071,
            1935 => 1786293705,
            1936 => 1174842281,
            1937 => 569596106,
            1938 => 487770725,
            1939 => 1458018848,
            1940 => 1040756828,
            1941 => 1364688297,
            1942 => 102252802,
            1943 => 1662425025,
            1944 => 488853419,
            1945 => 216722718,
            1946 => 1410065465,
            1947 => 1938538275,
            1948 => 1261247021,
            1949 => 1674757113,
            1950 => 441698509,
            1951 => 27564637,
            1952 => 1771016123,
            1953 => 1872072154,
            1954 => 1776875251,
            1955 => 673501129,
            1956 => 1069760469,
            1957 => 712541803,
            1958 => 1986638202,
            1959 => 1966865957,
            1960 => 1436219206,
            1961 => 1011069435,
            1962 => 1550521478,
            1963 => 1556145790,
            1964 => 265697391,
            1965 => 432600427,
            1966 => 1422610553,
            1967 => 2089091865,
            1968 => 1259272294,
            1969 => 806868341,
            1970 => 186648327,
            1971 => 2130841287,
            1972 => 133117736,
            1973 => 1612107564,
            1974 => 1719808641,
            1975 => 1229956617,
            1976 => 1668287431,
            1977 => 558035101,
            1978 => 432914394,
            1979 => 485152617,
            1980 => 605067780,
            1981 => 1819913081,
            1982 => 700003196,
            1983 => 849623060,
            1984 => 936610125,
            1985 => 401274260,
            1986 => 1600608900,
            1987 => 2031295233,
            1988 => 416553442,
            1989 => 373930632,
            1990 => 464355538,
            1991 => 1250849319,
            1992 => 1400388345,
            1993 => 848151040,
            1994 => 1260820520,
            1995 => 1268623515,
            1996 => 1463508086,
            1997 => 1516804905,
            1998 => 1144554833,
            1999 => 690944361,
            2000 => 2101139459,
            2001 => 1215441339,
            2002 => 1689453054,
            2003 => 1485401793,
            2004 => 900090275,
            2005 => 142989665,
            2006 => 1944227870,
            2007 => 529411325,
            2008 => 1688940230,
            2009 => 1762031380,
            2010 => 852732566,
            2011 => 272176407,
            2012 => 1005289385,
            2013 => 1362081389,
            2014 => 2113459473,
            2015 => 1305363535,
            2016 => 1828552263,
            2017 => 892644753,
            2018 => 1067545185,
            2019 => 1033095505,
            2020 => 1803793771,
            2021 => 1260035891,
            2022 => 1556815562,
            2023 => 230404676,
            2024 => 391743810,
            2025 => 1930602794,
            2026 => 612619381,
            2027 => 1431073241,
            2028 => 708451493,
            2029 => 883905434,
            2030 => 391630857,
            2031 => 22510034,
            2032 => 870871146,
            2033 => 1010533930,
            2034 => 2064584673,
            2035 => 999785009,
            2036 => 662398932,
            2037 => 877150116,
            2038 => 1730372162,
            2039 => 1015146808,
            2040 => 1532628112,
            2041 => 97869301,
            2042 => 1551880011,
            2043 => 814995412,
            2044 => 290483585,
            2045 => 2114855314,
            2046 => 963297350,
            2047 => 1258144417,
            2048 => 879943516,
            2049 => 2116181722,
            2050 => 1180692687,
            2051 => 187150559,
            2052 => 1607516591,
            2053 => 1126122419,
            2054 => 1543777637,
            2055 => 1599191626,
            2056 => 352529678,
            2057 => 2038498643,
            2058 => 516722828,
            2059 => 509595372,
            2060 => 1572343073,
            2061 => 1982525131,
            2062 => 1336277726,
            2063 => 1170115039,
            2064 => 1298457174,
            2065 => 38277095,
            2066 => 661738500,
            2067 => 145260658,
            2068 => 1116625897,
            2069 => 885879179,
            2070 => 206433137,
            2071 => 2145487531,
            2072 => 1296061547,
            2073 => 327944866,
            2074 => 1999346655,
            2075 => 159961372,
            2076 => 1567606871,
            2077 => 1914968853,
            2078 => 176891221,
            2079 => 2090441626,
            2080 => 1983679172,
            2081 => 2017368296,
            2082 => 2044545017,
            2083 => 202888925,
            2084 => 1246083023,
            2085 => 1391242489,
            2086 => 1821347907,
            2087 => 375553097,
            2088 => 817094586,
            2089 => 1751320001,
            2090 => 1748811618,
            2091 => 607995126,
            2092 => 56420488,
            2093 => 1127340894,
            2094 => 1073627827,
            2095 => 879447602,
            2096 => 392038698,
            2097 => 704401810,
            2098 => 1862486017,
            2099 => 1986966925,
            2100 => 474357504,
            2101 => 1904270437,
            2102 => 598953499,
            2103 => 995285398,
            2104 => 443502198,
            2105 => 973270570,
            2106 => 406250195,
            2107 => 680452400,
            2108 => 742345250,
            2109 => 240071210,
            2110 => 548573472,
            2111 => 727679440,
            2112 => 1561501291,
            2113 => 839496452,
            2114 => 871583974,
            2115 => 1251196886,
            2116 => 1891529435,
            2117 => 759279548,
            2118 => 1509332331,
            2119 => 1836500963,
            2120 => 1149931938,
            2121 => 1231176896,
            2122 => 1831837984,
            2123 => 1239262334,
            2124 => 779677677,
            2125 => 1195268466,
            2126 => 1060528903,
            2127 => 2120160709,
            2128 => 2016093900,
            2129 => 156804014,
            2130 => 1182549840,
            2131 => 1512577842,
            2132 => 311452136,
            2133 => 189453208,
            2134 => 159852463,
            2135 => 1934259225,
            2136 => 251848685,
            2137 => 628521775,
            2138 => 1811781495,
            2139 => 696968670,
            2140 => 1547816901,
            2141 => 352363532,
            2142 => 548260245,
            2143 => 947969483,
            2144 => 2043189983,
            2145 => 1325591801,
            2146 => 222346521,
            2147 => 1647887426,
            2148 => 1670658159,
            2149 => 1680328955,
            2150 => 177886742,
            2151 => 1551756265,
            2152 => 1981793966,
            2153 => 804400430,
            2154 => 1037613044,
            2155 => 212912559,
            2156 => 265573197,
            2157 => 1855361125,
            2158 => 1306964642,
            2159 => 403738033,
            2160 => 327716912,
            2161 => 539197713,
            2162 => 140171763,
            2163 => 1734415385,
            2164 => 1849342491,
            2165 => 241164469,
            2166 => 1046020204,
            2167 => 1624366119,
            2168 => 67734366,
            2169 => 492003438,
            2170 => 299196319,
            2171 => 1596917867,
            2172 => 587224264,
            2173 => 213638115,
            2174 => 241757088,
            2175 => 1096157080,
            2176 => 1790997361,
            2177 => 2045161722,
            2178 => 856450981,
            2179 => 1498642711,
            2180 => 1890714700,
            2181 => 1216800614,
            2182 => 684500404,
            2183 => 594757615,
            2184 => 2054006578,
            2185 => 2027994735,
            2186 => 1579590838,
            2187 => 77243526,
            2188 => 1184006984,
            2189 => 1490020708,
            2190 => 235168365,
            2191 => 324281078,
            2192 => 735932451,
            2193 => 1943577189,
            2194 => 620417525,
            2195 => 2059590812,
            2196 => 1082934791,
            2197 => 262722063,
            2198 => 1801164727,
            2199 => 1558699662,
            2200 => 825415156,
            2201 => 370930411,
            2202 => 2123689122,
            2203 => 1487492412,
            2204 => 391966792,
            2205 => 316515254,
            2206 => 921129667,
            2207 => 1825862472,
            2208 => 611237879,
            2209 => 1405775769,
            2210 => 358777553,
            2211 => 1513400418,
            2212 => 1316889401,
            2213 => 1953972531,
            2214 => 47417506,
            2215 => 860096949,
            2216 => 515315322,
            2217 => 1893256509,
            2218 => 1154193446,
            2219 => 134513111,
            2220 => 751708773,
            2221 => 1378477673,
            2222 => 627090314,
            2223 => 2135800953,
            2224 => 1468110120,
            2225 => 2009301915,
            2226 => 1864207733,
            2227 => 1203220075,
            2228 => 1516079841,
            2229 => 1252763125,
            2230 => 917503986,
            2231 => 612086312,
            2232 => 450049232,
            2233 => 794700377,
            2234 => 205839106,
            2235 => 531402239,
            2236 => 1829238338,
            2237 => 2049106170,
            2238 => 252103203,
            2239 => 1229634693,
            2240 => 989741925,
            2241 => 1372654816,
            2242 => 713534919,
            2243 => 1651132414,
            2244 => 1491140052,
            2245 => 152655559,
            2246 => 710602535,
            2247 => 1824918827,
            2248 => 1246952424,
            2249 => 1942963710,
            2250 => 2056943075,
            2251 => 1060527205,
            2252 => 1149192111,
            2253 => 2006987226,
            2254 => 1519711313,
            2255 => 2134596540,
            2256 => 826845897,
            2257 => 405121204,
            2258 => 716170157,
            2259 => 885371237,
            2260 => 1557750893,
            2261 => 932725037,
            2262 => 1116344589,
            2263 => 1845064150,
            2264 => 523276059,
            2265 => 329207497,
            2266 => 1590933474,
            2267 => 1834438335,
            2268 => 1119436328,
            2269 => 1027435894,
            2270 => 409221051,
            2271 => 241138347,
            2272 => 429848180,
            2273 => 1499934339,
            2274 => 1405385088,
            2275 => 1850907025,
            2276 => 826557106,
            2277 => 1196664502,
            2278 => 776317153,
            2279 => 763226228,
            2280 => 1501745714,
            2281 => 254486483,
            2282 => 217769901,
            2283 => 718081661,
            2284 => 973710313,
            2285 => 224529213,
            2286 => 899273179,
            2287 => 565476654,
            2288 => 1440613406,
            2289 => 80906803,
            2290 => 1983998909,
            2291 => 1216330629,
            2292 => 2031490692,
            2293 => 2121362239,
            2294 => 990723868,
            2295 => 2022915811,
            2296 => 439689656,
            2297 => 800579014,
            2298 => 2116705768,
            2299 => 256332302,
            2300 => 1865686765,
            2301 => 110919759,
            2302 => 828491110,
            2303 => 1945137807,
            2304 => 717162712,
            2305 => 408787707,
            2306 => 2021521780,
            2307 => 955114729,
            2308 => 1756157628,
            2309 => 1778770211,
            2310 => 837617993,
            2311 => 1615674256,
            2312 => 724726501,
            2313 => 60138932,
            2314 => 154976708,
            2315 => 53421454,
            2316 => 267354726,
            2317 => 1679516187,
            2318 => 529099532,
            2319 => 1221926115,
            2320 => 615161142,
            2321 => 1409563593,
            2322 => 289230861,
            2323 => 1401947487,
            2324 => 524854905,
            2325 => 647732582,
            2326 => 1076748281,
            2327 => 357649181,
            2328 => 932576056,
            2329 => 707892342,
            2330 => 1427769184,
            2331 => 1506908212,
            2332 => 196510544,
            2333 => 1014487212,
            2334 => 46904758,
            2335 => 2020780050,
            2336 => 623871567,
            2337 => 629656538,
            2338 => 1362128164,
            2339 => 1798365632,
            2340 => 13564421,
            2341 => 1079487317,
            2342 => 1062576575,
            2343 => 2001385550,
            2344 => 2055298274,
            2345 => 1447093314,
            2346 => 23283355,
            2347 => 928022045,
            2348 => 1148647799,
            2349 => 2140918274,
            2350 => 67810860,
            2351 => 830647885,
            2352 => 8744639,
            2353 => 996015897,
            2354 => 2145842222,
            2355 => 1416948810,
            2356 => 1221034188,
            2357 => 882486517,
            2358 => 21882406,
            2359 => 1931666789,
            2360 => 432465259,
            2361 => 730823300,
            2362 => 1335489916,
            2363 => 2141046865,
            2364 => 80465239,
            2365 => 83373655,
            2366 => 2095046690,
            2367 => 1017739568,
            2368 => 449790574,
            2369 => 1321231374,
            2370 => 2035906398,
            2371 => 570788744,
            2372 => 1544277806,
            2373 => 837231537,
            2374 => 782645654,
            2375 => 1281401762,
            2376 => 557592364,
            2377 => 529413491,
            2378 => 1822093569,
            2379 => 1075367488,
            2380 => 1250314689,
            2381 => 1050789368,
            2382 => 1015767966,
            2383 => 890360206,
            2384 => 1259866448,
            2385 => 1660096122,
            2386 => 405132059,
            2387 => 1944996776,
            2388 => 1313200959,
            2389 => 569967764,
            2390 => 572393360,
            2391 => 1173234060,
            2392 => 2130925275,
            2393 => 2068412189,
            2394 => 1056767372,
            2395 => 1250474536,
            2396 => 1548660344,
            2397 => 726167878,
            2398 => 1442743815,
            2399 => 509149568,
            2400 => 1849781989,
            2401 => 1388351275,
            2402 => 2140765794,
            2403 => 1290787859,
            2404 => 253129506,
            2405 => 1754206347,
            2406 => 1516805428,
            2407 => 171936714,
            2408 => 382227348,
            2409 => 1072569753,
            2410 => 86326185,
            2411 => 40685265,
            2412 => 625105416,
            2413 => 1053760405,
            2414 => 2080265635,
            2415 => 5379464,
            2416 => 1627323932,
            2417 => 556810258,
            2418 => 1142943138,
            2419 => 1532881428,
            2420 => 1117446719,
            2421 => 1696191191,
            2422 => 10815710,
            2423 => 1768317067,
            2424 => 1949717123,
            2425 => 724979951,
            2426 => 2110179909,
            2427 => 2056478136,
            2428 => 1191321236,
            2429 => 1291560115,
            2430 => 2085671309,
            2431 => 1102819062,
            2432 => 420866539,
            2433 => 1881152542,
            2434 => 1552087603,
            2435 => 557868660,
            2436 => 730150463,
            2437 => 1082834665,
            2438 => 1730360049,
            2439 => 1767943250,
            2440 => 1997532312,
            2441 => 291262426,
            2442 => 1896236057,
            2443 => 687874486,
            2444 => 67451449,
            2445 => 1746523787,
            2446 => 1100281653,
            2447 => 1234249267,
            2448 => 1185470118,
            2449 => 638810790,
            2450 => 1980042860,
            2451 => 1578191844,
            2452 => 636046115,
            2453 => 2058561400,
            2454 => 1952961821,
            2455 => 509536730,
            2456 => 143694859,
            2457 => 1346905863,
            2458 => 590194478,
            2459 => 1536837220,
            2460 => 1590243677,
            2461 => 1652186716,
            2462 => 1831252452,
            2463 => 495360099,
            2464 => 157742233,
            2465 => 239092013,
            2466 => 676070284,
            2467 => 1240885538,
            2468 => 1174082298,
            2469 => 1814925338,
            2470 => 250561309,
            2471 => 145327755,
            2472 => 1383312469,
            2473 => 829043017,
            2474 => 1079941389,
            2475 => 64431730,
            2476 => 1247474544,
            2477 => 2072620779,
            2478 => 514879779,
            2479 => 177629685,
            2480 => 1584573737,
            2481 => 1685750102,
            2482 => 262812870,
            2483 => 2090187406,
            2484 => 1708238051,
            2485 => 1346989265,
            2486 => 1819143349,
            2487 => 818313290,
            2488 => 69451750,
            2489 => 201889185,
            2490 => 75710641,
            2491 => 1663461283,
            2492 => 9746320,
            2493 => 2065663691,
            2494 => 1134289122,
            2495 => 189454912,
            2496 => 1179874645,
            2497 => 1908921460,
            2498 => 1215407299,
            2499 => 1757842128,
            2500 => 796767503,
            2501 => 1732644759,
            2502 => 836310213,
            2503 => 732600345,
            2504 => 881645033,
            2505 => 418582129,
            2506 => 1137160961,
            2507 => 330316872,
            2508 => 4042099,
            2509 => 1716723894,
            2510 => 1834898526,
            2511 => 2095875920,
            2512 => 677013849,
            2513 => 728868504,
            2514 => 2018120201,
            2515 => 1492683570,
            2516 => 1579153309,
            2517 => 998772768,
            2518 => 1246263668,
            2519 => 1082813574,
            2520 => 705037666,
            2521 => 1784291917,
            2522 => 863445072,
            2523 => 1297524077,
            2524 => 1085280316,
            2525 => 1292408391,
            2526 => 687989179,
            2527 => 418108424,
            2528 => 415854995,
            2529 => 1035818568,
            2530 => 63989972,
            2531 => 30350153,
            2532 => 1449042761,
            2533 => 1086680436,
            2534 => 1415476207,
            2535 => 48807752,
            2536 => 1434906247,
            2537 => 1141873772,
            2538 => 546978215,
            2539 => 1733176426,
            2540 => 1552717473,
            2541 => 691636867,
            2542 => 2121223525,
            2543 => 1243687464,
            2544 => 1812160040,
            2545 => 781572420,
            2546 => 1455733714,
            2547 => 1464328888,
            2548 => 1303624168,
            2549 => 775212008,
            2550 => 940090094,
            2551 => 1795707114,
            2552 => 537875619,
            2553 => 1183507573,
            2554 => 3861740,
            2555 => 652622597,
            2556 => 1689727524,
            2557 => 1958612875,
            2558 => 1532261368,
            2559 => 1164305503,
            2560 => 1018372244,
            2561 => 1045481066,
            2562 => 281000548,
            2563 => 1048224656,
            2564 => 167642919,
            2565 => 1740245789,
            2566 => 1083197447,
            2567 => 30968126,
            2568 => 992342667,
            2569 => 301323471,
            2570 => 131174183,
            2571 => 643788331,
            2572 => 1405711268,
            2573 => 1743312693,
            2574 => 1887372450,
            2575 => 1887540704,
            2576 => 1031898871,
            2577 => 527281222,
            2578 => 1535222770,
            2579 => 968724175,
            2580 => 1944333185,
            2581 => 1737949632,
            2582 => 525641362,
            2583 => 748887446,
            2584 => 23844983,
            2585 => 1131128547,
            2586 => 48298911,
            2587 => 561799359,
            2588 => 1372692183,
            2589 => 956340674,
            2590 => 2087987211,
            2591 => 975051488,
            2592 => 2109370475,
            2593 => 1409459823,
            2594 => 2018493802,
            2595 => 1455936372,
            2596 => 112029328,
            2597 => 1644884786,
            2598 => 1384360316,
            2599 => 1990519367,
            2600 => 711671535,
            2601 => 787171674,
            2602 => 2075016915,
            2603 => 1282010946,
            2604 => 452478495,
            2605 => 523858374,
            2606 => 887537880,
            2607 => 1978691316,
            2608 => 545584972,
            2609 => 1695904544,
            2610 => 1070126774,
            2611 => 426162543,
            2612 => 1562895077,
            2613 => 1874679972,
            2614 => 2055985490,
            2615 => 2108199114,
            2616 => 810343724,
            2617 => 494279919,
            2618 => 1410262291,
            2619 => 364021718,
            2620 => 1570410369,
            2621 => 2062273355,
            2622 => 1610231056,
            2623 => 208754043,
            2624 => 985863403,
            2625 => 2110861992,
            2626 => 1720695049,
            2627 => 1905068647,
            2628 => 1802546779,
            2629 => 713842967,
            2630 => 1268655565,
            2631 => 1156658228,
            2632 => 1519783690,
            2633 => 894478903,
            2634 => 252300708,
            2635 => 171656041,
            2636 => 680621628,
            2637 => 1794673310,
            2638 => 1247598271,
            2639 => 63590015,
            2640 => 746975904,
            2641 => 1274589080,
            2642 => 1959778152,
            2643 => 1115393684,
            2644 => 1712581487,
            2645 => 1375728001,
            2646 => 1427890839,
            2647 => 861152154,
            2648 => 1972546428,
            2649 => 1021726777,
            2650 => 1766340883,
            2651 => 406034524,
            2652 => 1742228180,
            2653 => 1900311553,
            2654 => 2002696019,
            2655 => 959032112,
            2656 => 1219809621,
            2657 => 1516435775,
            2658 => 1833902910,
            2659 => 1889740835,
            2660 => 1032008137,
            2661 => 124106137,
            2662 => 647672737,
            2663 => 736687162,
            2664 => 2033167389,
            2665 => 1961055311,
            2666 => 1339161476,
            2667 => 1719256653,
            2668 => 902223223,
            2669 => 1454800303,
            2670 => 443015854,
            2671 => 1163206824,
            2672 => 274697160,
            2673 => 1932068107,
            2674 => 399929695,
            2675 => 668529308,
            2676 => 340058995,
            2677 => 1156733964,
            2678 => 727138585,
            2679 => 1483224890,
            2680 => 877233839,
            2681 => 2096446342,
            2682 => 195502790,
            2683 => 2033837420,
            2684 => 52536281,
            2685 => 1880085297,
            2686 => 426334946,
            2687 => 1168195306,
            2688 => 1551059118,
            2689 => 393998282,
            2690 => 1605496921,
            2691 => 397183684,
            2692 => 1337314160,
            2693 => 322407325,
            2694 => 873600542,
            2695 => 1482860672,
            2696 => 1194966963,
            2697 => 166247998,
            2698 => 1186281656,
            2699 => 2051635774,
            2700 => 1329019638,
            2701 => 1329635968,
            2702 => 257122566,
            2703 => 92821952,
            2704 => 1178246901,
            2705 => 1288016396,
            2706 => 1868088312,
            2707 => 1655772810,
            2708 => 43664389,
            2709 => 2041625192,
            2710 => 559593183,
            2711 => 1232226101,
            2712 => 1710355834,
            2713 => 1439233749,
            2714 => 2039877835,
            2715 => 1341207806,
            2716 => 1808829901,
            2717 => 537144721,
            2718 => 1660373120,
            2719 => 106357229,
            2720 => 191463226,
            2721 => 654404290,
            2722 => 33655322,
            2723 => 1452736999,
            2724 => 913710909,
            2725 => 1559794789,
            2726 => 1337490755,
            2727 => 868945572,
            2728 => 688778370,
            2729 => 1337812900,
            2730 => 1649137804,
            2731 => 1356833410,
            2732 => 1305564380,
            2733 => 816031080,
            2734 => 1416508330,
            2735 => 1129716754,
            2736 => 1657607457,
            2737 => 1083920674,
            2738 => 1507947260,
            2739 => 1441468661,
            2740 => 2026782104,
            2741 => 545846276,
            2742 => 645616080,
            2743 => 1033215471,
            2744 => 470161732,
            2745 => 789806545,
            2746 => 1395199160,
            2747 => 553967866,
            2748 => 1879620202,
            2749 => 943453440,
            2750 => 1498770477,
            2751 => 2137451436,
            2752 => 427152754,
            2753 => 860402894,
            2754 => 263780373,
            2755 => 2054354971,
            2756 => 552890377,
            2757 => 2034621849,
            2758 => 1853329259,
            2759 => 1910915991,
            2760 => 1055476266,
            2761 => 2101183325,
            2762 => 1368370694,
            2763 => 662436631,
            2764 => 1758301660,
            2765 => 939365240,
            2766 => 581420544,
            2767 => 2004073285,
            2768 => 2143508392,
            2769 => 1276718679,
            2770 => 1586933784,
            2771 => 1239347795,
            2772 => 223533392,
            2773 => 639607171,
            2774 => 421627024,
            2775 => 957117276,
            2776 => 1825295509,
            2777 => 548969353,
            2778 => 488491257,
            2779 => 2002134799,
            2780 => 931567460,
            2781 => 1995900246,
            2782 => 1329305726,
            2783 => 1807728040,
            2784 => 1315014491,
            2785 => 1342698550,
            2786 => 314930857,
            2787 => 476790758,
            2788 => 612701009,
            2789 => 953853113,
            2790 => 1288168040,
            2791 => 1615658409,
            2792 => 987845761,
            2793 => 1407196076,
            2794 => 877466340,
            2795 => 437054255,
            2796 => 879821514,
            2797 => 169974607,
            2798 => 175939437,
            2799 => 1696958363,
            2800 => 436350241,
            2801 => 1285494159,
            2802 => 1022212056,
            2803 => 639604198,
            2804 => 433168543,
            2805 => 98940506,
            2806 => 1097763045,
            2807 => 1516696631,
            2808 => 1004131542,
            2809 => 905451404,
            2810 => 44078976,
            2811 => 766505902,
            2812 => 54567740,
            2813 => 1627373864,
            2814 => 1294667707,
            2815 => 1354327329,
            2816 => 861034118,
            2817 => 1128310688,
            2818 => 441885918,
            2819 => 897148018,
            2820 => 1821910923,
            2821 => 1372826731,
            2822 => 1051429039,
            2823 => 1259211709,
            2824 => 193097838,
            2825 => 459562160,
            2826 => 1290346610,
            2827 => 866300063,
            2828 => 2038844444,
            2829 => 1804975607,
            2830 => 1381575906,
            2831 => 1984329200,
            2832 => 216025188,
            2833 => 1636578987,
            2834 => 1885706593,
            2835 => 1196879698,
            2836 => 1829734557,
            2837 => 1559353718,
            2838 => 570485443,
            2839 => 509126109,
            2840 => 1289312075,
            2841 => 1946034415,
            2842 => 639171066,
            2843 => 1883292769,
            2844 => 766519325,
            2845 => 1951063549,
            2846 => 1974930660,
            2847 => 2046450440,
            2848 => 446984005,
            2849 => 1015903419,
            2850 => 1642122603,
            2851 => 463054952,
            2852 => 872960251,
            2853 => 1408997172,
            2854 => 155873501,
            2855 => 1916145411,
            2856 => 63357269,
            2857 => 1156756145,
            2858 => 1005038782,
            2859 => 1209504724,
            2860 => 1517231699,
            2861 => 2016310287,
            2862 => 119958527,
            2863 => 182777994,
            2864 => 1400660971,
            2865 => 642379433,
        );

        foreach($nao_pagos as $n) {
            $query_update = "UPDATE secultce_payment SET status = 4, error = 'PRÉ PROCESSAMENTO DIZIA QUE ESTAVA PAGO, MAS NÃO ESTAVA.' WHERE registration_id = $n AND installment = 1;";

            $stmt_update = $app->em->getConnection()->prepare($query_update);
            $stmt_update->execute();
        }

        echo "QUERYS EXECUTADAS COM SUCESSO!";
        exit;
    }
}

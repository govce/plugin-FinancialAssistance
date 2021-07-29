<?php

use MapasCulturais\App;
use MapasCulturais\i;

$app = App::i();
$opportunity = $this->controller->requestedEntity->id;
$route = App::i()->createUrl('paymentauxilio', 'bankData');
$registrations = $app->repo('Registration')->findByOpportunityAndUser($entity, $app->user);
//$opportunity_id = $registrations[0]->opportunity->id;
$userID = $app->user->id; //$registrations[0]->id;
$msg = $this->controller->getUrlData();

if (isset($msg['mensagem'])) {
    echo '<script>';
    echo 'alert("Dados salvos com sucesso!")';
    echo '</script>';
} else {
    echo '';
}
?>

<div class="tabs-content">
    <?php if ($registrations && $opportunity == '2852') : ?>
        <?php
        $registration_id = $registrations[0]->id;
        //CONSULTA DO RESULTADO DE AVALIAÇÃO DE INSCRICÃO
        $asp = '"';
        $sqlRegistrationData = "
                select
                    r.id as id_inscricao,
                    (
                        SELECT string_agg(REPLACE(to_json(re.evaluation_data::jsonb->'obs')::TEXT,'$asp',''), '. ')
                        FROM registration_evaluation re
                        WHERE re.result <> '10' 
                        --AND re.registration_id IN (select id from registration where opportunity_id = 2852 and status = 3)
                        AND re.registration_id = r.id
                        GROUP BY re.registration_id
                    ) as motivo,
                    r.status as status_insc,
                    case
                        when r.status = 7 then 'RECURSO PENDENTE'
                        when r.status = 10 then 'RECURSO APROVADO'
                        when r.status = 8 then 'RECURSO ESGOTADO'
                        when r.status = 3 then 'RECURSO REPROVADO'
                        when r.status = 2 then 'RECURSO REPROVADO'
                        when r.status = 1 or r.status = 0 then 'RECURSO REPROVADO'
                    end as resultado
                from 
                    public.registration as r
                where
                    r.opportunity_id =  2852
                    and r.id = $registration_id
            ";

        //$str = strval($sqlRegistrationData);
        $stmtRegistration = $app->em->getConnection()->prepare($sqlRegistrationData);
        $stmtRegistration->execute();
        $dataRegistration = $stmtRegistration->fetchAll();
        $resultado_inscricao = $dataRegistration[0]['resultado'];
        $status_inscricao = $dataRegistration[0]['status_insc'];
        $motivo_inscricao = $dataRegistration[0]['motivo'];

        //CONSULTA DE RESULTADO DO PAGAMENTO DO AUXILIO
        $sqlData = " 
                SELECT
                    CASE
                    WHEN sp1.STATUS IS NULL OR sp1.STATUS = 0 THEN 'PENDENTE'
                    WHEN sp1.STATUS = 2 AND sp1.ERROR IS NOT NULL THEN 'REENVIADO PARA PAGAMENTO: '|| to_char(sp1.SENT_DATE, 'DD-MON-YYYY HH24:MI:SS')
                    WHEN sp1.STATUS = 2 AND sp1.ERROR IS NULL THEN 'REENVIADO PARA PAGAMENTO: '|| to_char(sp1.SENT_DATE, 'DD-MON-YYYY HH24:MI:SS')
                    WHEN sp1.STATUS = 3 THEN 'APROVADO'
                    WHEN sp1.STATUS = 4 THEN 'REPROVADO'
                    ELSE
                            'PENDENTE'
                    END as resultado_pg_1,
                    sp1.ERROR as erro_pg_1,
                    sp1.INSTALLMENT as parcela_pg_1,
                    sp1.PAYMENT_DATE as data_pg_1,
                    sp1.value as valor_pg_1,
                    CASE
                        WHEN sp2.STATUS IS NULL OR sp2.STATUS = 0 THEN 'PENDENTE'
                        WHEN sp2.STATUS = 2 AND sp2.ERROR IS NOT NULL THEN 'REENVIADO PARA PAGAMENTO: '|| to_char(sp2.SENT_DATE, 'DD-MON-YYYY HH24:MI:SS')
                        WHEN sp2.STATUS = 2 AND sp2.ERROR IS NULL THEN 'REENVIADO PARA PAGAMENTO: '|| to_char(sp2.SENT_DATE, 'DD-MON-YYYY HH24:MI:SS')
                        WHEN sp2.STATUS = 3 THEN 'APROVADO'
                        WHEN sp2.STATUS = 4 THEN 'REPROVADO'
                        ELSE
                            'PENDENTE'
                    END as resultado_pg_2,
                    sp2.ERROR as erro_pg_2,
                    sp2.INSTALLMENT as parcela_pg_2,
                    sp2.PAYMENT_DATE as data_pg_2,
                    sp2.value as valor_pg_2,
                    sp2.status as status_parcela_2,
                    sp1.status as status_parcela_1
                
                FROM 
                    public.secultce_payment as sp1
                        left join public.secultce_payment as sp2
                            on sp1.registration_id =  sp2.registration_id
                            and sp2.installment = 2
                where
                    sp1.registration_id = $registration_id
                    and sp1.installment = 1
                order by 
                    sp1.INSTALLMENT
            ";
        $stmt = $app->em->getConnection()->prepare($sqlData);
        $stmt->execute();
        $data = $stmt->fetchAll();

        //CONSULTA DE DADOS BANCÁRIOS
        $sqlConsultaBancaria = "
            select distinct
                r.agents_data::jsonb->'owner'->>'nomeCompleto' as nome,
	            r.agents_data::jsonb->'owner'->>'documento' as cpf,
                rm_banco.value as banco,
                rm_agencia.value as agencia,
                rm_conta.value as conta,
                rm_tipo_conta.value as tipo_conta
            from 
                public.registration as r
                inner join public.registration_meta as rm_banco
                    on rm_banco.object_id = r.id
                    and rm_banco.key = 'field_26529'
                inner join public.registration_meta as rm_agencia
                    on rm_agencia.object_id = r.id
                    and rm_agencia.key = 'field_26530'
                inner join public.registration_meta as rm_conta
                    on rm_conta.object_id = r.id
                    and rm_conta.key = 'field_26531'
                inner join public.registration_meta as rm_tipo_conta
                    on rm_tipo_conta.object_id = r.id
                    and rm_tipo_conta.key =  'field_26528'
                left join public.registration_field_configuration rfc
                    on rfc.opportunity_id = r.opportunity_id
                    and rfc.id in (26528, 26529, 26530, 26531)
            where 
                r.opportunity_id = 2852
                and r.status = 10
                and r.id = $registration_id
                and  r.opportunity_id in (select opportunity_id from public.registration_field_configuration where id in (26528, 26529, 26530, 26531) and opportunity_id = 2852)           
        ";
        $stmtConsultaBancaria = $app->em->getConnection()->prepare($sqlConsultaBancaria);
        $stmtConsultaBancaria->execute();
        $dataConsultaBancaria = $stmtConsultaBancaria->fetchAll();

        //VALIDANDO CPF NO FILD DE CPF DO Registration META
        $sqlValidCPF = "
            select
                R.ID as inscricao_valid,
                rm.value as cpf_valid
            
            from 
                public.registration as r
                inner join public.registration_meta as rm
                    on rm.object_id = r.id
            where 
                r.opportunity_id = 2852
                and r.status = 10
                and rm.key = 'field_26519'
                and r.id = $registration_id
        ";
        $stmtValidCPF = $app->em->getConnection()->prepare($sqlValidCPF);
        $stmtValidCPF->execute();
        $dataValidCPF = $stmtValidCPF->fetchAll();

        //LISTA DE BANCOS DISPONÍVEIS                
        $listaBancos = [
            "001 - BANCO DO BRASIL",
            "104 - CAIXA ECONOMICA FEDERAL",
            "237 - BANCO BRADESCO",
            "341 - ITAÚ UNIBANCO",
            "033 - BANCO SANTANDER (BRASIL)",
            "260 - NU PAGAMENTOS",
            "323 - MERCADO PAGO",
            "290 - PAGSEGURO",
            "003 - BANCO DA AMAZONIA",
            "004 - BANCO DO NORDESTE DO BRASIL",
            "007 - BNDES",
            "010 - CREDICOAMO",
            "011 - C.SUISSE HEDGING-GRIFFO CV S/A",
            "012 - BANCO INBURSA",
            "014 - STATE STREET BR BANCO COMERCIAL",
            "015 - UBS BRASIL CCTVM",
            "016 - SICOOB CREDITRAN",
            "017 - BNY MELLON BANCO",
            "018 - BANCO TRICURY",
            "021 - BANCO BANESTES",
            "024 - BANCO BANDEPE",
            "025 - BANCO ALFA",
            "029 - BANCO ITAÚ CONSIGNADO",
            "036 - BANCO BBI",
            "037 - BANCO DO EST. DO PA",
            "040 - BANCO CARGILL",
            "041 - BANCO DO ESTADO DO RS",
            "047 - BANCO DO EST. DE SE",
            "060 - CONFIDENCE CC",
            "062 - HIPERCARD BM",
            "063 - BANCO BRADESCARD",
            "064 - GOLDMAN SACHS DO BRASIL BM S.A",
            "065 - BANCO ANDBANK",
            "066 - BANCO MORGAN STANLEY",
            "069 - BANCO CREFISA",
            "070 - BRB - BANCO DE BRASILIA",
            "074 - BCO. J.SAFRA",
            "075 - BANCO ABN AMRO",
            "076 - BANCO KDB BRASIL",
            "077 - BANCO INTER",
            "078 - HAITONG BI DO BRASIL",
            "079 - BANCO ORIGINAL DO AGRO S/A",
            "080 - B&T CC LTDA.",
            "081 - BANCOSEGURO",
            "082 - BANCO TOPÁZIO",
            "083 - BANCO DA CHINA BRASIL",
            "084 - UNIPRIME NORTE DO PARANÁ - CC",
            "085 - COOP CENTRAL AILOS",
            "088 - BANCO RANDON",
            "089 - CREDISAN CC",
            "091 - CCCM UNICRED CENTRAL RS",
            "092 - BRK CFI",
            "093 - POLOCRED SCMEPP LTDA.",
            "094 - BANCO FINAXIS",
            "095 - TRAVELEX BANCO DE CÂMBIO",
            "096 - BANCO B3",
            "097 - CREDISIS CENTRAL DE COOPERATIVAS DE CRÉDITO LTDA.",
            "098 - CREDIALIANÇA CCR",
            "099 - UNIPRIME CENTRAL CCC LTDA.",
            "100 - PLANNER CV",
            "101 - RENASCENCA DTVM LTDA",
            "102 - XP INVESTIMENTOS CCTVM S/A",
            "105 - LECCA CFI",
            "107 - BANCO BOCOM BBM",
            "108 - PORTOCRED - CFI",
            "111 - OLIVEIRA TRUST DTVM",
            "113 - MAGLIANO CCVM",
            "114 - CENTRAL COOPERATIVA DE CRÉDITO NO ESTADO DO ESPÍRITO SANTO",
            "117 - ADVANCED CC LTDA",
            "119 - BANCO WESTERN UNION",
            "120 - BANCO RODOBENS",
            "121 - BANCO AGIBANK",
            "122 - BANCO BRADESCO BERJ",
            "124 - BANCO WOORI BANK DO BRASIL",
            "125 - PLURAL BANCO BM",
            "126 - BR PARTNERS BI",
            "127 - CODEPE CVC",
            "128 - MS BANK BANCO DE CÂMBIO",
            "129 - UBS BRASIL BI",
            "130 - CARUANA SCFI",
            "131 - TULLETT PREBON BRASIL CVC LTDA",
            "132 - ICBC DO BRASIL BM",
            "133 - CRESOL CONFEDERAÇÃO",
            "134 - BGC LIQUIDEZ DTVM LTDA",
            "136 - UNICRED",
            "138 - GET MONEY CC LTDA",
            "139 - INTESA SANPAOLO BRASIL BM",
            "140 - EASYNVEST - TÍTULO CV SA",
            "142 - BROKER BRASIL CC LTDA.",
            "143 - TREVISO CC",
            "144 - BEXS BANCO DE CAMBIO",
            "145 - LEVYCAM CCV LTDA",
            "146 - GUITTA CC LTDA",
            "149 - FACTA CFI",
            "157 - ICAP DO BRASIL CTVM LTDA.",
            "159 - CASA CREDITO SCM",
            "163 - COMMERZBANK BRASIL - BANCO MÚLTIPLO",
            "169 - BANCO OLÉ CONSIGNADO",
            "173 - BRL TRUST DTVM SA",
            "174 - PERNAMBUCANAS FINANC CFI",
            "177 - GUIDE",
            "180 - CM CAPITAL MARKETS CCTVM LTDA",
            "183 - SOCRED SA - SCMEPP",
            "184 - BANCO ITAÚ BBA",
            "188 - ATIVA INVESTIMENTOS CCTVM",
            "189 - HS FINANCEIRA",
            "190 - SERVICOOP",
            "191 - NOVA FUTURA CTVM LTDA.",
            "194 - PARMETAL DTVM LTDA",
            "196 - FAIR CC",
            "197 - STONE PAGAMENTOS",
            "208 - BANCO BTG PACTUAL",
            "212 - BANCO ORIGINAL",
            "213 - BANCO ARBI",
            "217 - BANCO JOHN DEERE",
            "218 - BANCO BS2",
            "222 - BANCO CRÉDIT AGRICOLE BR",
            "224 - BANCO FIBRA",
            "233 - BANCO CIFRA",
            "241 - BANCO CLASSICO",
            "243 - BANCO MÁXIMA",
            "246 - BANCO ABC BRASIL",
            "249 - BANCO INVESTCRED UNIBANCO",
            "250 - BCV",
            "253 - BEXS CC",
            "254 - PARANA BANCO",
            "259 - MONEYCORP BANCO DE CÂMBIO",
            "265 - BANCO FATOR",
            "266 - BANCO CEDULA",
            "268 - BARI CIA HIPOTECÁRIA",
            "269 - BANCO HSBC",
            "270 - SAGITUR CC LTDA",
            "271 - IB CCTVM",
            "272 - AGK CC",
            "273 - CCR DE SÃO MIGUEL DO OESTE",
            "274 - MONEY PLUS SCMEPP LTDA",
            "276 - SENFF - CFI",
            "278 - GENIAL INVESTIMENTOS CVM",
            "279 - CCR DE PRIMAVERA DO LESTE",
            "280 - AVISTA CFI",
            "281 - CCR COOPAVEL",
            "283 - RB CAPITAL INVESTIMENTOS DTVM LTDA.",
            "285 - FRENTE CC LTDA.",
            "286 - CCR DE OURO",
            "288 - CAROL DTVM LTDA.",
            "289 - DECYSEO CC LTDA.",
            "292 - BS2 DTVM",
            "293 - LASTRO RDV DTVM LTDA",
            "296 - VISION CC",
            "298 - VIPS CC LTDA.",
            "299 - SOROCRED CFI",
            "300 - BANCO LA NACION ARGENTINA",
            "301 - BPP IP",
            "306 - PORTOPAR DTVM LTDA",
            "307 - TERRA INVESTIMENTOS DTVM",
            "309 - CAMBIONET CC LTDA",
            "310 - VORTX DTVM LTDA.",
            "313 - AMAZÔNIA CC LTDA.",
            "315 - PI DTVM",
            "318 - BANCO BMG",
            "319 - OM DTVM LTDA",
            "320 - BANCO CCB BRASIL",
            "321 - CREFAZ SCMEPP LTDA",
            "322 - CCR DE ABELARDO LUZ",
            "325 - ÓRAMA DTVM",
            "326 - PARATI - CFI",
            "329 - QI SCD",
            "330 - BANCO BARI",
            "331 - FRAM CAPITAL DTVM",
            "332 - ACESSO SOLUCOES PAGAMENTO SA",
            "335 - BANCO DIGIO",
            "336 - BANCO C6",
            "340 - SUPER PAGAMENTOS E ADMINISTRACAO DE MEIOS ELETRONICOS",
            "342 - CREDITAS SCD",
            "343 - FFA SCMEPP LTDA.",
            "348 - BANCO XP",
            "349 - AMAGGI CFI",
            "350 - CREHNOR LARANJEIRAS",
            "352 - TORO CTVM LTDA",
            "354 - NECTON INVESTIMENTOS S.A CVM",
            "355 - ÓTIMO SCD",
            "359 - ZEMA CFI S/A",
            "360 - TRINUS CAPITAL DTVM",
            "362 - CIELO",
            "363 - SOCOPA SC PAULISTA",
            "364 - GERENCIANET PAGTOS BRASIL LTDA",
            "365 - SOLIDUS CCVM",
            "366 - BANCO SOCIETE GENERALE BRASIL",
            "367 - VITREO DTVM",
            "368 - BANCO CSF",
            "370 - BANCO MIZUHO",
            "371 - WARREN CVMC LTDA",
            "373 - UP.P SEP",
            "376 - BANCO J.P. MORGAN",
            "378 - BBC LEASING",
            "379 - CECM COOPERFORTE",
            "381 - BANCO MERCEDES-BENZ",
            "382 - FIDUCIA SCMEPP LTDA",
            "383 - JUNO",
            "387 - BANCO TOYOTA DO BRASIL",
            "389 - BANCO MERCANTIL DO BRASIL",
            "390 - BANCO GM",
            "391 - CCR DE IBIAM",
            "393 - BANCO VOLKSWAGEN S.A",
            "394 - BANCO BRADESCO FINANC.",
            "396 - HUB PAGAMENTOS",
            "399 - KIRTON BANK",
            "412 - BANCO CAPITAL",
            "422 - BANCO SAFRA",
            "456 - BANCO MUFG BRASIL",
            "464 - BANCO SUMITOMO MITSUI BRASIL",
            "473 - BANCO CAIXA GERAL BRASIL",
            "477 - CITIBANK N.A.",
            "479 - BANCO ITAUBANK",
            "487 - DEUTSCHE BANKBANCO ALEMAO",
            "488 - JPMORGAN CHASE BANK",
            "492 - ING BANK N.V.",
            "495 - BANCO LA PROVINCIA B AIRES BCE",
            "505 - BANCO CREDIT SUISSE",
            "545 - SENSO CCVM",
            "600 - BANCO LUSO BRASILEIRO",
            "604 - BANCO INDUSTRIAL DO BRASIL",
            "610 - BANCO VR",
            "611 - BANCO PAULISTA",
            "612 - BANCO GUANABARA",
            "613 - OMNI BANCO",
            "623 - BANCO PAN",
            "626 - BANCO C6 CONSIG",
            "630 - SMARTBANK",
            "633 - BANCO RENDIMENTO",
            "634 - BANCO TRIANGULO",
            "637 - BANCO SOFISA",
            "643 - BANCO PINE",
            "652 - ITAÚ UNIBANCO HOLDING",
            "653 - BANCO INDUSVAL",
            "654 - BANCO DIGIMAIS",
            "655 - BANCO VOTORANTIM",
            "707 - BANCO DAYCOVAL S.A",
            "712 - BANCO OURINVEST",
            "739 - BANCO CETELEM",
            "741 - BANCO RIBEIRAO PRETO",
            "743 - BANCO SEMEAR",
            "745 - BANCO CITIBANK",
            "746 - BANCO MODAL",
            "747 - BANCO RABOBANK INTL BRASIL",
            "748 - BANCO COOPERATIVO SICREDI",
            "751 - SCOTIABANK BRASIL",
            "752 - BANCO BNP PARIBAS BRASIL S A",
            "753 - NOVO BANCO CONTINENTAL - BM",
            "754 - BANCO SISTEMA",
            "755 - BOFA MERRILL LYNCH BM",
            "756 - BANCOOB",
            "757 - BANCO KEB HANA DO BRASIL",
        ];
        //VARIAVEIS DE VALIDAÇÃO DO PERÍODO DE ALTERAÇÃO DE DADOS BANCÁRIOS
        $dataAtual = date("Y-m-d H:i:s", time());
        $dataFimInscricao = '2021-08-03 23:59:59';
        ?>
        <table class="my-registrations">
            <caption><?php \MapasCulturais\i::_e("Minhas inscrições"); ?></caption>
            <thead>
                <tr>
                    <th class="registration-id-col">
                        <?php \MapasCulturais\i::_e("Inscrição"); ?>
                    </th>
                    <th class="registration-agents-col">
                        <?php \MapasCulturais\i::_e("Agentes"); ?>
                    </th>
                    <th class="registration-status-col">
                        <?php \MapasCulturais\i::_e("Status"); ?>
                    </th>
                    <th class="registration-status-col">
                        <?php \MapasCulturais\i::_e("Resultado"); ?>
                    </th>
                    <?php if ($dataAtual <= $dataFimInscricao) : ?>
                        <?php if ($status_inscricao >= '10' && $resultado_inscricao == 'RECURSO APROVADO') : ?>
                            <th class="registration-status-col">
                                <?php \MapasCulturais\i::_e("Dados Bacários"); ?>
                            </th>
                        <?php endif; ?>
                    <?php else : ?>
                        <th class="registration-status-col">
                            <?php \MapasCulturais\i::_e("Dados Bacários"); ?>
                        </th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($registrations as $registration) :
                    $reg_args = ['registration' => $registration, 'opportunity' => $entity];
                ?>
                    <tr>
                        <?php $this->applyTemplateHook('user-registration-table--registration', 'begin', $reg_args); ?>
                        <td class="registration-id-col">
                            <?php $this->applyTemplateHook('user-registration-table--registration--number', 'begin', $reg_args); ?>
                            <a href="<?php echo $registration->singleUrl ?>"><?php echo $registration->number ?></a>
                            <?php $this->applyTemplateHook('user-registration-table--registration--number', 'end', $reg_args); ?>
                        </td>
                        <td class="registration-agents-col">
                            <?php $this->applyTemplateHook('user-registration-table--registration--agents', 'begin', $reg_args); ?>
                            <p>
                                <span class="label"><?php \MapasCulturais\i::_e("Responsável"); ?></span><br>
                                <?php echo htmlentities($registration->owner->name); ?>
                            </p>
                            <?php
                            foreach ($app->getRegisteredRegistrationAgentRelations() as $def) :
                                if (!$entity->useRegistrationAgentRelation($def))
                                    continue;
                            ?>
                                <?php if ($agents = $registration->getRelatedAgents($def->agentRelationGroupName)) : ?>
                                    <p>
                                        <span class="label"><?php echo $def->label ?></span><br>
                                        <?php echo htmlentities($agents[0]->name); ?>
                                    </p>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php $this->applyTemplateHook('user-registration-table--registration--agents', 'end', $reg_args); ?>
                        </td>
                        <td class="registration-status-col">
                            <?php $this->applyTemplateHook('user-registration-table--registration--status', 'begin', $reg_args); ?>
                            <?php if ($registration->status > 0) : ?>
                                <?php \MapasCulturais\i::_e("Enviada em"); ?> <?php echo $registration->sentTimestamp ? $registration->sentTimestamp->format(\MapasCulturais\i::__('d/m/Y à\s H:i')) : ''; ?>.
                            <?php else : ?>
                                <?php \MapasCulturais\i::_e("Não enviada."); ?><br>
                                <a class="btn btn-small btn-primary" href="<?php echo $registration->singleUrl ?>"><?php \MapasCulturais\i::_e("Editar e enviar"); ?></a>
                            <?php endif; ?>
                            <?php $this->applyTemplateHook('user-registration-table--registration--status', 'end', $reg_args); ?>
                        </td>

                        <td class="registration-status-col">
                            <?php $this->applyTemplateHook('user-registration-table--registration--status', 'begin', $reg_args); ?>
                            <br>
                            <a class="btn btn-small btn-primary" ng-click="editbox.open('report-evaluation-auxilioEventos-options', $event)" rel="noopener noreferrer">Ver Resultado</a>
                            <?php $this->applyTemplateHook('user-registration-table--registration--status', 'end', $reg_args); ?>
                        </td>
                        <?php $this->applyTemplateHook('user-registration-table--registration', 'end', $reg_args); ?>
                        <?php if ($dataAtual <= $dataFimInscricao) : ?>
                            <?php if ($status_inscricao >= '10' && $resultado_inscricao == 'RECURSO APROVADO') : ?>
                                <td class="registration-status-col">
                                    <?php $this->applyTemplateHook('user-registration-table--registration--status', 'begin', $reg_args); ?>
                                    <br>
                                    <a class="btn btn-small btn-primary" ng-click="editbox.open('report-evaluation-auxilioEventos-options-dados', $event)" rel="noopener noreferrer">Ver Dados</a>
                                    <?php $this->applyTemplateHook('user-registration-table--registration--status', 'end', $reg_args); ?>
                                </td>
                                <?php $this->applyTemplateHook('user-registration-table--registration', 'end', $reg_args); ?>
                            <?php endif; ?>
                        <?php else : ?>
                            <td class="registration-status-col">
                                <p>Período de aleração de dados bancários encerrado.</p>
                            </td>
                            <?php $this->applyTemplateHook('user-registration-table--registration', 'end', $reg_args); ?>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Formulário -->
        <edit-box id="report-evaluation-auxilioEventos-options" position="top" title="<?php i::esc_attr_e('Resultado da Inscrição') ?>" cancel-label="Ok" close-on-cancel="true">
            <form class="form-report-evaluation-auxilioEventos-options" action="<?= $route ?>" method="GET">
                <div>
                    <div>
                        <?php echo ("<b>Resultado: </b>");
                        echo ($resultado_inscricao);
                        ?>
                    </div>
                    <div>
                        <?php if ($status_inscricao < '10' && $status_inscricao != '7') : ?>
                            <div>
                                <label>
                                    <?php
                                    if ($motivo_inscricao == null || $motivo_inscricao == "") {
                                        // echo ("<p><b>Motivo: </b>");
                                        // echo ("Mensagem padrão");
                                        // echo ("</p>");
                                    } else {
                                        echo ("<p><b>Motivo: </b>");
                                        echo ($motivo_inscricao);
                                        echo ("</p>");
                                    }
                                    ?>
                                </label>
                            </div>
                        <?php else : ?>
                            <?php if ((empty($data[0]['resultado_pg_1']) == false && empty($data[0]['data_pg_1']) == false) && $status_inscricao >= '10') : ?>
                                <div>
                                    <b>Resultado do Pagamento da 1ª parcela no valor de R$ 500,00: </b>
                                </div>
                                <div>
                                    <?php echo ("<b>Situação do Pagamento: </b>");
                                    echo ($data[0]['resultado_pg_1']);
                                    ?>
                                </div>
                            <?php elseif ((empty($data[0]['resultado_pg_1']) == true && empty($data[0]['data_pg_1']) == true) && $status_inscricao >= '10') : ?>
                                <br>
                                <div>
                                    <b>Resultado do Pagamento da 1ª parcela no valor de R$ 500,00: </b>
                                </div>
                                <div>
                                    <?php echo ("<b>Situação do Pagamento: </b>");
                                    echo ("PENDENTE");
                                    ?>
                                </div>
                                <div><?php echo ('<b>Data do Pagamento: </b>');
                                        echo ("PENDENTE");
                                        ?>
                                </div>
                            <?php endif; ?>
                            <?php if ((empty($data[0]['resultado_pg_2']) == false && empty($data[0]['data_pg_2']) == false) && $status_inscricao >= '10') : ?>
                                <br>
                                <div>
                                    <b>Resultado do Pagamento da 2ª parcela no valor de R$ 500,00: </b>
                                </div>
                                <div>
                                    <?php echo ("<b>Situação do Pagamento: </b>");
                                    echo ($data[0]['resultado_pg_2']);
                                    ?>
                                </div>
                            <?php elseif (empty($data[0]['resultado_pg_2']) == true && empty($data[0]['data_pg_2']) == true && $status_inscricao >= '10') : ?>
                                <br>
                                <div>
                                    <b>Resultado do Pagamento da 2ª parcela no valor de R$ 500,00: </b>
                                </div>
                                <div>
                                    <?php echo ("<b>Situação do Pagamento: </b>");
                                    echo ("PENDENTE");
                                    ?>
                                </div>
                                <div><?php echo ('<b>Data do Pagamento: </b>');
                                        echo ("PENDENTE"); ?>
                                </div>
                            <?php endif; ?>
                            <br>
                            <div>

                                <?php if (isset($data[0]['erro_pg_1']) && (($data[0]['erro_pg_1']) != '') && (($data[0]['status_parcela_1']) == 4)) : ?>
                                    <div><b>Erro de pagamento da 1ª parcela: </b></div>
                                    <div>
                                        <label>
                                            <?php echo ("<p><b>Motivo: </b>");
                                            echo ($data[0]['erro_pg_1']);
                                            echo ("</p>");
                                            ?>
                                        </label>
                                    </div>
                                <?php endif; ?>
                                <?php if (isset($data[0]['erro_pg_2']) && (($data[0]['erro_pg_2']) != '') && (($data[0]['status_parcela_2']) == 4)) : ?>
                                    <div><b>Erro de pagamento da 2ª parcela: </b></div>
                                    <div>
                                        <label>
                                            <?php echo ("<p><b>Motivo: </b>");
                                            echo ($data[0]['erro_pg_2']);
                                            echo ("</p>");
                                            ?>
                                        </label>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </edit-box>
        <!-- BOTÃO DE EDIÇÃO E VISUALIZAÇÃO DE DADOS BANCÁRIOS -->
        <?php if ($dataAtual <= $dataFimInscricao) : ?>
            <?php if ($status_inscricao >= '10' && $resultado_inscricao == 'RECURSO APROVADO') : ?>
                <edit-box id="report-evaluation-auxilioEventos-options-dados" position="top" title="<?php i::esc_attr_e('Visualizar e Editar Dados Bancários') ?>">
                    <form class="form-report-evaluation-auxilioEventos-options-dados" action="<?= $route ?>" method="POST">
                        <!-- <label for="publishDate">Data publicação</label> -->
                        <!-- <input type="date" name="publishDate" id="publishDate"> -->
                        <?php
                        $validCPF = "";
                        if (isset($dataValidCPF[0]['cpf_valid']) && $dataValidCPF[0]['cpf_valid'] != "") {
                            $validCPF .= $dataValidCPF[0]['cpf_valid'];
                        } else {
                            $validCPF = "";
                        }
                        $nomeSelecionado = $dataConsultaBancaria[0]['nome'];
                        $cpfSelecionado = $dataConsultaBancaria[0]['cpf'];
                        $bancoSelecionado = $dataConsultaBancaria[0]['banco'];
                        $contaSelecionada = $dataConsultaBancaria[0]['conta'];
                        $agenciaSelecionada = $dataConsultaBancaria[0]['agencia'];
                        $tipoContaSelecionada = json_decode($dataConsultaBancaria[0]['tipo_conta']);
                        ?>
                        <div>
                            <input type="hidden" name="num_inscricao" value="<?php echo ($registration_id); ?>" />
                        </div>
                        <div>
                            <input type="hidden" name="validCPF" value="<?php echo ($validCPF); ?>" />
                        </div>
                        <div>
                            <label for="mail"><b>NOME COMPLETO: </b></label>
                            <input type="hiden" name="nomeCompleto" value="<?php echo ($nomeSelecionado) ?>"></input>
                        </div>
                        <div>
                            <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.15/jquery.mask.min.js">
                                var cpf = document.querySelector("#cpf");

                                cpf.addEventListener("blur", function() {
                                    if (cpf.value) cpf.value = cpf.value.match(/.{1,3}/g).join(".").replace(/\.(?=[^.]*$)/, "-");
                                });
                            </script>
                            <label for="mail"><b>CPF: </b></label>
                            <input type="hiden" id="cpf" name="cpf" onkeypress="$(this).mask('000.000.000-00');" value="<?php echo ($cpfSelecionado) ?>" maxlength="11"></input>
                        </div>
                        <div>
                            <label for="mail"><b>BANCO: </b></label>
                            <select name="bank" id="bank">
                                <?php foreach ($listaBancos as $b) : ?>
                                    <?php if ($b === $bancoSelecionado) : ?>
                                        <option value="<?php echo ($b) ?>" selected><?php echo ($b) ?></option>
                                    <?php else : ?>
                                        <option value="<?php echo ($b) ?>"><?php echo ($b) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="mail"><b>NÚMERO DA AGÊNCIA COM O DÍGITO: </b></label>
                            <input type="hiden" name="agencia" value="<?php echo ($agenciaSelecionada) ?>"></input>
                        </div>
                        <div>
                            <label for="mail"><b>NÚMERO DA CONTA COM O DÍGITO: </b></label>
                            <input type="hiden" name="conta" value="<?php echo ($contaSelecionada) ?>"></input>
                        </div>
                        <div>
                            <label for="mail"><b>TIPO DE CONTA BANCÁRIA: </b></label>
                            <select name="contaTipe" id="contaTipe">
                                <?php if ($tipoContaSelecionada[0] == 'Conta poupança') : ?>
                                    <option value='Conta poupança' selected><?php echo ($tipoContaSelecionada[0]) ?></option>
                                    <option value='Conta corrente'>Conta corrente</option>
                                <?php elseif ($tipoContaSelecionada[0] == 'Conta corrente') : ?>
                                    <option value='Conta corrente' selected><?php echo ($tipoContaSelecionada[0]) ?></option>
                                    <option value='Conta poupança'>Conta poupança</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <br>
                        <div>
                            <button class="btn btn-primary download" type="submit">Salvar Dados</button>
                            <button class="btn btn-default" ng-click="editbox.close('report-evaluation-auxilioEventos-options-dados', $event)" type="button">Cancelar</button>
                        </div>
                        <br>
                        <!-- <div style="background-color: green;">
                        <p style="color:white; text-align: center;"><b>Dados atualizados com sucesso!</b></p>
                    </div>
                    <div style="background-color: red;">
                        <p style="color:white; text-align: center;"><b>Não foi possível atualizar os dados. Por favor, contate o suporte!</b></p>
                    </div> -->
                    </form>
                </edit-box>
            <?php endif; ?>
        <?php endif; ?>
        <!-- COLUNA PADRÃO SEM BOTÕES -->
    <?php else : ?>
        <?php if ($registrations) : ?>
            <table class="my-registrations">
                <caption><?php \MapasCulturais\i::_e("Minhas inscrições"); ?></caption>
                <thead>
                    <tr>
                        <th class="registration-id-col">
                            <?php \MapasCulturais\i::_e("Inscrição"); ?>
                        </th>
                        <th class="registration-agents-col">
                            <?php \MapasCulturais\i::_e("Agentes"); ?>
                        </th>
                        <th class="registration-status-col">
                            <?php \MapasCulturais\i::_e("Status"); ?>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registrations as $registration) :
                        $reg_args = ['registration' => $registration, 'opportunity' => $entity];
                    ?>
                        <tr>
                            <?php $this->applyTemplateHook('user-registration-table--registration', 'begin', $reg_args); ?>
                            <td class="registration-id-col">
                                <?php $this->applyTemplateHook('user-registration-table--registration--number', 'begin', $reg_args); ?>
                                <a href="<?php echo $registration->singleUrl ?>"><?php echo $registration->number ?></a>
                                <?php $this->applyTemplateHook('user-registration-table--registration--number', 'end', $reg_args); ?>
                            </td>
                            <td class="registration-agents-col">
                                <?php $this->applyTemplateHook('user-registration-table--registration--agents', 'begin', $reg_args); ?>
                                <p>
                                    <span class="label"><?php \MapasCulturais\i::_e("Responsável"); ?></span><br>
                                    <?php echo htmlentities($registration->owner->name); ?>
                                </p>
                                <?php
                                foreach ($app->getRegisteredRegistrationAgentRelations() as $def) :
                                    if (!$entity->useRegistrationAgentRelation($def))
                                        continue;
                                ?>
                                    <?php if ($agents = $registration->getRelatedAgents($def->agentRelationGroupName)) : ?>
                                        <p>
                                            <span class="label"><?php echo $def->label ?></span><br>
                                            <?php echo htmlentities($agents[0]->name); ?>
                                        </p>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <?php $this->applyTemplateHook('user-registration-table--registration--agents', 'end', $reg_args); ?>
                            </td>
                            <td class="registration-status-col">
                                <?php $this->applyTemplateHook('user-registration-table--registration--status', 'begin', $reg_args); ?>
                                <?php if ($registration->status > 0) : ?>
                                    <?php \MapasCulturais\i::_e("Enviada em"); ?> <?php echo $registration->sentTimestamp ? $registration->sentTimestamp->format(\MapasCulturais\i::__('d/m/Y à\s H:i')) : ''; ?>.
                                <?php else : ?>
                                    <?php \MapasCulturais\i::_e("Não enviada."); ?><br>
                                    <a class="btn btn-small btn-primary" href="<?php echo $registration->singleUrl ?>"><?php \MapasCulturais\i::_e("Editar e enviar"); ?></a>
                                <?php endif; ?>
                                <?php $this->applyTemplateHook('user-registration-table--registration--status', 'end', $reg_args); ?>
                            </td>
                            <?php $this->applyTemplateHook('user-registration-table--registration', 'end', $reg_args); ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
</div>
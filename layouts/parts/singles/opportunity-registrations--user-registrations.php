<?php

use MapasCulturais\App;
use MapasCulturais\i;
use Psy\Util\Str;

$app = App::i();
$opportunity = $this->controller->requestedEntity->id;
$route = ''; //App::i()->createUrl('acompanhamentoauxilio', 'report', ['id' => $entity->id]);
$registrations = $app->repo('Registration')->findByOpportunityAndUser($entity, $app->user);
//$opportunity_id = $registrations[0]->opportunity->id;
$userID = $app->user->id; //$registrations[0]->id;

?>

<div class="tabs-content">
    <?php if ($registrations && $opportunity == '2852') : ?>
        <?php
        $registration_id = $registrations[0]->id;
        $asp = '""';
        $sqlRegistrationData = "
            select
                r.id as id_inscricao,
                case
                    when re.evaluation_data::jsonb->'obs' = '$asp' and re.result = '10' or re.result is null then null
                    else re.evaluation_data::json->'obs'
                end as observacao, 
                re.result as status_insc,
                case
                    when re.result = '10' then 'RECURSO APROVADO'
                    when re.result = '8' then 'RECURSO ESGOTADO'
                    when re.result = '3' then 'RECURSO REPROVADO'
                    when re.result = '2' then 'RECURSO REPROVADO'
                    when re.result is null and re.evaluation_data::jsonb->'obs' <> '$asp'  then 'RECURSO REPROVADO'
                    when re.result is null and re.evaluation_data::jsonb->'obs' = '$asp'  then 'RECURSO PENDENTE'
                    when re.result = '1' then 'RECURSO PENDENTE'
                end as resultado
            from 
                public.registration as r
                    inner join public.registration_evaluation as re
                        on re.registration_id = r.id
            where
                r.opportunity_id =  2852
                and r.id = $registration_id
        ";

        //$str = strval($sqlRegistrationData);
        $stmtRegistration = $app->em->getConnection()->prepare($sqlRegistrationData);
        $stmtRegistration->execute();
        $dataRegistration = $stmtRegistration->fetchAll();
        $json_array_motivo = [];
        $json_array_resultado = [];
        foreach ($dataRegistration as $d1) {
            $json_array_motivo[] = [
                $d1['observacao']
            ];
            $json_array_resultado[] = [
                $d1['resultado'],

            ];
        };
        $motivo = '';
        $init = 0;
        foreach ($json_array_motivo as $j) {
            for ($init = 0; $init <= 50; $init++) {
                if (isset($j[$init])) {
                    $motivo .= json_decode(nl2br($j[$init]));
                } else {
                    echo '';
                }
            }
        }
        $resultado = '';
        $init2 = 0;
        foreach ($json_array_resultado as $j) {
            for ($init2 = 0; $init2 <= 50; $init2++) {
                if (isset($j[$init2])) {
                    if ($j[$init2] == 'RECURSO REPROVADO') {
                        $resultado = 'RECURSO REPROVADO';
                    } else if ($j[$init2] == 'RECURSO APROVADO') {
                        $resultado = 'RECURSO APROVADO';
                    } else if ($j[$init2] == 'RECURSO ESGOTADO') {
                        $resultado = 'RECURSO ESGOTADO';
                    } else if ($j[$init2] == 'RECURSO PENDENTE') {
                        $resultado = 'RECURSO PENDENTE';
                    } else {
                        echo '';
                    }
                } else {
                    echo '';
                }
            }
        }
        $sqlData = " 
            SELECT
                CASE
                    WHEN  RETURN_FILE_ID = 3 AND STATUS = 1 THEN 'Pagamento Aprovado!'
                    WHEN  RETURN_FILE_ID = 4 AND STATUS = 0 THEN 'Pagamento Reprovado!'
                    WHEN  RETURN_FILE_ID != 3 OR RETURN_FILE_ID != 4 OR STATUS != 1 OR STATUS != 2 OR  RETURN_FILE_ID IS NULL OR STATUS IS NULL THEN 'Pagamento Pendente.'
                END as resultado,
                ERROR  as erro,
                CASE
                    WHEN INSTALLMENT = 1 AND STATUS = 1 THEN CONCAT(PAYMENT_DATE,' - ', 'Pagamento Efetuado!') 
                    WHEN INSTALLMENT = 1 AND STATUS = 0 THEN 'Pagamento Não Efetuado.'
                END AS data_pagamento_1,
                CASE
                    WHEN INSTALLMENT = 2 AND STATUS = 1 THEN CONCAT(PAYMENT_DATE,' - ', 'Pagamento Efetuado!') 
                    WHEN INSTALLMENT = 2 AND STATUS = 0 THEN 'Pagamento Não Efetuado.'
                END AS data_pagamento_2
            from 
                public.secultce_payment
                where
                registration_id = $registration_id
            ";
        $stmt = $app->em->getConnection()->prepare($sqlData);
        $stmt->execute();
        $data = $stmt->fetchAll();
        $json_array = [];
        foreach ($data as $d) {
            //$json_array[] = [$d];
            $json_array[] = [
                'resultado' => $d['resultado'],
                'data_pagamento_1' => $d['data_pagamento_1'],
                'data_pagamento_2' => $d['data_pagamento_2'],
                'erro' => $d['erro']
            ];
        }

        if ($data == null or isset($data) or  $json_array == null or isset($json_array)) {
            $motivoPagamento = 'Pagamento não iniciado.';
        } else {
            $resultado1 = $json_array[0]['resultado'];
            $resultado2 = $json_array[1]['resultado'];
            $data_pagamento_1 = $json_array[0]['data_pagamento_1'];
            $data_pagamento_2 = $json_array[1]['data_pagamento_2'];
            $erro_1 = $json_array[0]['erro'];
            $erro_2 = $json_array[1]['erro'];
        }
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
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <!-- Formulário -->
        <edit-box id="report-evaluation-auxilioEventos-options" position="top" title="<?php i::esc_attr_e('Resultado da Inscrição') ?>" cancel-label="Ok" close-on-cancel="true">
            <form class="form-report-evaluation-auxilioEventos-options" action="<?= $route ?>" method="GET">
                <!-- <label for="publishDate">Data publicação</label> -->
                <!-- <input type="date" name="publishDate" id="publishDate"> -->
                <div>
                    <label for="mail"><b>Resultado da Avaliação Técnia: </b></label>
                    <label for="mail">
                        <?php echo ($resultado) ?>
                    </label>
                </div>
                <div>
                    <?php if ($resultado == 'RECURSO REPROVADO') : ?>
                        <div><b>Motivo: </b></div>
                        <div>
                            <?php echo ($motivo) ?>
                        </div>
                    <?php elseif ($resultado == 'RECURSO ESGOTADO') : ?>
                        <div><b>Motivo: </b></div>
                        <div>
                            <?php echo ($motivo) ?>
                        </div>
                    <?php elseif ($resultado == 'RECURSO PENDENTE') : ?>
                        <div><b>Motivo: </b></div>
                        <div>
                            <?php echo ($motivo) ?>
                        </div>
                    <?php elseif ($resultado == 'RECURSO APROVADO') : ?>
                        <?php if (($data == null or isset($data) == null) && $resultado == 'RECURSO REPROVADO') : ?>
                            <div>
                                <?php echo '' ?>
                            </div>
                        <?php elseif (($data == null or isset($data) == null) && $resultado == 'RECURSO APROVADO') : ?>
                            <div>
                                <?php echo '' ?>
                            </div>
                        <?php elseif (($data == null or isset($data) == null) && $resultado == 'RECURSO ESGOTADO') : ?>
                            <div>
                                <?php echo '' ?>
                            </div>
                        <?php elseif (($data == null or isset($data) == null) && $resultado == 'RECURSO PENDENTE') : ?>
                            <div>
                                <?php echo '' ?>
                            </div>
                        <?php elseif ($resultado == 'RECURSO APROVADO' && $data != null) : ?>
                            <br>
                            <div>
                                <label for="mail"><b>Resultado da 1ª parcela: </b></label>
                                <label for="mail">
                                    <?php
                                    echo ($json_array[0]['resultado'])
                                    ?>
                                </label>
                            </div>
                            <div><b>Pagamento da 1ª parcela no valor de R$ 500,00: </b></div>
                            <div><?php echo ('<b>Data e hora: </b>');
                                    echo ($json_array[0]['data_pagamento_1']); ?></div>
                            <br>
                            <div>
                                <label for="mail"><b>Resultado da 2ª parcela: </b></label>
                                <label for="mail">
                                    <?php
                                    echo ($json_array[0]['resultado'])
                                    ?>

                                </label>
                            </div>
                            <div><b>Pagamento da 2ª parcela no valor de R$ 500,00: </b></div>
                            <div> <?php echo ('<b>Data e hora: </b>');
                                    echo ($json_array[1]['data_pagamento_2']); ?></div>
                            <br>
                            <div>
                                <?php if (isset($json_array[0]['erro'])) : ?>
                                    <div><b>Error de pagamento da 1ª parcela: </b></div>
                                    <div>
                                        <?php echo ('<b>Motivo: </b>');
                                        echo ($json_array[0]['erro']) ?>
                                    </div>
                                <?php elseif (isset($json_array[1]['erro'])) : ?>
                                    <div><b>Error de pagamento da 2ª parcela: </b></div>
                                    <div>
                                        <?php echo ('<b>Motivo: </b>');
                                        echo ($json_array[1]['erro']) ?>
                                    </div>
                                <?php else : ?>
                                    <div></div>
                                <?php endif; ?>
                            </div>
                        <?php else : ?>
                            <div></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </form>
        </edit-box>
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
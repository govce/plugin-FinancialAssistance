<?php

use MapasCulturais\App;
use MapasCulturais\i;

$app = App::i();
$route = ''; //App::i()->createUrl('acompanhamentoauxilio', 'report', ['id' => $entity->id]);
$registrations = $app->repo('Registration')->findByOpportunityAndUser($entity, $app->user);

$registration_id = $registrations[0]->id;

$sqlData = " 
    SELECT *,
        CASE
            WHEN  RETURN_FILE_ID = 3 AND STATUS = 1 THEN 'Pagamento Aprovado!'
            WHEN  RETURN_FILE_ID = 4 AND STATUS = 0 THEN 'Pagamento Reprovado!'
            WHEN  RETURN_FILE_ID != 3 OR RETURN_FILE_ID != 4 OR STATUS != 1 OR STATUS != 2 OR  RETURN_FILE_ID IS NULL OR STATUS IS NULL THEN 'Pagamento Pendente.'
        END as resultado,
        ERROR  as erro,
        CASE
            WHEN INSTALLMENT = 1 AND STATUS = 1 THEN 'Pagamento Efetuado!'
            WHEN INSTALLMENT = 1 AND STATUS = 0 THEN 'Pagamento Não Efetuado.'
        END AS pagamento_1,
        CASE
            WHEN INSTALLMENT = 2 AND STATUS = 1 THEN 'Pagamento Efetuado!'
            WHEN INSTALLMENT = 2 AND STATUS = 0 THEN 'Pagamento Não Efetuado.'
        END AS pagamento_2,
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
        'return_file_id' => $d['return_file_id']
    ];
}
$resultado1 = $json_array[0]['resultado'];
$resultado2 = $json_array[1]['resultado'];
$data_pagamento_1 = $json_array[0]['data_pagamento_1'];
$data_pagamento_2 = $json_array[1]['data_pagamento_2'];
$erro1 = 'asdasdasdasdasdasd';
$erro2  = '';
//var_dump($json_array);

//die();
?>

<div class="tabs-content">
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
    <?php endif; ?>
</div>

<!-- Formulário -->
<edit-box id="report-evaluation-auxilioEventos-options" position="top" title="<?php i::esc_attr_e('Resultado da Inscrição') ?>" cancel-label="Ok" close-on-cancel="true">
    <form class="form-report-evaluation-auxilioEventos-options" action="<?= $route ?>" method="GET">
        <!-- <label for="publishDate">Data publicação</label> -->
        <!-- <input type="date" name="publishDate" id="publishDate"> -->
        <div>
            <label for="mail"><b>Resultado da 1ª parcela: </b></label>
            <label for="mail">
                <?php echo ($resultado1) ?>
            </label>
        </div>
        <div><b>Pagamento da 1ª parcela no valor de R$ 500,00: </b></div>
        <div><?php echo ('<b>Data e hora: </b>');
                echo ($data_pagamento_1); ?></div>
        <br>
        <div>
            <label for="mail"><b>Resultado da 2ª parcela: </b></label>
            <label for="mail">
                <?php echo ('<b>Data e hora: </b>');
                echo ($data_pagamento_2); ?>
            </label>
        </div>
        <div><b>Pagamento da 2ª parcela no valor de R$ 500,00: </b></div>
        <div><?php echo ($resultado2) ?></div>
        <br>
        <div>
            <?php
            if (($json_array[0]['return_file_id']) == 4) {
                echo ('<div><b>Error de pagamento da 1ª parcela: </b></div>');
                echo ('<div>');
                echo ($erro1);
                echo ('</div>');
            } else if (($json_array[0]['return_file_id']) == 4) {
                echo ('<div><b>Error de pagamento da 2ª parcela: </b></div>');
                echo ('<div>');
                echo ($erro1);
                echo ('</div>');
            } else {
                echo ('');
            }
            ?>
        </div>
    </form>
</edit-box>
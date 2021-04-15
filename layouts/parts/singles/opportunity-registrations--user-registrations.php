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
        // $sqlData = " 
        //     SELECT
        //         CASE
        //             WHEN STATUS IS NULL OR STATUS < 3 THEN 'Pagamento Pendente'
        //             WHEN STATUS = 3 THEN 'Pagamento Aprovado'
        //             WHEN STATUS = 4 THEN 'Pagamento Reprovado'
        //             ELSE
        //                 'Pagamento Pendente'
        //         END as resultado,
        //         ERROR as erro,
        //         INSTALLMENT as parcela,
        //         PAYMENT_DATE as dt_pagamento,
        //         value as valor_pagamento
        //     FROM 
        //         public.secultce_payment
        //         where
        //         registration_id = $registration_id
        //     order by INSTALLMENT
        //     ";
        // $stmt = $app->em->getConnection()->prepare($sqlData);
        // $stmt->execute();
        // $data = $stmt->fetchAll();
        // function parcela($indice_parcela, $data)
        // {
        //     return array(
        //         'resultado_parcela' => $data[$indice_parcela]['resultado'],
        //         'valor_parcela' => $data[$indice_parcela]['valor_pagamento'],
        //         'numero_parcela' => $data[$indice_parcela]['parcela'],
        //         'data_pagamento' => $data[$indice_parcela]['dt_pagamento'],
        //         'error_pagamento' => $data[$indice_parcela]['erro']
        //     );
        // };
        // $list[] = parcela(0, $data);
        // var_dump(parcela(0, $data));
        // die();
        // $valor_parcela = 
        // $resultado_parcela = $data[0]['resultado'];
        // $data_pg_parcela_1 = 

        // $json_array = [];
        // foreach ($data as $d) {
        //     //$json_array[] = [$d];
        //     $json_array[] = [
        //         'resultado' => $d['resultado'],
        //         'data_pagamento_1' => $d['data_pagamento_1'],
        //         'data_pagamento_2' => $d['data_pagamento_2'],
        //         'erro' => $d['erro']
        //     ];
        // }

        // if ($data == null or isset($data) or  $json_array == null or isset($json_array)) {
        //     $motivoPagamento = 'Pagamento não iniciado.';
        // } else {
        //     $resultado1 = $json_array[0]['resultado'];
        //     $resultado2 = $json_array[1]['resultado'];
        //     $data_pagamento_1 = $json_array[0]['data_pagamento_1'];
        //     $data_pagamento_2 = $json_array[1]['data_pagamento_2'];
        //     $erro_1 = $json_array[0]['erro'];
        //     $erro_2 = $json_array[1]['erro'];
        // }
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
                    <div>
                        <label><b>Resultado da Avaliação Técnia: </b></label>
                        <label>
                            <?php echo ("<p>");
                            echo ($resultado_inscricao);
                            echo ("</p>");
                            ?>
                        </label>
                    </div>
                    <div>
                        <?php if ($status_inscricao < '10' && ($motivo_inscricao == null || $motivo_inscricao == "")) : ?>
                            <div>
                                <label><b>Motivo: </b></label>
                                <label>
                                    <?php echo ("<p>");
                                    echo ("mensagem de motivo padrão");
                                    echo ("</p>");
                                    ?>
                                </label>
                            </div>
                        <?php elseif ($status_inscricao < '10' && ($motivo_inscricao != null || $motivo_inscricao != "")) : ?>
                            <div>
                                <label><b>Motivo: </b></label>
                                <label>
                                    <?php echo ("<p>");
                                    echo ($motivo_inscricao);
                                    echo ("</p>");
                                    ?>
                                </label>
                            </div>
                        <?php else : ?>
                            <?php if (true) : ?>
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
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
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
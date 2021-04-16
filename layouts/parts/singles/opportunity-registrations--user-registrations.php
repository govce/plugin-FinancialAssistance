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
        $sqlData = " 
                SELECT
                    CASE
                        WHEN sp1.STATUS IS NULL OR sp1.STATUS < 3 THEN 'PENDENTE'
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
                        WHEN sp2.STATUS IS NULL OR sp2.STATUS < 3 THEN 'PENDENTE'
                        WHEN sp2.STATUS = 3 THEN 'APROVADO'
                        WHEN sp2.STATUS = 4 THEN 'REPROVADO'
                        ELSE
                            'PENDENTE'
                    END as resultado_pg_2,
                    sp2.ERROR as erro_pg_2,
                    sp2.INSTALLMENT as parcela_pg_2,
                    sp2.PAYMENT_DATE as data_pg_2,
                    sp2.value as valor_pg_2
                
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
                <div>
                    <div>
                        <?php echo ("<b>Resultado: </b>");
                        echo ($resultado_inscricao);
                        ?>
                    </div>
                    <div>
                        <?php if ($status_inscricao < '10') : ?>
                            <div>
                                <label>
                                    <?php
                                    if ($motivo_inscricao == null || $motivo_inscricao == "") {
                                        echo ("<p><b>Motivo: </b>");
                                        echo ("Mensagem padrão");
                                        echo ("</p>");
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
                                    <?php echo ("<b>Status do Pagamento: </b>");
                                    echo ($data[0]['resultado_pg_1']);
                                    ?>
                                </div>
                                <div><?php echo ('<b>Data e Hora do Pagamento: </b>');
                                        echo ($data[0]['data_pg_1']); ?>
                                </div>
                            <?php elseif ((empty($data[0]['resultado_pg_1']) == true && empty($data[0]['data_pg_1']) == true) && $status_inscricao >= '10') : ?>
                                <br>
                                <div>
                                    <b>Resultado do Pagamento da 1ª parcela no valor de R$ 500,00: </b>
                                </div>
                                <div>
                                    <?php echo ("<b>Status do Pagamento: </b>");
                                    echo ("PENDENTE");
                                    ?>
                                </div>
                                <div><?php echo ('<b>Data e Hora do Pagamento: </b>');
                                        echo ("PENDENTE"); ?>
                                </div>
                            <?php endif; ?>
                            <?php if ((empty($data[0]['resultado_pg_2']) == false && empty($data[0]['data_pg_2']) == false) && $status_inscricao >= '10') : ?>
                                <br>
                                <div>
                                    <b>Resultado do Pagamento da 2ª parcela no valor de R$ 500,00: </b>
                                </div>
                                <div>
                                    <?php echo ("<b>Status do Pagamento: </b>");
                                    echo ($data[0]['resultado_pg_2']);
                                    ?>
                                </div>
                                <div><?php echo ('<b>Data e Hora do Pagamento: </b>');
                                        echo ($data[0]['data_pg_2']); ?>
                                </div>
                            <?php elseif (empty($data[0]['resultado_pg_2']) == true && empty($data[0]['data_pg_2']) == true && $status_inscricao >= '10') : ?>
                                <br>
                                <div>
                                    <b>Resultado do Pagamento da 2ª parcela no valor de R$ 500,00: </b>
                                </div>
                                <div>
                                    <?php echo ("<b>Status do Pagamento: </b>");
                                    echo ("PENDENTE");
                                    ?>
                                </div>
                                <div><?php echo ('<b>Data e Hora do Pagamento: </b>');
                                        echo ("PENDENTE"); ?>
                                </div>
                            <?php endif; ?>
                            <br>
                            <div>
                                <?php if (isset($data[0]['erro_pg_1'])) : ?>
                                    <div><b>Error de pagamento da 1ª parcela: </b></div>
                                    <div>
                                        <label>
                                            <?php echo ("<p><b>Motivo: </b>");
                                            echo ($data[0]['erro_pg_1']);
                                            echo ("</p>");
                                            ?>
                                        </label>
                                    </div>
                                <?php elseif (isset($data[0]['erro_pg_2'])) : ?>
                                    <div><b>Error de pagamento da 2ª parcela: </b></div>
                                    <div>
                                        <label>
                                            <?php echo ("<p><b>Motivo: </b>");
                                            echo ($data[0]['erro_pg_2']);
                                            echo ("</p>");
                                            ?>
                                        </label>
                                    </div>
                                <?php else : ?>
                                    <div></div>
                                <?php endif; ?>
                            </div>
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

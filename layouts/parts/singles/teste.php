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
    <!-------------- STATUS DE ACOMPANHAMENTO DE PAGAMENTO POR INSCRIÇÃO ------------------>
    <?php if ($registrations && $opportunity == '2852') : ?>
        <table class="my-registrations">
            <caption><?php \MapasCulturais\i::_e("Minhas inscrições"); ?></caption>
            <thead>
                <tr>
                    <th class="registration-status-col">
                        <?php \MapasCulturais\i::_e("Dados Bacários"); ?>
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($registrations as $registration) :
                    $reg_args = ['registration' => $registration, 'opportunity' => $entity];
                ?>
                    <tr>
                        <?php $this->applyTemplateHook('user-registration-table--registration', 'begin', $reg_args); ?>
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
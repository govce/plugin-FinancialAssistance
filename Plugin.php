<?php

namespace RegistrationPaymentsAuxilio;

require_once __DIR__ . "/vendor/autoload.php";

use MapasCulturais\App;
use MapasCulturais\i;

class Plugin extends \MapasCulturais\Plugin
{
  public function __construct(array $config = [])
  {

    $config += [
      'config-cnab240' => require_once __DIR__ . '/config/config-cnab240.php'
    ];

    parent::__construct($config);
  }

  function _init()
  {
    $app = App::i();

    $plugin = $this;


    $app->hook('template(opportunity.single.header-inscritos):end', function () use ($plugin, $app) {
      $opportunity = $this->controller->requestedEntity;

      if ($opportunity->id == $plugin->config['opportunity_id']) {
        $this->part('auxilio/opportunity-button-auxilio', ['opportunity' => $opportunity]);
      }
    });


    // //BOTÃO DE VERIFICAÇÃO DE INSCRIÇÃO   
    // /*$app->hook('template(opportunity.single.main-content):begin', function () use ($app) {
    //   $opportunityId = $this->controller->requestedEntity->id;
    //   $entity = $this->controller->requestedEntity;
    //   if ($opportunityId == '1544') {
    //     $this->part('singles/opportunity-registrations--user-registrations', ['entity' => $entity]);
    //     //$this->part('acompanhamento-edital/opportunity-button-acompanhamento-edital', ['entity' => $entity]); opportunity-registrations--user-registrations
    //   }
    // });*/
  }


  function register()
  {
    $app = App::i();

    $app->registerController('paymentauxilio', 'MapasCulturais\Controllers\Auxilio');
  }
}

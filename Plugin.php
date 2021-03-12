<?php 

namespace RegistrationPaymentsAuxilio;

require_once __DIR__ . "/vendor/autoload.php";

use MapasCulturais\App;
use MapasCulturais\i;

class Plugin extends \MapasCulturais\Plugin {
  public function __construct(array $config = []) {

      $config += [
        'config-cnab240' => require_once __DIR__ . '/config/config-cnab240.php'
      ];

      parent::__construct($config);
  }

  function _init() {
    $app = App::i();

    $plugin = $this;

    $app->hook('template(opportunity.single.header-inscritos):end', function () use($plugin, $app) {
      $opportunity = $this->controller->requestedEntity;
      
      if ($opportunity->id == $plugin->config['opportunity_id']) {
        $this->part('auxilio/opportunity-button-auxilio', [ 'opportunity' => $opportunity ]);
      }
    });
  }


  function register() {
    $app = App::i();

    $app->registerController('paymentauxilio', 'MapasCulturais\Controllers\Auxilio');
  }
}
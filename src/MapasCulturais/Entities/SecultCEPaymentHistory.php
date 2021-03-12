<?php 

namespace MapasCulturais\Entities;

use Doctrine\ORM\Mapping as ORM;
use MapasCulturais\Traits;
use MapasCulturais\App;

/**
 * @ORM\Table(name="secultce_payment_history")
 * @ORM\Entity
 * @ORM\entity(repositoryClass="MapasCulturais\Repository")
 * @ORM\HasLifecycleCallbacks
*/
class SecultCEPaymentHistory extends \MapasCulturais\Entity {
  /**
   * @var integer
   *
   * @ORM\Column(name="id", type="integer", nullable=false)
   * @ORM\Id
   * @ORM\GeneratedValue(strategy="SEQUENCE")
   * @ORM\SequenceGenerator(sequenceName="secultce_payment_history_id_seq", allocationSize=1, initialValue=1)
  */
  protected $id;

  /**
   * @var \MapasCulturais\Entities\SecultCEPayment
   *
   * @ORM\ManyToOne(targetEntity="MapasCulturais\Entities\SecultCEPayment")
   * @ORM\JoinColumns({
   *   @ORM\JoinColumn(name="payment_id", referencedColumnName="id", nullable=false)
   * })
  */
  protected $payment_id;

  /**
   * @var \MapasCulturais\Entities\File
   *
   * @ORM\ManyToOne(targetEntity="MapasCulturais\Entities\File")
   * @ORM\JoinColumns({
   *   @ORM\JoinColumn(name="file_id", referencedColumnName="id", nullable=false)
   * })
  */
  protected $file_id;

  /**
   * @var string
   *
   * @ORM\Column(name="action", type="string", length=255, nullable=false)
  */
  protected $action;

  /**
   * @var string
   *
   * @ORM\Column(name="result", type="text", nullable=false)
  */
  protected $result;

  /**
   * @var \DateTime
   *
   * @ORM\Column(name="file_date", type="datetime", nullable=false)
  */
  protected $fileDate;

  /**
   * @var \DateTime
   *
   * @ORM\Column(name="payment_date", type="datetime", nullable=false)
  */
  protected $paymentDate;
}
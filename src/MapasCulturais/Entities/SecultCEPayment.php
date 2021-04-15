<?php

namespace MapasCulturais\Entities;

use Doctrine\ORM\Mapping as ORM;
use MapasCulturais\Traits;
use MapasCulturais\App;


/**
 * @ORM\Table(name="secultce_payment")
 * @ORM\Entity
 * @ORM\entity(repositoryClass="MapasCulturais\Repository")
 * @ORM\HasLifecycleCallbacks
 */
class SecultCEPayment extends \MapasCulturais\Entity
{

  /**
   * @var integer
   *
   * @ORM\Column(name="id", type="integer", nullable=false)
   * @ORM\Id
   * @ORM\GeneratedValue(strategy="SEQUENCE")
   * @ORM\SequenceGenerator(sequenceName="secultce_payment_id_seq", allocationSize=1, initialValue=1)
   */
  protected $id;

  /**
   * @var \MapasCulturais\Entities\Registration
   *
   * @ORM\ManyToOne(targetEntity="MapasCulturais\Entities\Registration", fetch="LAZY")
   * @ORM\JoinColumns({
   *   @ORM\JoinColumn(name="registration_id", referencedColumnName="id", onDelete="CASCADE")
   * })
   */
  protected $registration;

  /**
   * @var integer
   *
   * @ORM\Column(name="installment", type="smallint", nullable=true)
   */
  protected $installment;

  /**
   * @var string
   *
   * @ORM\Column(name="value", type="integer", nullable=true)
   */
  protected $value;

  /**
   * @var string
   *
   * @ORM\Column(name="status", type="integer", nullable=true)
   */
  protected $status;

  /**
   * @var \DateTime
   *
   * @ORM\Column(name="payment_date", type="datetime", nullable=true)
   */
  protected $paymentDate;

  /**
   * @var \DateTime
   *
   * @ORM\Column(name="generate_file_date", type="datetime", nullable=true)
   */
  protected $generateFileDate;

  /**
   * @var \DateTime
   *
   * @ORM\Column(name="sent_date", type="datetime", nullable=true)
   */
  protected $sentDate;

  /**
   * @var \DateTime
   *
   * @ORM\Column(name="return_date", type="datetime", nullable=true)
   */
  protected $returnDate;

  /**
   * @var \MapasCulturais\Entities\File
   *
   * @ORM\ManyToOne(targetEntity="MapasCulturais\Entities\File")
   * @ORM\JoinColumns({
   *   @ORM\JoinColumn(name="payment_file_id", referencedColumnName="id", nullable=false)
   * })
   */
  protected $paymentFile;

  /**
   * @var \MapasCulturais\Entities\File
   *
   * @ORM\ManyToOne(targetEntity="MapasCulturais\Entities\File")
   * @ORM\JoinColumns({
   *   @ORM\JoinColumn(name="return_file_id", referencedColumnName="id", nullable=false)
   * })
   */
  protected $returnFile;

  /**
   * @var string
   *
   * @ORM\Column(name="error", type="text", nullable=true)
   */
  protected $error;


  function setRegistration($registration)
  {
    $this->registration = $registration;
  }
}

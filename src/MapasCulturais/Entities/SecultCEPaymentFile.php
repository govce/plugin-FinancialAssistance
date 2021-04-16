<?php 

namespace MapasCulturais\Entities;

use Doctrine\ORM\Mapping as ORM;
use MapasCulturais\Entities\File;

/**
 * @ORM\Entity
 * @ORM\entity(repositoryClass="MapasCulturais\Repository")
 */
class SecultCEPaymentFile extends File {
    
    /**
     * @var \MapasCulturais\Entities\SecultCEPayment
     *
     * @ORM\ManyToOne(targetEntity="MapasCulturais\Entities\SecultCEPayment")
     * @ORM\JoinColumn(name="object_id", referencedColumnName="id", onDelete="CASCADE")
     * })
     */
    protected $owner;

    /**
     * @var \MapasCulturais\Entities\SecultCEPayment
     *
     * @ORM\ManyToOne(targetEntity="MapasCulturais\Entities\SecultCEPayment", fetch="EAGER")
     * @ORM\JoinColumns({
     *   @ORM\JoinColumn(name="parent_id", referencedColumnName="id", onDelete="CASCADE")
     * })
     */
    protected $parent;
} 
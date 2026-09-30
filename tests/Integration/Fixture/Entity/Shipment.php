<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Fixture\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Nubit\ApiPlatform\Attribute\RowScoped;
use Nubit\WorkflowBundle\Attribute\Workflow;

/**
 * A resource with a workflow AND a row scope: the combination the transition
 * route has to get right, because it loads its entity outside API Platform.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_shipment')]
#[ApiResource(operations: [new GetCollection(), new Get()])]
#[RowScoped(field: 'warehouse', claim: 'warehouses')]
#[Workflow(
    field: 'status',
    transitions: [
        'dispatch' => ['from' => ['packed'], 'to' => 'dispatched', 'set' => ['carrier' => 'DHL']],
        'audit' => ['from' => ['packed', 'dispatched'], 'to' => 'audited', 'roles' => ['ROLE_AUDITOR']],
    ],
)]
class Shipment implements FixtureEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60)]
    public string $reference = '';

    #[ORM\Column]
    public int $warehouse = 0;

    #[ORM\Column(length: 20)]
    public string $status = 'packed';

    #[ORM\Column(length: 20, nullable: true)]
    public ?string $carrier = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}

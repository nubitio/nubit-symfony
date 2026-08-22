<?php

declare(strict_types=1);

namespace Nubit\Tests\Integration\Fixture\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\RangeFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Nubit\ApiPlatform\Attribute\GridScale;
use Nubit\ApiPlatform\Doctrine\Filter\DataGridFilter;

/**
 * A resource that expects to get large: cursor-paginated, no exact total.
 *
 * The combination is the realistic one for an ERP's movements table — offsets
 * degrade as the table grows and nobody reads the footer as a precise number
 * once it passes six digits.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_ledger_entry')]
#[ApiResource(
    operations: [new GetCollection()],
    // The order has to be declared on the resource, not left to the client: the
    // next link carries only the range parameter, so without a deterministic
    // order the cursor walks rows the database returned in whatever sequence it
    // liked.
    order: ['id' => 'DESC'],
    paginationPartial: true,
    paginationViaCursor: [['field' => 'id', 'direction' => 'DESC']],
)]
#[ApiFilter(DataGridFilter::class)]
// Cursor pagination is these two filters plus the declaration; without them the
// "?id[lt]=…" the next link carries is ignored and every page repeats.
#[ApiFilter(RangeFilter::class, properties: ['id'])]
#[ApiFilter(OrderFilter::class, properties: ['id' => 'DESC'])]
#[GridScale(cursorField: 'id', cursorDirection: 'DESC', exactCount: false, inlineExportLimit: 3)]
class LedgerEntry implements FixtureEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60)]
    public string $reference = '';

    #[ORM\Column(length: 60)]
    public string $account = '';

    public function getId(): ?int
    {
        return $this->id;
    }
}

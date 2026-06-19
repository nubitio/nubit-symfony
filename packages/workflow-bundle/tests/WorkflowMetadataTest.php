<?php

declare(strict_types=1);

namespace Nubit\WorkflowBundle\Tests;

use Nubit\WorkflowBundle\Attribute\Workflow;
use Nubit\WorkflowBundle\Workflow\WorkflowMetadata;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowMetadataTest extends TestCase
{
    #[Test]
    public function it_reads_workflow_attribute_from_entity(): void
    {
        $metadata = new WorkflowMetadata();
        $workflow = $metadata->read(WorkflowEntity::class);

        self::assertInstanceOf(Workflow::class, $workflow);
        self::assertSame('status', $workflow->field);
        self::assertArrayHasKey('approve', $workflow->transitions);
    }

    #[Test]
    public function it_builds_transition_definitions(): void
    {
        $metadata = new WorkflowMetadata();
        $transitions = $metadata->buildTransitions([
            'approve' => ['from' => ['draft'], 'to' => 'approved', 'label' => 'Approve'],
        ]);

        self::assertCount(1, $transitions);
        self::assertSame('approve', $transitions[0]->name);
        self::assertSame(['draft'], $transitions[0]->from);
        self::assertSame('approved', $transitions[0]->to);
        self::assertSame('Approve', $transitions[0]->label);
    }
}

#[Workflow(
    field: 'status',
    transitions: [
        'approve' => ['from' => ['draft'], 'to' => 'approved'],
    ],
)]
final class WorkflowEntity
{
}